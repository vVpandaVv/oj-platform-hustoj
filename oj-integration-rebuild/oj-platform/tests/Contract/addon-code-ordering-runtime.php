<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingAnswerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingAssemblyResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingPuzzleResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRuntimeContext;
use CodingPanda\OjPlatform\Kernel\Addon\RealAddonCodeOrderingGateway;
use CodingPanda\OjPlatform\Kernel\Addon\UnavailableAddonRuntimeServiceFactory;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Shared\CodeOrdering\CodeOrderingPuzzle;

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
    $check(false, 'Malformed public DTOs must be rejected.');
};

// The existing learning-check contract tests exercise its real user/class/group/contest
// authorizers. Here the gateway boundary proves every request delegates to that contract
// before reading policies, and never forwards the private material in its response.
$access = new class implements AddonLearningCheckGatewayV1 {
    public ?int $currentActor = 7;
    public bool $allowed = true;
    public bool $contestAllowed = false;
    public bool $throws = false;
    public array $queries = [];
    public function context(AddonLearningCheckContextQueryV1 $query): AddonLearningCheckContextResultV1 {
        $this->queries[] = $query;
        if ($this->throws) { throw new RuntimeException('SECRET authorization exception'); }
        $contextAllowed = match ($query->contextType()) {
            'general' => true,
            'class' => $query->contextId() === 5,
            'group' => $query->contextId() === 6,
            'contest' => $query->contextId() === 8 && $this->contestAllowed,
        };
        if (! $this->allowed || $query->actorUserId() !== $this->currentActor
            || $query->problemId() !== 101 || ! $contextAllowed) {
            return new AddonLearningCheckContextResultV1(false, [],
                new AddonApiProblemV1('addon_access_denied', 'Denied', 'ordering:denied', false));
        }
        return new AddonLearningCheckContextResultV1(true, [
            'problem' => ['id' => 101, 'title' => '배열 연습', 'description' => '공개 문제',
                'input' => '', 'output' => '', 'sample_input' => '', 'sample_output' => '',
                'content_hash' => str_repeat('a', 64)],
            'submission' => ['id' => 1, 'problem_id' => 101, 'source' => 'SECRET own submission',
                'language_id' => 6, 'language' => 'python', 'result_code' => 4,
                'submitted_at' => '', 'processing' => false],
        ], null);
    }
};
$schema = CodeOrderingPuzzle::encode(CodeOrderingPuzzle::create("x = 1\nx += 2\nprint(x)\n", 'python'));
$policies = new class implements SubmissionProblemProvider {
    public string $schema = '';
    public ?string $hash = null;
    public string $mode = 'code_ordering';
    public bool $throws = false;
    public array $reads = [];
    public function findVisible(int $userId, int $problemId, ?ProblemAccessContext $context = null): ?SubmissionProblem { return null; }
    public function findVisibleMany(int $userId, array $problemIds, ?ProblemAccessContext $context = null): array { return []; }
    public function listVisible(string $keyword = '', int $limit = 100): array { return []; }
    public function codePolicy(int $userId, int $problemId, ?ProblemAccessContext $context = null): SubmissionCodePolicy {
        $this->reads[] = [$userId, $problemId, $context];
        if ($this->throws) { throw new RuntimeException('SECRET policy exception'); }
        return new SubmissionCodePolicy($problemId, '배열 연습', $this->mode, ['SECRET forbidden'],
            'SECRET prefix', 'SECRET canonical starter', 'SECRET suffix', null, null, null,
            'problem-code-policy-v1:' . str_repeat('b', 64),
            $this->mode === 'code_ordering' ? $this->schema : null,
            $this->mode === 'code_ordering' ? ($this->hash ?? CodeOrderingPuzzle::hash($this->schema)) : null);
    }
};
$policies->schema = $schema;
$correlations = new AddonCorrelationIdFactory();
$gateway = new RealAddonCodeOrderingGateway('oj-primm', $access, $policies, $correlations);
$query = new AddonCodeOrderingQueryV1(7, 101);
$puzzle = $gateway->puzzle($query);
$check($puzzle->isSuccessful(), 'Authorized code ordering problems must provide a puzzle.');
$payload = $puzzle->payload();
$check(array_keys($payload) === ['problem_id', 'language', 'template_hash', 'blocks']
    && $payload['problem_id'] === 101 && $payload['language'] === 'python', 'Puzzle payload must have a minimal allowlist.');
