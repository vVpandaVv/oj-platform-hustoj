<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionAnswerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionAssemblyResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionTemplateResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRuntimeContext;
use CodingPanda\OjPlatform\Kernel\Addon\RealAddonCodeCompletionGateway;
use CodingPanda\OjPlatform\Kernel\Addon\UnavailableAddonRuntimeServiceFactory;
use CodingPanda\OjPlatform\Modules\Problems\Application\CodeCompletionTemplatePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Application\CodeCompletionSourceMode;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionLanguageCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceRequest;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) { throw new RuntimeException($message); }
    ++$checks;
};
$throws = static function (callable $call) use ($check): void {
    try { $call(); } catch (InvalidArgumentException) { $check(true, 'invalid'); return; }
    $check(false, 'Malformed completion DTO input must be rejected.');
};

// Real learning-check authorization is separately covered by its contract test.
// This fixture verifies exact delegation and reauthorization before policy reads.
$access = new class implements AddonLearningCheckGatewayV1 {
    public ?int $currentActor = 7;
    public bool $allowed = true;
    public bool $contestAllowed = false;
    public bool $throws = false;
    public array $queries = [];
    public function context(AddonLearningCheckContextQueryV1 $query): AddonLearningCheckContextResultV1 {
        $this->queries[] = $query;
        if ($this->throws) { throw new RuntimeException('SECRET access failure'); }
        $contextAllowed = match ($query->contextType()) {
            'general' => true,
            'class' => $query->contextId() === 5,
            'group' => $query->contextId() === 6,
            'contest' => $query->contextId() === 8 && $this->contestAllowed,
        };
        if (! $this->allowed || $this->currentActor !== $query->actorUserId()
            || $query->problemId() !== 101 || ! $contextAllowed) {
            return new AddonLearningCheckContextResultV1(false, [], new AddonApiProblemV1(
                'addon_access_denied', 'Denied', 'completion:denied', false));
        }
        return new AddonLearningCheckContextResultV1(true, [
            'problem' => ['id' => 101, 'title' => '빈칸 연습', 'description' => '공개 문제',
                'input' => '', 'output' => '', 'sample_input' => '', 'sample_output' => '',
                'content_hash' => str_repeat('a', 64)],
            'submission' => ['id' => 1, 'problem_id' => 101, 'source' => 'SECRET own source',
                'language_id' => 6, 'language' => 'python', 'result_code' => 4,
                'submitted_at' => '', 'processing' => false],
        ], null);
    }
};
$policies = new class implements SubmissionProblemProvider {
    public string $starter = "n = {{OJ_BLANK:value}}\r\n{{OJ_BLANK:body}}\r\n";
    public string $language = 'python';
    public array $blanks = [
        ['id' => 'value', 'label' => '초기값', 'kind' => 'inline', 'max_bytes' => 4],
        ['id' => 'body', 'label' => '실행 코드', 'kind' => 'block', 'max_bytes' => 64],
    ];
    public string $mode = 'code_completion';
    public ?string $hashOverride = null;
    public bool $throws = false;
    public array $reads = [];
    public function findVisible(int $userId, int $problemId, ?ProblemAccessContext $context = null): ?SubmissionProblem { return null; }
    public function findVisibleMany(int $userId, array $problemIds, ?ProblemAccessContext $context = null): array { return []; }
    public function listVisible(string $keyword = '', int $limit = 100): array { return []; }
    public function codePolicy(int $userId, int $problemId, ?ProblemAccessContext $context = null): SubmissionCodePolicy {
        $this->reads[] = [$userId, $problemId, $context];
        if ($this->throws) { throw new RuntimeException('SECRET policy failure'); }
        $prepared = (new CodeCompletionTemplatePolicy())->prepare($this->starter,
            json_encode(['language_key' => $this->language, 'blanks' => $this->blanks]), null);
        // Extra server-only metadata must be dropped, never serialized to consumers.
        $schema = json_decode($prepared['schema'], true);
        $schema['answer'] = 'SECRET reference answer';
        $schema['blanks'][0]['answer'] = 'SECRET per-blank answer';
        return new SubmissionCodePolicy($problemId, '빈칸 연습', $this->mode, ['SECRET forbidden'],
            'SECRET prefix', $this->starter, 'SECRET suffix', null,
            $this->mode === 'code_completion' ? json_encode($schema) : null,
            $this->mode === 'code_completion' ? ($this->hashOverride ?? $prepared['hash']) : null,
            'problem-code-policy-v1:' . str_repeat('b', 64));
    }
};
$correlations = new AddonCorrelationIdFactory();
$languages = new SubmissionLanguageCatalog();
$gateway = new RealAddonCodeCompletionGateway('oj-primm', $access, $policies, $correlations, $languages);
$query = new AddonCodeCompletionQueryV1(7, 101);
$template = $gateway->template($query);
$check($template->isSuccessful(), 'Authorized completion problems must expose their public template.');
$payload = $template->payload();
$check(array_keys($payload) === ['problem_id', 'language', 'template_hash', 'starter_code', 'blanks'], 'Template keys must use the public allowlist.');
$check($payload['starter_code'] === $policies->starter && substr_count($payload['starter_code'], '{{OJ_BLANK:') === 2,
    'The original starter and its blank tokens must be preserved exactly.');
