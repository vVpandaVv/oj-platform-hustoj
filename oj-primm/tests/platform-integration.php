<?php
declare(strict_types=1);

/** Real platform DTOs and support gateways; no WordPress database or judge process. */
$pluginRoot = dirname(__DIR__);
$platformRoot = dirname($pluginRoot) . '/oj-integration-rebuild/oj-platform';
require_once $platformRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($platformRoot);
require_once $pluginRoot . '/src/Core/Autoloader.php';
\OjPrimm\Core\Autoloader::register();

use CodingPanda\OjPlatform\AddonApi\V1 as Api;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory;
use CodingPanda\OjPlatform\Kernel\Addon\RealAddonCodeCompletionGateway;
use CodingPanda\OjPlatform\Kernel\Addon\RealAddonCodeOrderingGateway;
use CodingPanda\OjPlatform\Modules\Problems\Application\CodeCompletionTemplatePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionLanguageCatalog;
use CodingPanda\OjPlatform\Shared\CodeOrdering\CodeOrderingPuzzle;
use OjPrimm\Addon\RestController;
use OjPrimm\Addon\RuntimeBridge;
use OjPrimm\Application\PrimmService;
use OjPrimm\Core\Plugin;
use OjPrimm\Storage\Repository;
use OjPrimm\Support\PlatformGateway;

$GLOBALS['integration_actor'] = 10;
function get_current_user_id(): int { return $GLOBALS['integration_actor']; }
function is_user_logged_in(): bool { return get_current_user_id() > 0; }
function current_user_can(string $cap): bool {
    return $cap === Api\AddonCapabilityKeyV1::wordpress(get_current_user_id() === 10 ? 'oj_primm_teach' : 'oj_primm_learn');
}
function is_super_admin(): bool { return false; }
function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'integration-nonce' && $action === 'wp_rest'; }
function rest_ensure_response(mixed $data): IntegrationResponse { return new IntegrationResponse($data); }
class IntegrationResponse {
    public array $headers = [];
    public function __construct(public mixed $data) {}
    public function header(string $name, string $value): void { $this->headers[$name] = $value; }
}
class WP_Error {
    public function __construct(public string $code, public string $message, public array $data = []) {}
}
class WP_REST_Request {
    public function __construct(private array $params = [], private array $input = [], private string $nonce = 'integration-nonce') {}
    public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
    public function get_header(string $name): string { return $this->nonce; }
    public function get_json_params(): array { return $this->input; }
    public function get_body(): string { return json_encode($this->input, JSON_THROW_ON_ERROR); }
}
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) { throw new RuntimeException($message); }
};
$rejects = static function (callable $call, string $message) use ($check): void {
    try { $call(); } catch (DomainException) { $check(true, $message); return; }
    $check(false, $message);
};