$check($payload['template_hash'] === CodeOrderingPuzzle::hash($schema), 'The published hash must bind the actual schema.');
$original = json_decode($schema, true)['blocks'];
$publishedIds = array_column($payload['blocks'], 'id');
$expectedIds = array_column($original, 'id');
sort($publishedIds);
sort($expectedIds);
$check($publishedIds === $expectedIds, 'Public shuffling must preserve exactly the piece set; coincidental original order is allowed.');
$reorderedSchema = json_decode($schema, true);
$reorderedSchema['blocks'] = array_reverse($reorderedSchema['blocks']);
$check(CodeOrderingPuzzle::hash(CodeOrderingPuzzle::encode($reorderedSchema)) === $payload['template_hash'],
    'The public template hash must not act as an oracle for the solution order.');
$check(! str_contains(json_encode($payload), 'SECRET') && ! isset($payload['source']), 'Wrappers, own submissions and canonical source must not be forwarded.');
foreach ($payload['blocks'] as $block) {
    $check(array_keys($block) === ['id', 'code'], 'Blocks must not expose positions or solution metadata.');
}

$ids = array_reverse(array_column($original, 'id'));
$answer = new AddonCodeOrderingAnswerV1(7, 101, $payload['template_hash'], $ids);
$assembly = $gateway->assemble($answer);
$check($assembly->isSuccessful() && $assembly->payload()['source'] === implode('', array_reverse(array_column($original, 'code'))),
    'Assembly must reconstruct the requested order without silently correcting it.');
$check(array_keys($assembly->payload()) === ['problem_id', 'language', 'template_hash', 'source']
    && ! isset($assembly->payload()['accepted']), 'Assembly success must not claim correctness or AC.');
$check(! str_contains(json_encode($assembly->payload()), 'SECRET'), 'Assembly must not add hidden wrappers or submissions.');
foreach ([[], array_slice($ids, 1), [$ids[0], $ids[0], $ids[2]], [$ids[0], $ids[1], str_repeat('f', 32)]] as $invalidIds) {
    $result = $gateway->assemble(new AddonCodeOrderingAnswerV1(7, 101, $payload['template_hash'], $invalidIds));
    $check(! $result->isSuccessful() && $result->payload() === [], 'Missing, duplicate and foreign blocks must fail without source.');
}
$stale = $gateway->assemble(new AddonCodeOrderingAnswerV1(7, 101, str_repeat('0', 64), $ids));
$check(! $stale->isSuccessful() && $stale->payload() === [], 'A stale puzzle hash must fail closed.');
$reads = count($policies->reads);
$check(! $gateway->puzzle(new AddonCodeOrderingQueryV1(8, 101))->isSuccessful()
    && count($policies->reads) === $reads, 'Actor mismatch must fail before policy reads.');
$access->currentActor = null;
$check(! $gateway->puzzle($query)->isSuccessful(), 'Logged out requests must fail.');
$access->currentActor = 7;
$check(! $gateway->puzzle(new AddonCodeOrderingQueryV1(7, 999))->isSuccessful(), 'Unrelated problems must fail.');
foreach ([['class', 5], ['group', 6]] as [$type, $id]) {
    $check($gateway->puzzle(new AddonCodeOrderingQueryV1(7, 101, $type, $id))->isSuccessful(), 'Authorized learning contexts must be forwarded.');
    $last = $access->queries[array_key_last($access->queries)];
    $check($last->contextType() === $type && $last->contextId() === $id && $last->submissionId() === null,
        'Learning-check authorization must receive the exact context and no requested submission.');
}
$check(! $gateway->puzzle(new AddonCodeOrderingQueryV1(7, 101, 'class', 9))->isSuccessful(), 'Unrelated classroom access must fail.');
$check(! $gateway->puzzle(new AddonCodeOrderingQueryV1(7, 101, 'contest', 8))->isSuccessful(), 'Contest feedback restrictions must be respected.');
$access->contestAllowed = true;
$check($gateway->puzzle(new AddonCodeOrderingQueryV1(7, 101, 'contest', 8))->isSuccessful(), 'Allowed contest contexts must remain usable.');
$context = $policies->reads[array_key_last($policies->reads)][2];
$check($context->contestId() === 8, 'The public Problems contract must also receive the contest context.');
$access->allowed = false;
$reads = count($policies->reads);
$denied = $gateway->assemble($answer);
$check(! $denied->isSuccessful() && $denied->payload() === [] && count($policies->reads) === $reads,
    'Access revocation after opening a puzzle must deny assembly before policy reads.');