$check($payload['language'] === 'python' && $payload['problem_id'] === 101, 'Template must identify its language and problem.');
$check(! str_contains(json_encode($payload), 'SECRET'), 'Schema extras, reference answers, wrappers and own submissions must never be projected.');
foreach ($payload['blanks'] as $blank) {
    $check(array_keys($blank) === ['id', 'label', 'kind', 'max_bytes'], 'Blanks must expose only the four public metadata fields.');
}
$answers = ['value' => '3', 'body' => "print(n)\r\nprint(n + 1)"];
$answer = new AddonCodeCompletionAnswerV1(7, 101, $payload['template_hash'], $answers);
$assembly = $gateway->assemble($answer);
$direct = (new CodeCompletionSourceMode($languages))->build(new SubmissionSourceRequest(
    $policies->codePolicy(7, 101), 6, '', $payload['template_hash'], $answers, '', ''));
$check($assembly->isSuccessful() && $assembly->payload()['source'] === $direct->userSource()
    && $assembly->payload()['source'] === "n = 3\nprint(n)\nprint(n + 1)\n", 'API and formal completion mode must reconstruct identically, including CRLF normalization.');
$check(array_keys($assembly->payload()) === ['problem_id', 'language', 'template_hash', 'source']
    && ! str_contains(json_encode($assembly->payload()), 'SECRET'), 'Assembly must expose only student source and public identifiers.');
$syntaxError = $gateway->assemble(new AddonCodeCompletionAnswerV1(7, 101, $payload['template_hash'], ['value' => '!', 'body' => 'invalid syntax']));
$check($syntaxError->isSuccessful() && ! isset($syntaxError->payload()['accepted']), 'Reconstruction must not claim execution or AC.');
foreach ([
    [], ['value' => '1'], $answers + ['foreign' => '1'],
    ['value' => "1\n2", 'body' => 'pass'], ['value' => '12345', 'body' => 'pass'],
    ['value' => '가나', 'body' => 'pass'], ['value' => ' ', 'body' => 'pass'],
    ['value' => "1\0", 'body' => 'pass'], ['value' => '1', 'body' => str_repeat('x', 65)],
] as $invalidAnswers) {
    $result = $gateway->assemble(new AddonCodeCompletionAnswerV1(7, 101, $payload['template_hash'], $invalidAnswers));
    $check(! $result->isSuccessful() && $result->payload() === [], 'Missing, extra, multiline-inline, oversized, empty and NUL answers must fail safely.');
}
$check(! $gateway->assemble(new AddonCodeCompletionAnswerV1(7, 101, str_repeat('0', 64), $answers))->isSuccessful(), 'Stale hashes must be rejected.');
$policies->starter .= '# changed';
$check(! $gateway->assemble($answer)->isSuccessful(), 'Changing the starter after opening must invalidate the old answer version.');
$policies->starter = $payload['starter_code'];
$reads = count($policies->reads);
$check(! $gateway->template(new AddonCodeCompletionQueryV1(8, 101))->isSuccessful() && count($policies->reads) === $reads,
    'A different actor must fail before policy access.');