$contexts = new class implements Api\AddonLearningContextGatewayV1 {
    public bool $active = true;
    public array $requests = [];
    private function page(): Api\AddonContextPageV1 {
        return new Api\AddonContextPageV1(true, [
            new Api\AddonContextSummaryV1('class', 'class:5', '실제 DTO 수업', 'active', 'class-rev:1'),
            new Api\AddonContextSummaryV1('group', 'group:6', '실제 DTO 그룹', 'active', 'group-rev:1'),
        ], '', null);
    }
    public function listTeachingContexts(Api\AddonTeachingContextQueryV1 $query): Api\AddonContextPageV1 { $this->requests[] = $query; return $this->page(); }
    public function listLearningContexts(Api\AddonLearningContextQueryV1 $query): Api\AddonContextPageV1 { $this->requests[] = $query; return $this->page(); }
    public function authorize(Api\AddonContextAccessRequestV1 $request): Api\AddonContextAccessDecisionV1 {
        $this->requests[] = $request;
        $scope = ($request->contextType() === 'class' && $request->contextId() === 'class:5')
            || ($request->contextType() === 'group' && $request->contextId() === 'group:6');
        $allowed = $this->active && $scope && $request->actorUserId() === get_current_user_id()
            && ($request->operation() === 'participate'
                ? in_array(get_current_user_id(), [7, 8], true) && $request->learnerUserId() === get_current_user_id()
                : get_current_user_id() === 10 && ($request->learnerUserId() === null || in_array($request->learnerUserId(), [7, 8], true)));
        return new Api\AddonContextAccessDecisionV1($allowed, $allowed ? '' : 'addon_context_denied',
            $allowed ? new Api\AddonContextSnapshotV1($request->contextType(), $request->contextId(), '수업',
                'active', 'context-rev:1', get_current_user_id() === 10 ? 'teacher' : 'learner') : null, 'integration:context');
    }
    public function snapshot(Api\AddonContextSnapshotRequestV1 $request): Api\AddonContextSnapshotResultV1 {
        throw new LogicException('Unused contract method');
    }
};
$learning = new class implements Api\AddonLearningCheckGatewayV1 {
    public array $queries = [];
    public function context(Api\AddonLearningCheckContextQueryV1 $query): Api\AddonLearningCheckContextResultV1 {
        $this->queries[] = $query;
        $allowed = $query->actorUserId() === get_current_user_id() && in_array($query->problemId(), [100, 200, 201], true)
            && (($query->contextType() === 'class' && $query->contextId() === 5)
                || ($query->contextType() === 'group' && $query->contextId() === 6));
        // Submission 903 is learner 7's AC; 904 is learner 7's WA; 905 belongs to learner 8.
        $submissionId = $query->submissionId();
        if ($submissionId !== null && ($query->problemId() !== 100 || ! in_array($submissionId, [903, 904], true) || get_current_user_id() !== 7)) { $allowed = false; }
        if (! $allowed) { return new Api\AddonLearningCheckContextResultV1(false, [], new Api\AddonApiProblemV1(
            'addon_access_denied', '문제 또는 본인 제출 접근 거부', 'integration:learning', false)); }
        return new Api\AddonLearningCheckContextResultV1(true, [
            'problem' => ['id' => $query->problemId(), 'title' => '실제 DTO 문제 ' . $query->problemId(),
                'description' => '공개 설명', 'input' => '공개 입력', 'output' => '공개 출력',
                'sample_input' => "1\n", 'sample_output' => "1\n", 'content_hash' => hash('sha256', (string) $query->problemId())],
            'submission' => $submissionId === null ? null : ['id' => $submissionId, 'problem_id' => 100,
                'source' => 'private learner 7 source', 'language_id' => 6, 'language' => 'python',
                'result_code' => $submissionId === 903 ? 4 : 6, 'submitted_at' => '2026-10-24 10:00:00', 'processing' => false],
        ], null);
    }
};
$integrations = new class implements Api\AddonIntegrationGatewayV1 {
    public function searchProblems(Api\AddonProblemQueryV1 $query): Api\AddonProblemPageV1 {
        return new Api\AddonProblemPageV1(true, [new Api\AddonProblemV1(100, '실제 검색 DTO', 10, 5)], null);
    }
    public function searchActivities(Api\AddonActivityQueryV1 $query): Api\AddonActivityPageV1 { throw new LogicException('Unused'); }
    public function acceptedSubmissions(Api\AddonAcceptedSubmissionQueryV1 $query): Api\AddonAcceptedSubmissionPageV1 { throw new LogicException('Unused'); }
    public function privacyManagementPath(): string { return '/settings'; }
};
$orderingSchema = CodeOrderingPuzzle::encode(CodeOrderingPuzzle::create("x = 1\nprint(x)\n", 'python'));
$starter = "x = {{OJ_BLANK:value}}\nprint(x)\n";
$completion = (new CodeCompletionTemplatePolicy())->prepare($starter, json_encode([
    'language_key' => 'python', 'blanks' => [['id' => 'value', 'label' => '값', 'kind' => 'inline', 'max_bytes' => 10]],
]), null);
$policies = new class($orderingSchema, $starter, $completion) implements SubmissionProblemProvider {
    public function __construct(private string $orderingSchema, private string $starter, private array $completion) {}
    public function findVisible(int $userId, int $problemId, ?ProblemAccessContext $context = null): ?SubmissionProblem { throw new LogicException('Unused'); }
    public function findVisibleMany(int $userId, array $problemIds, ?ProblemAccessContext $context = null): array { throw new LogicException('Unused'); }
    public function listVisible(string $keyword = '', int $limit = 100): array { throw new LogicException('Unused'); }
    public function codePolicy(int $userId, int $problemId, ?ProblemAccessContext $context = null): SubmissionCodePolicy {
        return new SubmissionCodePolicy($problemId, '문제', $problemId === 200 ? 'code_ordering' : 'code_completion',
            [], 'SECRET wrapper prefix', $problemId === 201 ? $this->starter : 'SECRET starter', 'SECRET wrapper suffix', null,
            $problemId === 201 ? $this->completion['schema'] : null, $problemId === 201 ? $this->completion['hash'] : null,
            'problem-code-policy-v1:' . str_repeat('b', 64),
            $problemId === 200 ? $this->orderingSchema : null, $problemId === 200 ? CodeOrderingPuzzle::hash($this->orderingSchema) : null);
    }
};
$correlations = new AddonCorrelationIdFactory();
$ordering = new RealAddonCodeOrderingGateway('oj-primm', $learning, $policies, $correlations);
$completionGateway = new RealAddonCodeCompletionGateway('oj-primm', $learning, $policies, $correlations, new SubmissionLanguageCatalog());
$runtime = new class($contexts, $learning, $integrations, $ordering, $completionGateway) implements
    Api\AddonLearningCheckRuntimeContextV1, Api\AddonIntegrationRuntimeContextV1,
    Api\AddonCodeOrderingRuntimeContextV1, Api\AddonCodeCompletionRuntimeContextV1 {
    public function __construct(private Api\AddonContextGatewayV1 $contexts, private Api\AddonLearningCheckGatewayV1 $learning,
        private Api\AddonIntegrationGatewayV1 $integrations, private Api\AddonCodeOrderingGatewayV1 $ordering,
        private Api\AddonCodeCompletionGatewayV1 $completion) {}
    public function snapshot(): Api\AddonRuntimeSnapshotV1 {
        return new Api\AddonRuntimeSnapshotV1('oj-primm', '0.1.0', '>=2.3.23 <3.0.0', '2.3.23', '^1.4', '1.4.0',
            'accepted', '', 'catalog:integration', [], 'integration:runtime');
    }
    public function contexts(): Api\AddonContextGatewayV1 { return $this->contexts; }
    public function aiTasks(): Api\AddonAiTaskGatewayV1 { throw new LogicException('Unused'); }
    public function learningChecks(): Api\AddonLearningCheckGatewayV1 { return $this->learning; }
    public function integrations(): Api\AddonIntegrationGatewayV1 { return $this->integrations; }
    public function codeOrdering(): Api\AddonCodeOrderingGatewayV1 { return $this->ordering; }
    public function codeCompletion(): Api\AddonCodeCompletionGatewayV1 { return $this->completion; }
};
$platform = new PlatformGateway(static fn () => $runtime);
$check($platform->contexts(true)[0] === ['type' => 'class', 'id' => 'class:5', 'label' => '실제 DTO 수업', 'revision' => 'class-rev:1'], 'Real context summary accessors and canonical IDs must map correctly.');
$check($platform->authorize('class', 'class:5', 'assign')['id'] === 'class:5', 'Real access decision and snapshot accessors must map correctly.');
$check($platform->searchProblems('test') === [['id' => 100, 'title' => '실제 검색 DTO']], 'Real problemId accessor must map correctly.');
$check($platform->problem(100, 'class', 'class:5')['problem']['sample_input'] === "1\n", 'Real learning-check payload must be preserved.');
$lastQuery = $learning->queries[array_key_last($learning->queries)];
$check($lastQuery->contextType() === 'class' && $lastQuery->contextId() === 5 && $lastQuery->actorUserId() === 10, 'Prefixed class IDs must become integer query IDs.');
$check($lastQuery->projection() === 'problem', 'Public problem refresh must opt out of submission list, count and source reads.');
$platform->submission(100, 'class', 'class:5');
$check($learning->queries[array_key_last($learning->queries)]->projection() === 'latest', 'Submission lookup must use the bounded latest projection when available.');
$check($platform->problem(100, 'group', 'group:6')['problem']['id'] === 100, 'Group context IDs must use the actual DTO integer shape.');
$check($platform->problemUrl(100, 'class', 'class:5') === '/problems/100?class_id=5'
    && $platform->problemUrl(100, 'group', 'group:6') === '/problems/100?group_id=6', 'Problem links must preserve current class/group query parameters.');