$access->allowed = true;
$policies->mode = 'full_code';
$check(! $gateway->puzzle($query)->isSuccessful(), 'Ordinary problems must not expose their starter code as puzzles.');
$policies->mode = 'code_ordering';
$policies->hash = str_repeat('c', 64);
$check(! $gateway->puzzle($query)->isSuccessful(), 'Inconsistent policy and schema hashes must fail.');
$policies->hash = null;
$policies->throws = true;
$failure = $gateway->puzzle($query);
$check(! $failure->isSuccessful() && $failure->payload() === []
    && ! str_contains($failure->problem()->message(), 'SECRET'), 'Provider exceptions must become safe failures.');
$policies->throws = false;
$access->throws = true;
$check(! $gateway->assemble($answer)->isSuccessful(), 'Authorization exceptions must fail closed.');
$access->throws = false;

$factory = new UnavailableAddonRuntimeServiceFactory($correlations);
$runtimeArgs = ['oj-primm', null, '2.3.23', '1.4.0', '', $factory->contexts('oj-primm', null),
    $factory->aiTasks('oj-primm', null), $correlations];
$legacyRuntime = new AddonRuntimeContext(...$runtimeArgs);
$check($legacyRuntime instanceof AddonRuntimeContextV1 && $legacyRuntime instanceof AddonCodeOrderingRuntimeContextV1,
    'The optional extension must preserve the base runtime contract.');
$check(! $legacyRuntime->codeOrdering()->puzzle($query)->isSuccessful()
    && ! $legacyRuntime->codeOrdering()->assemble($answer)->isSuccessful(), 'The old constructor must return unavailable gateways safely.');
$runtime = new AddonRuntimeContext(...[...$runtimeArgs, null, null, null, $gateway]);
$check($runtime->codeOrdering()->puzzle($query)->isSuccessful(), 'The appended optional gateway must be usable through the public runtime.');
$problem = new AddonApiProblemV1('addon_context_unavailable', 'Unavailable', 'ordering:test', false);
foreach ([
    fn () => new AddonCodeOrderingQueryV1(0, 101),
    fn () => new AddonCodeOrderingQueryV1(7, 0),
    fn () => new AddonCodeOrderingQueryV1(7, 101, 'general', 5),
    fn () => new AddonCodeOrderingQueryV1(7, 101, 'class'),
    fn () => new AddonCodeOrderingAnswerV1(7, 101, 'bad', $ids),
    fn () => new AddonCodeOrderingAnswerV1(7, 101, $payload['template_hash'], ['source code']),
    fn () => new AddonCodeOrderingAnswerV1(7, 101, $payload['template_hash'], array_fill(0, 201, $ids[0])),
    fn () => new AddonCodeOrderingPuzzleResultV1(false, $payload, $problem),
    fn () => new AddonCodeOrderingPuzzleResultV1(true, $payload + ['solution_order' => $ids], null),
    fn () => new AddonCodeOrderingPuzzleResultV1(true, array_replace($payload, ['blocks' => [$payload['blocks'][0], $payload['blocks'][0]]]), null),
    fn () => new AddonCodeOrderingAssemblyResultV1(true, $assembly->payload() + ['judge_source' => 'SECRET'], null),
    fn () => new AddonCodeOrderingAssemblyResultV1(false, $assembly->payload(), $problem),
] as $invalid) { $throws($invalid); }
fwrite(STDOUT, "Addon code ordering runtime: {$checks} checks passed\n");