$access->currentActor = null;
$check(! $gateway->template($query)->isSuccessful(), 'Logged out requests must fail.');
$access->currentActor = 7;
$check(! $gateway->template(new AddonCodeCompletionQueryV1(7, 999))->isSuccessful(), 'Unrelated problems must fail.');
foreach ([['class', 5], ['group', 6]] as [$type, $id]) {
    $check($gateway->template(new AddonCodeCompletionQueryV1(7, 101, $type, $id))->isSuccessful(), 'Authorized contexts must work.');
    $last = $access->queries[array_key_last($access->queries)];
    $check($last->contextType() === $type && $last->contextId() === $id && $last->submissionId() === null,
        'Learning checks must receive the exact context with no requested submission.');
}
$check(! $gateway->template(new AddonCodeCompletionQueryV1(7, 101, 'class', 9))->isSuccessful(), 'Unrelated classroom access must fail.');
$check(! $gateway->template(new AddonCodeCompletionQueryV1(7, 101, 'contest', 8))->isSuccessful(), 'Contest restrictions must be preserved.');
$access->contestAllowed = true;
$check($gateway->template(new AddonCodeCompletionQueryV1(7, 101, 'contest', 8))->isSuccessful(), 'Permitted contest feedback contexts must work.');
$context = $policies->reads[array_key_last($policies->reads)][2];
$check($context->contestId() === 8, 'Problems policy lookup must use the same contest context.');
$access->allowed = false;
$reads = count($policies->reads);
$check(! $gateway->assemble($answer)->isSuccessful() && count($policies->reads) === $reads, 'Revoked access must be rechecked on assembly.');
$access->allowed = true;
$policies->mode = 'full_code';
$check(! $gateway->template($query)->isSuccessful(), 'Non-completion problems must not expose their starter.');
$policies->mode = 'code_completion';
$policies->hashOverride = str_repeat('c', 64);
$check(! $gateway->template($query)->isSuccessful(), 'Mismatched stored template hashes must fail.');
$policies->hashOverride = null;
$policies->language = 'unsupported';
$check(! $gateway->template($query)->isSuccessful(), 'Unknown languages must be rejected by the shared submission language catalog.');
$policies->language = 'python3';
$check($gateway->template($query)->isSuccessful(), 'Existing language aliases must remain usable.');
$policies->language = 'python';
$policies->throws = true;
$failure = $gateway->template($query);
$check(! $failure->isSuccessful() && $failure->payload() === [] && ! str_contains($failure->problem()->message(), 'SECRET'), 'Internal errors must return safe failures.');
$policies->throws = false;
$access->throws = true;
$check(! $gateway->assemble($answer)->isSuccessful(), 'Authorization errors must fail closed.');
$access->throws = false;

// Preserve the existing per-blank limits without adding an artificial 64KB total.
$policies->blanks[0]['kind'] = 'block';
$policies->blanks[0]['max_bytes'] = 65536;
$policies->blanks[1]['max_bytes'] = 65536;
$largeTemplate = $gateway->template($query)->payload();
$large = $gateway->assemble(new AddonCodeCompletionAnswerV1(7, 101, $largeTemplate['template_hash'],
    ['value' => str_repeat('x', 65536), 'body' => str_repeat("x\r\n", 21845)]));
$check($large->isSuccessful() && strlen($large->payload()['source']) > 65536,
    'Valid multiple block answers and CRLF limits must match the existing source mode.');

$factory = new UnavailableAddonRuntimeServiceFactory($correlations);
$runtimeArgs = ['oj-primm', null, '2.3.23', '1.4.0', '', $factory->contexts('oj-primm', null),
    $factory->aiTasks('oj-primm', null), $correlations];
$legacy = new AddonRuntimeContext(...$runtimeArgs);
$check($legacy instanceof AddonRuntimeContextV1 && $legacy instanceof AddonCodeCompletionRuntimeContextV1,
    'The optional extension must preserve the base runtime contract.');
$check(! $legacy->codeCompletion()->template($query)->isSuccessful()
    && ! $legacy->codeCompletion()->assemble($answer)->isSuccessful(), 'Old constructor calls must have safe unavailable fallbacks.');
$runtime = new AddonRuntimeContext(...[...$runtimeArgs, null, null, null, null, $gateway]);
$check($runtime->codeCompletion()->template($query)->isSuccessful(), 'The appended gateway must be usable from runtime.');
$problem = new AddonApiProblemV1('addon_context_unavailable', 'Unavailable', 'completion:test', false);
foreach ([
    fn () => new AddonCodeCompletionQueryV1(0, 101),
    fn () => new AddonCodeCompletionQueryV1(7, 0),
    fn () => new AddonCodeCompletionQueryV1(7, 101, 'general', 5),
    fn () => new AddonCodeCompletionQueryV1(7, 101, 'class'),
    fn () => new AddonCodeCompletionAnswerV1(7, 101, 'bad', $answers),
    fn () => new AddonCodeCompletionAnswerV1(7, 101, $payload['template_hash'], ['value' => []]),
    fn () => new AddonCodeCompletionAnswerV1(7, 101, $payload['template_hash'], ['bad id' => '1']),
    fn () => new AddonCodeCompletionTemplateResultV1(false, $payload, $problem),
    fn () => new AddonCodeCompletionTemplateResultV1(true, $payload + ['answers' => ['value' => '3']], null),
    fn () => new AddonCodeCompletionTemplateResultV1(true, array_replace($payload, ['blanks' => [$payload['blanks'][0], $payload['blanks'][0]]]), null),
    fn () => new AddonCodeCompletionAssemblyResultV1(true, $assembly->payload() + ['judge_source' => 'SECRET'], null),
    fn () => new AddonCodeCompletionAssemblyResultV1(false, $assembly->payload(), $problem),
] as $invalid) { $throws($invalid); }
fwrite(STDOUT, "Addon code completion runtime: {$checks} checks passed\n");