$platform->assertSupport(200, 'code_ordering', 'class', 'class:5');
$platform->assertSupport(201, 'code_completion', 'class', 'class:5');
$check(true, 'Support preflight must work against real platform gateways and DTOs.');
$rejects(fn () => $platform->assertSupport(200, 'code_completion', 'class', 'class:5'), 'Mismatched support kinds must fail.');
$rejects(fn () => $platform->authorize('class', 'class:99', 'assign'), 'Unrelated context authorization must fail.');

$records = new class implements Repository {
    public array $activities = [];
    public array $attempts = [];
    public function createActivity(array $data): array { $data['id'] = count($this->activities) + 1; return $this->activities[$data['id']] = $data; }
    public function findActivity(int $id): ?array { return $this->activities[$id] ?? null; }
    public function activitiesForContext(string $type, string $id): array { return array_values(array_filter($this->activities, static fn ($a) => $a['context_type'] === $type && $a['context_id'] === $id)); }
    public function findAttempt(int $activityId, int $userId): ?array { return $this->attempts[$activityId . ':' . $userId] ?? null; }
    public function saveAttempt(int $activityId, int $userId, int $expectedVersion, array $state, array $event, string $requestHash): array {
        if (($this->findAttempt($activityId, $userId)['version'] ?? 0) !== $expectedVersion) { throw new DomainException('Version mismatch'); }
        return $this->attempts[$activityId . ':' . $userId] = ['id' => $activityId * 100 + $userId, 'activity_id' => $activityId,
            'user_id' => $userId, 'version' => $expectedVersion + 1, 'last_request_hash' => $requestHash,
            'created_at' => '2026-10-24 10:00:00', 'updated_at' => '2026-10-24 10:00:00'] + $state;
    }
    public function attemptsForActivities(array $activityIds): array { return array_values(array_filter($this->attempts, static fn ($a) => in_array($a['activity_id'], $activityIds, true))); }
    public function exportUser(int $userId, int $page = 1): array { throw new LogicException('Unused'); }
    public function eraseUser(int $userId): bool { throw new LogicException('Unused'); }
    public function purgeBefore(string $cutoff): int { throw new LogicException('Unused'); }
};
$service = new PrimmService($records, $platform);
Plugin::connect($runtime);
RuntimeBridge::connect($runtime);
(new ReflectionProperty(Plugin::class, 'service'))->setValue(null, $service);
foreach ([['code_ordering', 200], ['code_completion', 201]] as [$kind, $id]) {
    $service->saveActivity(['context_type' => 'class', 'context_id' => 'class:5', 'target_problem_id' => 100,
        'support_kind' => $kind, 'support_problem_id' => $id]);
}
$check(count($records->activities) === 2, 'Real public runtime data must support teacher activity creation.');
$GLOBALS['integration_actor'] = 7;
$check(RestController::authorize(new WP_REST_Request(), 'student') === true, 'Student REST capability and nonce must pass.');
$check(RestController::authorize(new WP_REST_Request([], [], 'wrong'), 'student') instanceof WP_Error, 'Invalid nonces must fail.');
$check(RestController::authorize(new WP_REST_Request(), 'teacher') instanceof WP_Error, 'Learner capabilities must not grant teacher REST access.');
$check(count($service->studentActivities()) === 2, 'Real learning context page DTOs must list the current learner activities.');
$check(RestController::support(new WP_REST_Request(['id' => 1])) instanceof WP_Error, 'Support endpoints must reject early-stage access.');
foreach ([1, 2] as $activityId) {
    foreach (['predict', 'run', 'investigate'] as $version => $stage) {
        $response = RestController::progress(new WP_REST_Request(['id' => $activityId], [
            'expected_version' => $version, 'stage' => $stage, 'responses' => [$stage => '개념 근거와 관찰 기록'], 'advance' => true,
        ]));
        $check($response instanceof IntegrationResponse && $response->headers['Cache-Control'] === 'private, no-store', 'Authorized progress must use real adapter DTOs and private responses.');
    }
}
$check(array_reduce(array_slice($learning->queries, -8), static fn (bool $valid, Api\AddonLearningCheckContextQueryV1 $query): bool => $valid && $query->projection() === 'problem', true),
    'Autosave problem validation must not reintroduce submission projections.');
$orderResponse = RestController::support(new WP_REST_Request(['id' => 1]));
$check($orderResponse instanceof IntegrationResponse && isset($orderResponse->data['blocks'], $orderResponse->data['template_hash']), 'REST ordering support must accept the real gateway result shape.');
$orderAnswer = RestController::assemble(new WP_REST_Request(['id' => 1], ['template_hash' => $orderResponse->data['template_hash'],
    'block_ids' => array_reverse(array_column($orderResponse->data['blocks'], 'id'))]));
$check($orderAnswer instanceof IntegrationResponse && isset($orderAnswer->data['source']) && ! isset($orderAnswer->data['accepted']), 'Ordering REST assembly must use the real answer DTO without asserting AC.');
$blankResponse = RestController::support(new WP_REST_Request(['id' => 2]));
$check($blankResponse instanceof IntegrationResponse && $blankResponse->data['starter_code'] === $starter && $blankResponse->data['blanks'][0]['id'] === 'value', 'REST completion support must preserve actual starter_code and blanks fields.');
$blankAnswer = RestController::assemble(new WP_REST_Request(['id' => 2], ['template_hash' => $blankResponse->data['template_hash'], 'answers' => ['value' => '5']]));
$check($blankAnswer instanceof IntegrationResponse && $blankAnswer->data['source'] === "x = 5\nprint(x)\n", 'Completion REST assembly must call the actual platform reconstruction mode.');
$check(! str_contains(json_encode([$orderResponse->data, $blankResponse->data, $orderAnswer->data, $blankAnswer->data]), 'SECRET'), 'REST must not reveal wrappers, canonical source or schema internals.');
$check(RestController::assemble(new WP_REST_Request(['id' => 2], ['template_hash' => str_repeat('0', 64), 'answers' => ['value' => '5']])) instanceof WP_Error, 'Stale real gateway hashes must fail through REST.');
$check(RestController::assemble(new WP_REST_Request(['id' => 2], ['template_hash' => $blankResponse->data['template_hash'], 'answers' => []])) instanceof WP_Error, 'Real source-mode blank constraints must fail through REST.');
$GLOBALS['integration_actor'] = 8;
$check($service->activity(1)['attempt']['version'] === 0 && RestController::support(new WP_REST_Request(['id' => 1])) instanceof WP_Error,
    'Another learner must not inherit the first learner stage or support access.');
$GLOBALS['integration_actor'] = 7;
$service->progress(1, ['expected_version' => 3, 'stage' => 'modify', 'responses' => ['modify' => '수정 근거'], 'advance' => true]);
$rejects(fn () => $service->progress(1, ['expected_version' => 4, 'stage' => 'make', 'submission_id' => 905]), 'Foreign learner submissions must be denied by actual learning-check result contracts.');
$wa = $service->progress(1, ['expected_version' => 4, 'stage' => 'make', 'responses' => ['make' => '성찰'], 'submission_id' => 904, 'advance' => true]);
$check($wa['attempt']['status'] === 'process_complete' && ! $wa['attempt']['is_accepted'], 'Self-reported process completion must remain separate from actual WA.');
$ac = $service->progress(1, ['expected_version' => 5, 'stage' => 'make', 'submission_id' => 903]);
$check($ac['attempt']['is_accepted'] && $ac['attempt']['submission_provenance'] === 'verified_own_submission', 'Own AC must come only from the actual typed platform submission payload.');
$check(! str_contains(json_encode($ac), 'private learner 7 source'), 'Student submission sources fetched for verification must not be copied into activity responses.');
$contexts->active = false;
$check(RestController::support(new WP_REST_Request(['id' => 1])) instanceof WP_Error, 'Membership revocation must deny support even with a valid old activity ID.');
$GLOBALS['integration_actor'] = 0;
$check(RestController::authorize(new WP_REST_Request(), 'reader') instanceof WP_Error, 'Logged out REST requests must fail.');
echo 'PRIMM actual platform integration checks passed: ' . $checks . "\n";
