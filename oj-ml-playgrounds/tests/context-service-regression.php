<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRouteDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRoutingSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentLearnerPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentLearnerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSummaryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use OJML\Playgrounds\Application\ContextService;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Platform\Addon_Integration;
use OJML\Playgrounds\Platform\Addon_Runtime_Bridge;

if (!function_exists('sanitize_text_field')) { function sanitize_text_field(string $value): string { return trim(strip_tags($value)); } }
$pluginRoot = dirname(__DIR__);
$workspaceRoot = dirname($pluginRoot);
$configuredPlatform = getenv('OJ_PLATFORM_TEST_ROOT');
$candidates = array_filter([
    is_string($configuredPlatform) ? $configuredPlatform : '',
    $workspaceRoot . '/oj-integration-rebuild/oj-platform',
    $workspaceRoot . '/_releases/oj-platform-2.0.0-dev.79/oj-platform',
]);
$platformRoot = '';
foreach ($candidates as $candidate) {
    if (is_file($candidate . '/src/Kernel/Autoloader.php')) {
        $platformRoot = $candidate;
        break;
    }
}
if ($platformRoot === '') {
    fwrite(STDOUT, "SKIP: OJ Platform test source is unavailable.\n");
    exit(0);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', $pluginRoot . DIRECTORY_SEPARATOR);
}
if (!defined('OJ_ML_PLAYGROUNDS_VERSION')) {
    define('OJ_ML_PLAYGROUNDS_VERSION', (require $pluginRoot . '/plugin-package.php')['version']);
}

require_once $platformRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($platformRoot);
require_once $pluginRoot . '/src/Core/Autoloader.php';
(new \OJML\Playgrounds\Core\Autoloader($pluginRoot . '/src'))->register();
require_once $pluginRoot . '/src/Application/ContextService.php';

/** @param mixed $actual */
function ojMlContextAssert(bool $condition, string $message, mixed $actual = null): void
{
    if (!$condition) {
        throw new RuntimeException($message . ($actual === null ? '' : ' actual=' . var_export($actual, true)));
    }
}

final class OjMlContextGatewayStub implements AddonLearningContextGatewayV1, AddonAssignmentRosterGatewayV1
{
    /** @var array<string,AddonContextPageV1> */
    public array $teachingPages = [];

    /** @var array<string,AddonAssignmentLearnerPageV1> */
    public array $rosterPages = [];

    /** @var list<AddonContextAccessRequestV1> */
    public array $authorizationRequests = [];

    /** @var list<AddonTeachingContextQueryV1> */
    public array $teachingRequests = [];

    /** @var null|Closure(AddonContextAccessRequestV1):AddonContextAccessDecisionV1 */
    public ?Closure $authorization = null;

    public function listTeachingContexts(AddonTeachingContextQueryV1 $query): AddonContextPageV1
    {
        $this->teachingRequests[] = $query;
        return $this->teachingPages[$query->cursor()]
            ?? throw new RuntimeException('Unexpected teaching context cursor: ' . $query->cursor());
    }

    public function authorize(AddonContextAccessRequestV1 $request): AddonContextAccessDecisionV1
    {
        $this->authorizationRequests[] = $request;
        if ($this->authorization !== null) {
            return ($this->authorization)($request);
        }
        return new AddonContextAccessDecisionV1(
            true,
            '',
            new AddonContextSnapshotV1(
                $request->contextType(),
                $request->contextId(),
                'Context',
                'active',
                $request->expectedRevision() ?? 'ctx-r1',
                'teacher'
            ),
            'corr-authorize'
        );
    }

    public function snapshot(AddonContextSnapshotRequestV1 $request): AddonContextSnapshotResultV1
    {
        throw new RuntimeException('Not used.');
    }

    public function listLearningContexts(AddonLearningContextQueryV1 $query): AddonContextPageV1
    {
        throw new RuntimeException('Not used.');
    }

    public function listAssignmentLearners(AddonAssignmentRosterQueryV1 $query): AddonAssignmentLearnerPageV1
    {
        return $this->rosterPages[$query->cursor()]
            ?? throw new RuntimeException('Unexpected roster cursor: ' . $query->cursor());
    }
}

/** @return Addon_Runtime_Bridge */
function ojMlReadyRuntime(OjMlContextGatewayStub $contexts): Addon_Runtime_Bridge
{
    $counts = [
        'capability' => 6,
        'console_page' => 3,
        'public_page' => 3,
        'console_menu' => 3,
        'public_menu' => 1,
        'asset_bundle' => 1,
        'ai_task' => 0,
    ];
    $snapshot = new AddonRuntimeSnapshotV1(
        Addon_Integration::ADDON_ID,
        OJ_ML_PLAYGROUNDS_VERSION,
        Addon_Integration::PLATFORM_RANGE,
        '2.0.0-dev.88',
        Addon_Integration::ADDON_API_RANGE,
        '1.1.0',
        'accepted',
        '',
        'catalog-r1',
        $counts,
        'corr-runtime'
    );
    $ai = new class implements AddonAiTaskGatewayV1 {
        public function canRoute(string $taskKey): AddonAiTaskRouteDecisionV1 { throw new RuntimeException('Not used.'); }
        public function snapshot(string $taskKey): AddonAiTaskRoutingSnapshotV1 { throw new RuntimeException('Not used.'); }
        public function execute(AddonAiTaskRequestV1 $request): AddonAiTaskResultV1 { throw new RuntimeException('Not used.'); }
    };
    $runtimeContext = new class($snapshot, $contexts, $ai) implements AddonRuntimeContextV1 {
        public function __construct(
            private readonly AddonRuntimeSnapshotV1 $snapshotValue,
            private readonly AddonContextGatewayV1 $contextGateway,
            private readonly AddonAiTaskGatewayV1 $aiGateway
        ) {
        }
        public function snapshot(): AddonRuntimeSnapshotV1 { return $this->snapshotValue; }
        public function contexts(): AddonContextGatewayV1 { return $this->contextGateway; }
        public function aiTasks(): AddonAiTaskGatewayV1 { return $this->aiGateway; }
    };
    $runtime = new Addon_Runtime_Bridge();
    $runtime->connect($runtimeContext);
    return $runtime;
}

// Runtime unavailable is a visible 503, never an empty/allowed fallback.
$unreadyRuntime = new Addon_Runtime_Bridge();
$unreadyDiagnostics = $unreadyRuntime->diagnostics();
ojMlContextAssert(
    ($unreadyDiagnostics['state'] ?? null) === Addon_Runtime_Bridge::STATE_UNAVAILABLE
        && ($unreadyDiagnostics['code'] ?? null) === 'addon_runtime_not_ready',
    'An unready Runtime must expose a stable non-empty diagnostic code.',
    $unreadyDiagnostics
);
$unready = new ContextService($unreadyRuntime);
$unreadyStatus = null;
try {
    $unready->teachingContexts(7);
} catch (Problem $problem) {
    $unreadyStatus = $problem->httpStatus();
}
ojMlContextAssert($unreadyStatus === 503, 'Runtime-not-ready must fail with 503.', $unreadyStatus);

// Teaching context DTOs are projected without platform implementation leakage.
$gateway = new OjMlContextGatewayStub();
$gateway->teachingPages[''] = new AddonContextPageV1(true, [
    new AddonContextSummaryV1('class', 'class-1', '1학년 1반', 'active', 'ctx-r1', ['year' => 2026]),
], '', null);
$readyRuntime = ojMlReadyRuntime($gateway);
$readyDiagnostics = $readyRuntime->diagnostics();
ojMlContextAssert(
    ($readyDiagnostics['state'] ?? null) === Addon_Runtime_Bridge::STATE_READY
        && array_key_exists('code', $readyDiagnostics)
        && $readyDiagnostics['code'] === '',
    'A ready Runtime must preserve its intentionally empty error code.',
    $readyDiagnostics
);
$service = new ContextService($readyRuntime);
$contexts = $service->teachingContexts(7);
ojMlContextAssert($contexts['items'][0]['id'] === 'class-1', 'Teaching context ID projection failed.');
ojMlContextAssert($contexts['items'][0]['revision'] === 'ctx-r1', 'Teaching context revision projection failed.');

// Creation queries must filter at the gateway and projection without hiding historical class scopes.
$groupGateway = new OjMlContextGatewayStub();
$groupGateway->teachingPages[''] = new AddonContextPageV1(true, [
    new AddonContextSummaryV1('class', 'old-class', '기존 수업', 'active', 'ctx-class', []),
    new AddonContextSummaryV1('group', 'group-1', '실습 그룹', 'active', 'ctx-group', []),
], 'next-groups', null);
$groupService = new ContextService(ojMlReadyRuntime($groupGateway));
$groups = $groupService->creationGroups(7, '실습', '', 10);
$groupQuery = $groupGateway->teachingRequests[0];
ojMlContextAssert($groupQuery->types() === ['group'] && $groupQuery->search() === '실습'
    && $groupQuery->actorUserId() === 7 && $groupQuery->limit() === 10,
    'New-assignment selectors must ask the public gateway for groups only.');
ojMlContextAssert(array_column($groups['items'], 'type') === ['group'] && $groups['nextCursor'] === 'next-groups',
    'The creation projection must discard stray classes while preserving pagination.');
$historicalContexts = $groupService->teachingContexts(7);
ojMlContextAssert(array_column($historicalContexts['items'], 'type') === ['class', 'group']
    && $groupGateway->teachingRequests[1]->types() === ['class', 'group'],
    'Historical assignment scope discovery must retain both classes and groups.');

// A two-page roster must retain one stable revision and de-duplicate learner IDs.
$gateway->rosterPages[''] = new AddonAssignmentLearnerPageV1(true, [
    new AddonAssignmentLearnerV1(11, 'member-11'),
    new AddonAssignmentLearnerV1(12, 'member-12'),
], 'page-2', 'roster-r1', null);
$gateway->rosterPages['page-2'] = new AddonAssignmentLearnerPageV1(true, [
    new AddonAssignmentLearnerV1(13, 'member-13'),
], '', 'roster-r1', null);
$snapshot = $service->assignmentSnapshot(7, 'class', 'class-1');
ojMlContextAssert($snapshot['context_revision'] === 'ctx-r1', 'Context revision snapshot failed.');
ojMlContextAssert($snapshot['roster_revision'] === 'roster-r1', 'Roster revision snapshot failed.');
ojMlContextAssert($snapshot['learners'] === [11, 12, 13], 'Two-page roster snapshot failed.', $snapshot['learners']);

// Repeating cursors fail closed.
$loopGateway = new OjMlContextGatewayStub();
$loopGateway->rosterPages[''] = new AddonAssignmentLearnerPageV1(true, [], 'loop', 'roster-r1', null);
$loopGateway->rosterPages['loop'] = new AddonAssignmentLearnerPageV1(true, [], 'loop', 'roster-r1', null);
$loopCode = null;
try {
    (new ContextService(ojMlReadyRuntime($loopGateway)))->assignmentSnapshot(7, 'group', 'group-1');
} catch (Problem $problem) {
    $loopCode = $problem->problemCode();
}
ojMlContextAssert($loopCode === 'OJ_ML_ROSTER_CURSOR_LOOP', 'Roster cursor loop must be rejected.', $loopCode);

// A changed roster revision between pages fails with a retryable conflict.
$revisionGateway = new OjMlContextGatewayStub();
$revisionGateway->rosterPages[''] = new AddonAssignmentLearnerPageV1(true, [], 'next', 'roster-r1', null);
$revisionGateway->rosterPages['next'] = new AddonAssignmentLearnerPageV1(true, [], '', 'roster-r2', null);
$revisionStatus = null;
try {
    (new ContextService(ojMlReadyRuntime($revisionGateway)))->assignmentSnapshot(7, 'group', 'group-1');
} catch (Problem $problem) {
    $revisionStatus = $problem->httpStatus();
}
ojMlContextAssert($revisionStatus === 409, 'Roster revision change must fail with 409.', $revisionStatus);

// Learner and teacher operations must be sent to the public gateway verbatim.
$authorizationGateway = new OjMlContextGatewayStub();
$authorizationService = new ContextService(ojMlReadyRuntime($authorizationGateway));
$assignment = [
    'context_type' => 'class',
    'context_id' => 'class-1',
    'context_revision' => 'ctx-r1',
];
$authorizationService->assertAssignmentAccess(22, $assignment, 'participate', 22);
$authorizationService->assertAssignmentAccess(7, $assignment, 'report');
$authorizationService->assertAssignmentAccess(7, $assignment, 'manage');
$operations = array_map(
    static fn (AddonContextAccessRequestV1 $request): string => $request->operation(),
    $authorizationGateway->authorizationRequests
);
ojMlContextAssert(
    $operations === ['participate', 'report', 'manage'],
    'Learner/teacher authorization operations are incorrect.',
    $operations
);
ojMlContextAssert(
    $authorizationGateway->authorizationRequests[0]->learnerUserId() === 22,
    'Participant authorization must carry the learner ID.'
);

// An allowed but inactive Context cannot be snapshotted into a new assignment.
$inactiveGateway = new OjMlContextGatewayStub();
$inactiveGateway->authorization = static function (AddonContextAccessRequestV1 $request): AddonContextAccessDecisionV1 {
    return new AddonContextAccessDecisionV1(
        true,
        '',
        new AddonContextSnapshotV1(
            $request->contextType(),
            $request->contextId(),
            'Inactive Context',
            'disabled',
            'ctx-disabled-r1',
            'teacher'
        ),
        'corr-inactive'
    );
};
$inactiveCode = null;
$inactiveStatus = null;
try {
    (new ContextService(ojMlReadyRuntime($inactiveGateway)))->assignmentSnapshot(7, 'class', 'disabled-class');
} catch (Problem $problem) {
    $inactiveCode = $problem->problemCode();
    $inactiveStatus = $problem->httpStatus();
}
ojMlContextAssert($inactiveCode === 'OJ_ML_CONTEXT_INACTIVE', 'Inactive Context must be rejected.', $inactiveCode);
ojMlContextAssert($inactiveStatus === 409, 'Inactive Context must be a 409 conflict.', $inactiveStatus);

// A gateway cannot make the service accumulate more than 10,000 unique scopes.
$scopeGateway = new OjMlContextGatewayStub();
for ($pageNumber = 0; $pageNumber <= 100; $pageNumber++) {
    $cursor = $pageNumber === 0 ? '' : 'scope-' . $pageNumber;
    $count = $pageNumber < 100 ? 100 : 1;
    $items = [];
    for ($offset = 0; $offset < $count; $offset++) {
        $number = ($pageNumber * 100) + $offset;
        $items[] = new AddonContextSummaryV1(
            'class',
            'scope-' . $number,
            'Scope ' . $number,
            'active',
            'ctx-r1',
            []
        );
    }
    $next = $pageNumber < 100 ? 'scope-' . ($pageNumber + 1) : '';
    $scopeGateway->teachingPages[$cursor] = new AddonContextPageV1(true, $items, $next, null);
}
$scopeLimitCode = null;
$scopeLimitStatus = null;
try {
    (new ContextService(ojMlReadyRuntime($scopeGateway)))->allTeachingScopes(7);
} catch (Problem $problem) {
    $scopeLimitCode = $problem->problemCode();
    $scopeLimitStatus = $problem->httpStatus();
}
ojMlContextAssert($scopeLimitCode === 'OJ_ML_CONTEXT_SCOPE_LIMIT', 'Context scope hard cap must be enforced.', $scopeLimitCode);
ojMlContextAssert($scopeLimitStatus === 503, 'Context scope hard cap must fail closed with 503.', $scopeLimitStatus);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!class_exists('wpdb')) {
    class wpdb
    {
        /** @var list<array<string,mixed>> */
        public array $assignmentRows = [];

        /** @var list<array<string,mixed>> */
        public array $learnerRows = [];

        /** @var list<array<string,mixed>> */
        public array $learnerSummaryRows = [];

        /** @var array<string,mixed>|null */
        public ?array $resultAssignmentRow = null;

        /** @var array<string,mixed>|null */
        public ?array $resultAttemptRow = null;

        /** @var list<array<string,mixed>> */
        public array $resultRows = [];

        public int $resultTotal = 0;

        /** @var list<mixed> */
        public array $lastPreparedArguments = [];

        /** @var list<string> */
        public array $executedQueries = [];

        public ?int $totalOverride = null;

        public int $pageQueries = 0;

        public int $getRowCalls = 0;

        public function prepare(string $query, mixed ...$arguments): string
        {
            $this->lastPreparedArguments = $arguments;
            return $query;
        }

        public function get_var(string $query): int
        {
            $this->executedQueries[] = $query;
            if (str_contains($query, 'FROM oj_ml_assignment_learners')) {
                return $this->resultTotal;
            }
            return $this->totalOverride ?? count($this->filteredAssignmentRows($query));
        }

        /** @return list<array<string,mixed>> */
        public function get_results(string $query, mixed $output = null): array
        {
            $this->executedQueries[] = $query;
            if (str_contains($query, 'SELECT a.*,') && str_contains($query, 'AS has_submission_history')) {
                return $this->learnerRows;
            }
            if (str_contains($query, 'SELECT a.assignment_id,a.uuid,a.received_at')) {
                return $this->learnerSummaryRows;
            }
            if (str_contains($query, 'SELECT l.user_id AS roster_user_id')) {
                return $this->resultRows;
            }
            if (str_contains($query, 'FROM oj_ml_artifacts')
                || str_contains($query, 'FROM oj_ml_evaluations')
                || str_contains($query, 'FROM oj_ml_reviews')
            ) {
                return [];
            }
            if (!str_contains($query, 'FROM oj_ml_assignments')) {
                return [];
            }
            $this->pageQueries++;
            $count = count($this->lastPreparedArguments);
            $limit = $count >= 2 ? (int) $this->lastPreparedArguments[$count - 2] : 100;
            $offset = $count >= 1 ? (int) $this->lastPreparedArguments[$count - 1] : 0;
            $rows = array_slice($this->filteredAssignmentRows($query), $offset, $limit);
            if (str_contains($query, 'SELECT id,title,status,context_type,context_id,context_revision')) {
                $columns = array_flip(['id', 'title', 'status', 'context_type', 'context_id', 'context_revision']);
                return array_map(
                    static fn (array $row): array => array_intersect_key($row, $columns),
                    $rows
                );
            }
            return $rows;
        }

        /** @return list<array<string,mixed>> */
        private function filteredAssignmentRows(string $query): array
        {
            $rows = $this->assignmentRows;
            $scopeCount = substr_count($query, '(context_type = %s AND context_id = %s)');
            if ($scopeCount > 0) {
                $scopes = array_chunk(array_slice($this->lastPreparedArguments, 0, $scopeCount * 2), 2);
                $rows = array_values(array_filter($rows, static function (array $row) use ($scopes): bool {
                    foreach ($scopes as [$type, $id]) {
                        if ($row['context_type'] === $type && $row['context_id'] === $id) {
                            return true;
                        }
                    }
                    return false;
                }));
            }
            $statuses = str_contains($query, 'status IN (%s,%s,%s)')
                ? array_slice($this->lastPreparedArguments, $scopeCount * 2, 3)
                : (str_contains($query, 'status = %s')
                    ? array_slice($this->lastPreparedArguments, $scopeCount * 2, 1) : []);
            return $statuses === [] ? $rows : array_values(array_filter(
                $rows,
                static fn (array $row): bool => in_array($row['status'], $statuses, true)
            ));
        }

        /** @return list<int> */
        public function get_col(string $query): array
        {
            $this->executedQueries[] = $query;
            return [];
        }
        public function get_row(string $query, mixed $output = null): ?array
        {
            $this->getRowCalls++;
            if (str_contains($query, 'FROM oj_ml_assignments WHERE id = %d')) {
                return $this->resultAssignmentRow;
            }
            if (str_contains($query, 'FROM oj_ml_attempts WHERE uuid = %s')) {
                return $this->resultAttemptRow;
            }
            return null;
        }
    }
}

/** @return list<array<string,mixed>> */
function ojMlAssignmentRows(int $count, int $manageDenied = 0, bool $draftLast = false): array
{
    $rows = [];
    for ($id = 1; $id <= $count; $id++) {
        $rows[] = [
            'id' => $id,
            'title' => 'Assignment ' . $id,
            'description' => '',
            'task_type' => 'classification',
            'context_type' => 'class',
            'context_id' => $id <= $manageDenied ? 'class-denied' : 'class-1',
            'context_revision' => $id <= $manageDenied ? 'ctx-denied' : 'ctx-allowed',
            'roster_revision' => 'roster-r1',
            'status' => $draftLast && $id === $count ? 'draft' : 'published',
            'policy_json' => '{}',
            'rubric_json' => '{}',
            'opens_at' => null,
            'due_at' => null,
            'max_attempts' => 2,
            'revision' => 1,
            'created_by' => 7,
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
            'published_at' => '2026-09-01 00:00:00',
        ];
    }
    return $rows;
}

/** @return array<string,mixed> */
function ojMlResultAttemptRow(): array
{
    return [
        'id' => 901,
        'uuid' => '00000000-0000-4000-8000-000000000123',
        'assignment_id' => 1,
        'user_id' => 22,
        'attempt_no' => 1,
        'status' => 'accepted',
        'idempotency_key' => null,
        'artifact_id' => 1,
        'policy_revision' => 1,
        'client_app_version' => '0.12.1',
        'format_version' => 2,
        'progress_json' => '{"phase":"done"}',
        'last_progress_at' => '2026-09-01 00:10:00',
        'failure_code' => null,
        'failure_json' => null,
        'late' => 0,
        'received_at' => '2026-09-01 00:10:00',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:10:00',
    ];
}

function ojMlResultDatabase(): wpdb
{
    $database = new wpdb();
    $assignment = ojMlAssignmentRows(1)[0];
    $assignment['context_revision'] = 'ctx-result';
    $database->resultAssignmentRow = $assignment;
    $attempt = ojMlResultAttemptRow();
    $database->resultAttemptRow = $attempt;
    $database->resultRows = [[
        'roster_user_id' => 22,
        'attempts_used' => 1,
        ...$attempt,
    ]];
    $database->resultTotal = 1;
    return $database;
}
/** @return AddonContextPageV1 */
function ojMlTeachingScopePage(): AddonContextPageV1
{
    return new AddonContextPageV1(true, [
        new AddonContextSummaryV1('class', 'class-1', 'Class 1', 'active', 'ctx-scope', []),
        new AddonContextSummaryV1('class', 'class-denied', 'Denied class', 'active', 'ctx-scope', []),
    ], '', null);
}

$learnerDatabase = new wpdb();
$learnerDatabase->learnerRows = array_map(
    static function (array $row): array {
        $row['has_submission_history'] = 0;
        return $row;
    },
    ojMlAssignmentRows(200)
);
$learnerDatabase->learnerSummaryRows = array_map(
    static fn (int $id): array => [
        'assignment_id' => $id,
        'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
        'received_at' => '2026-09-01 00:00:00',
    ],
    range(1, 200)
);
$learnerGateway = new OjMlContextGatewayStub();
$learnerService = new \OJML\Playgrounds\Application\AssignmentService(
    new \OJML\Playgrounds\Database\Repository($learnerDatabase),
    new ContextService(ojMlReadyRuntime($learnerGateway))
);
$learnerItems = $learnerService->listForLearner(22);
ojMlContextAssert(
    count($learnerItems) === 200
        && ($learnerItems[199]['latest_attempt_summary']['uuid'] ?? null)
            === '00000000-0000-4000-8000-000000000200',
    'Learner list services must attach one narrow latest-attempt summary batch.',
    count($learnerItems)
);
ojMlContextAssert(
    count($learnerDatabase->executedQueries) === 2
        && $learnerDatabase->getRowCalls === 0
        && count(array_filter(
            $learnerDatabase->executedQueries,
            static fn (string $query): bool => str_contains($query, 'oj_ml_artifacts')
                || str_contains($query, 'oj_ml_evaluations')
                || str_contains($query, 'oj_ml_reviews')
        )) === 0,
    'Two hundred learner list rows must use two repository queries and no detailed attempt decoration.',
    $learnerDatabase->executedQueries
);

$assignmentDatabase = new wpdb();
$assignmentDatabase->assignmentRows = ojMlAssignmentRows(205, 100, true);
$assignmentGateway = new OjMlContextGatewayStub();
$assignmentGateway->teachingPages[''] = ojMlTeachingScopePage();
$assignmentGateway->authorization = static function (AddonContextAccessRequestV1 $request): AddonContextAccessDecisionV1 {
    if ($request->operation() === 'manage' && $request->contextId() === 'class-denied') {
        return new AddonContextAccessDecisionV1(false, 'addon_context_denied', null, 'corr-denied');
    }
    return new AddonContextAccessDecisionV1(
        true,
        '',
        new AddonContextSnapshotV1(
            $request->contextType(),
            $request->contextId(),
            'Context',
            'active',
            $request->expectedRevision() ?? 'ctx-allowed',
            'teacher'
        ),
        'corr-allowed'
    );
};
$assignmentService = new \OJML\Playgrounds\Application\AssignmentService(
    new \OJML\Playgrounds\Database\Repository($assignmentDatabase),
    new ContextService(ojMlReadyRuntime($assignmentGateway))
);
$managed = $assignmentService->teacherAssignments(7, 1, 2, null);
ojMlContextAssert(
    array_column($managed['items'], 'id') === [101, 102]
        && $managed['total'] === 105
        && $managed['page'] === 1
        && $managed['per_page'] === 2,
    'Manage pagination must be rebuilt from authorized rows without a sparse first page.',
    $managed
);
$reported = $assignmentService->reportAssignments(7, 1, 2, null);
ojMlContextAssert(
    array_column($reported['items'], 'id') === [1, 2]
        && $reported['total'] === 204,
    'Report pagination must authorize report independently and omit drafts.',
    $reported
);
$authorizationOperations = array_count_values(array_map(
    static fn (AddonContextAccessRequestV1 $request): string => $request->operation(),
    $assignmentGateway->authorizationRequests
));
ojMlContextAssert(
    ($authorizationOperations['manage'] ?? 0) === 2
        && ($authorizationOperations['report'] ?? 0) === 2,
    'Allowed and denied context revisions must each be memoized once per operation.',
    $authorizationOperations
);
ojMlContextAssert(
    $assignmentDatabase->pageQueries === 6,
    'Authorized totals must consume all three raw pages for each independent collection.',
    $assignmentDatabase->pageQueries
);
ojMlContextAssert(
    count(array_filter(
        $assignmentDatabase->executedQueries,
        static fn (string $query): bool => str_contains($query, 'SELECT COUNT(*) FROM oj_ml_assignments')
    )) === 2,
    'Each authorized collection must count raw scoped assignments only on its first page.'
);

// Archive tabs must filter scoped SQL before paging, then retain manage authorization.
$archiveDatabase = new wpdb();
$archiveDatabase->assignmentRows = ojMlAssignmentRows(260, 40);
foreach ($archiveDatabase->assignmentRows as &$archiveRow) {
    $archiveRow['status'] = ['archived', 'draft', 'published', 'closed'][$archiveRow['id'] % 4];
    if ($archiveRow['id'] > 250) {
        $archiveRow['context_id'] = 'outside-teaching-scope';
        $archiveRow['context_revision'] = 'ctx-outside';
    }
}
unset($archiveRow);
$archiveGateway = new OjMlContextGatewayStub();
$archiveGateway->teachingPages[''] = ojMlTeachingScopePage();
$archiveGateway->authorization = $assignmentGateway->authorization;
$archiveService = new \OJML\Playgrounds\Application\AssignmentService(
    new \OJML\Playgrounds\Database\Repository($archiveDatabase),
    new ContextService(ojMlReadyRuntime($archiveGateway))
);
$expectedVisibleRows = array_values(array_filter(
    $archiveDatabase->assignmentRows,
    static fn (array $row): bool => $row['id'] > 40 && $row['id'] <= 250
));
$expectedUnarchived = array_column(array_filter(
    $expectedVisibleRows,
    static fn (array $row): bool => $row['status'] !== 'archived'
), 'id');
$unarchivedFirst = $archiveService->teacherAssignments(7, 1, 100, 'unarchived');
$unarchivedSecond = $archiveService->teacherAssignments(7, 2, 100, 'unarchived');
$unarchivedEmpty = $archiveService->teacherAssignments(7, 3, 100, 'unarchived');
ojMlContextAssert(
    array_column($unarchivedFirst['items'], 'id') === array_slice($expectedUnarchived, 0, 100)
        && array_column($unarchivedSecond['items'], 'id') === array_slice($expectedUnarchived, 100)
        && $unarchivedEmpty['items'] === []
        && $unarchivedFirst['total'] === 158 && $unarchivedSecond['total'] === 158
        && $unarchivedEmpty['total'] === 158 && $unarchivedSecond['page'] === 2
        && $unarchivedSecond['per_page'] === 100,
    'Unarchived pages must be dense, complete, disjoint, and exclude denied/out-of-scope/archived rows.'
);
ojMlContextAssert(
    count($archiveDatabase->executedQueries) === 9
        && count(array_filter($archiveDatabase->executedQueries,
            static fn (string $query): bool => str_contains($query, 'status IN (%s,%s,%s)'))) === 9
        && array_slice($archiveDatabase->lastPreparedArguments, 4, 3) === ['draft', 'published', 'closed'],
    'Each scoped count and raw page must use the same bound non-archived status list before LIMIT.'
);
foreach (['draft', 'published', 'closed', 'archived'] as $actualStatus) {
    $exactStatus = $archiveService->teacherAssignments(7, 1, 100, $actualStatus);
    $expectedExact = array_column(array_filter($expectedVisibleRows,
        static fn (array $row): bool => $row['status'] === $actualStatus), 'id');
    ojMlContextAssert(array_column($exactStatus['items'], 'id') === $expectedExact
        && $exactStatus['total'] === count($expectedExact),
        'Existing exact status filter must remain correct for ' . $actualStatus . '.');
}
$archiveAll = $archiveService->teacherAssignments(7, 1, 100, null);
$archiveReport = $archiveService->reportAssignments(7, 1, 100, 'archived');
ojMlContextAssert($archiveAll['total'] === 210 && array_column($archiveAll['items'], 'id') === range(41, 140)
    && $archiveReport['total'] === 62 && $archiveReport['items'][0]['id'] === 4,
    'Omitted status must still include archives; report exact status must keep independent report authorization.');
foreach ([['teacherAssignments', 'unknown'], ['teacherAssignments', ''], ['reportAssignments', 'unarchived']] as [$method, $invalidStatus]) {
    $queriesBefore = count($archiveDatabase->executedQueries);
    $invalidCode = null;
    try { $archiveService->$method(7, 1, 20, $invalidStatus); }
    catch (Problem $problem) { $invalidCode = [$problem->problemCode(), $problem->httpStatus()]; }
    ojMlContextAssert($invalidCode === ['OJ_ML_ASSIGNMENT_STATUS_INVALID', 400]
        && count($archiveDatabase->executedQueries) === $queriesBefore,
        'Invalid/list-only status must fail with 400 before database access on ' . $method . '.');
}
$noScopeDatabase = new wpdb();
$noScopeDatabase->assignmentRows = $archiveDatabase->assignmentRows;
$noScopeGateway = new OjMlContextGatewayStub();
$noScopeGateway->teachingPages[''] = new AddonContextPageV1(true, [], '', null);
$noScopeService = new \OJML\Playgrounds\Application\AssignmentService(
    new \OJML\Playgrounds\Database\Repository($noScopeDatabase),
    new ContextService(ojMlReadyRuntime($noScopeGateway))
);
foreach (['unarchived', 'archived'] as $archiveFilter) {
    $noScopeResult = $noScopeService->teacherAssignments(7, 1, 20, $archiveFilter);
    ojMlContextAssert($noScopeResult['items'] === [] && $noScopeResult['total'] === 0
        && $noScopeDatabase->executedQueries === [],
        'An empty teaching scope must never fall back to a global query for ' . $archiveFilter . '.');
}
$archiveHeavyDatabase = new wpdb();
$archiveHeavyDatabase->assignmentRows = ojMlAssignmentRows(10002);
foreach ($archiveHeavyDatabase->assignmentRows as &$archiveHeavyRow) {
    $archiveHeavyRow['status'] = $archiveHeavyRow['id'] === 10002 ? 'draft' : 'archived';
}
unset($archiveHeavyRow);
$archiveHeavyGateway = new OjMlContextGatewayStub();
$archiveHeavyGateway->teachingPages[''] = ojMlTeachingScopePage();
$archiveHeavyService = new \OJML\Playgrounds\Application\AssignmentService(
    new \OJML\Playgrounds\Database\Repository($archiveHeavyDatabase),
    new ContextService(ojMlReadyRuntime($archiveHeavyGateway))
);
$archiveHeavyResult = $archiveHeavyService->teacherAssignments(7, 1, 20, 'unarchived');
ojMlContextAssert($archiveHeavyResult['total'] === 1
    && array_column($archiveHeavyResult['items'], 'id') === [10002]
    && $archiveHeavyDatabase->pageQueries === 1 && count($archiveHeavyGateway->authorizationRequests) === 1,
    'More than ten thousand archived rows must not consume the normal-tab authorization scan limit.');
unset($archiveHeavyDatabase, $archiveHeavyGateway, $archiveHeavyService, $archiveHeavyResult);

// Exercise the real REST adapter and presenter with the same authorized collection.
final class WP_REST_Request
{
    public function __construct(private readonly array $params = []) {}
    public function get_param(string $name): mixed { return $this->params[$name] ?? null; }
}
final class WP_REST_Response
{
    public array $headers = [];
    public function __construct(public array $data = [], public int $status = 200) {}
    public function header(string $name, string $value): void { $this->headers[$name] = $value; }
}
final class WP_Error
{
    public function __construct(public string $code, public string $message, public array $data) {}
}
function get_current_user_id(): int { return $GLOBALS['oj_ml_archive_actor'] ?? 7; }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)) ?? ''; }
function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000099'; }

$archiveRoutesClass = new ReflectionClass(\OJML\Playgrounds\Rest\Routes::class);
$archiveRoutes = $archiveRoutesClass->newInstanceWithoutConstructor();
$archiveRoutesClass->getProperty('assignments')->setValue($archiveRoutes, $archiveService);
$archiveRoutesClass->getProperty('presenter')->setValue($archiveRoutes, new \OJML\Playgrounds\Rest\Presenter());
$restUnarchived = $archiveRoutes->teacherAssignments(new WP_REST_Request([
    'status' => 'unarchived', 'page' => 2, 'per_page' => 100,
]));
ojMlContextAssert($restUnarchived instanceof WP_REST_Response && $restUnarchived->status === 200
    && array_column($restUnarchived->data['data']['items'], 'id') === array_slice($expectedUnarchived, 100)
    && $restUnarchived->data['data']['total'] === 158
    && $restUnarchived->data['data']['page'] === 2 && $restUnarchived->data['data']['perPage'] === 100
    && $restUnarchived->headers['Cache-Control'] === 'no-store, private',
    'The teacher REST adapter must retain status filtering, authorized totals, pagination DTO, and private caching.');
$restArchived = $archiveRoutes->teacherAssignments(new WP_REST_Request([
    'status' => 'archived', 'page' => 1, 'perPage' => 2,
]));
ojMlContextAssert($restArchived instanceof WP_REST_Response
    && array_column($restArchived->data['data']['items'], 'id') === [44, 48]
    && $restArchived->data['data']['total'] === 52 && $restArchived->data['data']['perPage'] === 2,
    'The archive REST query must retain the legacy perPage alias and exact authorized status collection.');
$restAll = $archiveRoutes->teacherAssignments(new WP_REST_Request());
ojMlContextAssert($restAll instanceof WP_REST_Response && $restAll->data['data']['total'] === 210,
    'Omitting status from REST must preserve the existing all-status contract.');
foreach ([['teacherAssignments', 'unknown'], ['reportAssignments', 'unarchived']] as [$method, $status]) {
    $restInvalid = $archiveRoutes->$method(new WP_REST_Request(['status' => $status]));
    ojMlContextAssert($restInvalid instanceof WP_Error
        && $restInvalid->code === 'OJ_ML_ASSIGNMENT_STATUS_INVALID' && $restInvalid->data['status'] === 400,
        'REST must return a 400 status error for unsupported status on ' . $method . '.');
}
$GLOBALS['oj_ml_archive_actor'] = 0;
$restAnonymous = $archiveRoutes->teacherAssignments(new WP_REST_Request(['status' => 'unarchived']));
ojMlContextAssert($restAnonymous instanceof WP_Error && $restAnonymous->code === 'OJ_ML_AUTHENTICATION_REQUIRED'
    && $restAnonymous->data['status'] === 401,
    'The new collection filter must not bypass REST actor authentication.');
unset($GLOBALS['oj_ml_archive_actor']);

$optionsDatabase = new wpdb();
$optionsDatabase->assignmentRows = ojMlAssignmentRows(250, 50, true);
$optionsGateway = new OjMlContextGatewayStub();
$optionsGateway->teachingPages[''] = ojMlTeachingScopePage();
$optionsGateway->authorization = static function (AddonContextAccessRequestV1 $request): AddonContextAccessDecisionV1 {
    if ($request->operation() === 'report' && $request->contextId() === 'class-denied') {
        return new AddonContextAccessDecisionV1(false, 'addon_context_denied', null, 'corr-options-denied');
    }
    return new AddonContextAccessDecisionV1(
        true,
        '',
        new AddonContextSnapshotV1(
            $request->contextType(),
            $request->contextId(),
            'Context',
            'active',
            $request->expectedRevision() ?? 'ctx-allowed',
            'teacher'
        ),
        'corr-options-allowed'
    );
};
$optionsService = new \OJML\Playgrounds\Application\AssignmentService(
    new \OJML\Playgrounds\Database\Repository($optionsDatabase),
    new ContextService(ojMlReadyRuntime($optionsGateway))
);
$options = $optionsService->reportAssignmentOptions(7);
$optionIds = array_column($options['items'], 'id');
ojMlContextAssert(
    count($optionIds) === 199
        && count(array_unique($optionIds)) === 199
        && $optionIds[0] === 51
        && $optionIds[198] === 249
        && $options['total'] === 199,
    'A single report-options service call must return every unique authorized non-draft assignment.',
    $options
);
ojMlContextAssert(
    $optionsDatabase->pageQueries === 3
        && count(array_filter(
            $optionsDatabase->executedQueries,
            static fn (string $query): bool => str_contains($query, 'SELECT COUNT(*) FROM oj_ml_assignments')
        )) === 1
        && count($optionsGateway->authorizationRequests) === 2,
    'Two hundred fifty selector candidates must scan three raw pages once and memoize Context authorization.',
    ['queries' => $optionsDatabase->executedQueries, 'authorization' => count($optionsGateway->authorizationRequests)]
);
ojMlContextAssert(
    count($options['items'][0]) === 6
        && array_diff(array_keys($options['items'][0]), ['id', 'title', 'status', 'context_type', 'context_id', 'context_revision']) === []
        && !array_key_exists('description', $options['items'][0])
        && !array_key_exists('policy', $options['items'][0])
        && str_contains(implode("\n", $optionsDatabase->executedQueries), 'SELECT id,title,status,context_type,context_id,context_revision'),
    'Report options must use the lean authorization projection instead of hydrating full policy/rubric rows.',
    $options['items'][0]
);
$memoDatabase = new wpdb();
$memoDatabase->assignmentRows = ojMlAssignmentRows(1000);
$memoGateway = new OjMlContextGatewayStub();
$memoGateway->teachingPages[''] = ojMlTeachingScopePage();
$memoService = new \OJML\Playgrounds\Application\AssignmentService(
    new \OJML\Playgrounds\Database\Repository($memoDatabase),
    new ContextService(ojMlReadyRuntime($memoGateway))
);
$memoized = $memoService->teacherAssignments(7, 10, 100, null);
ojMlContextAssert(
    $memoized['total'] === 1000
        && count($memoized['items']) === 100
        && ($memoized['items'][0]['id'] ?? null) === 901
        && count($memoGateway->authorizationRequests) === 1,
    'One thousand assignments sharing one context revision must perform one manage authorization.',
    ['result' => $memoized, 'authorizationCount' => count($memoGateway->authorizationRequests)]
);
ojMlContextAssert(
    count(array_filter(
        $memoDatabase->executedQueries,
        static fn (string $query): bool => str_contains($query, 'SELECT COUNT(*) FROM oj_ml_assignments')
    )) === 1,
    'A ten-page authorized scan must execute one raw total query.'
);

foreach (['addon_context_denied', 'context_revision_changed', 'addon_context_unavailable'] as $evaluateDenialCode) {
    $resultDatabase = ojMlResultDatabase();
    $resultGateway = new OjMlContextGatewayStub();
    $resultGateway->authorization = static function (AddonContextAccessRequestV1 $request) use ($evaluateDenialCode): AddonContextAccessDecisionV1 {
        if ($request->operation() === 'evaluate') {
            return new AddonContextAccessDecisionV1(false, $evaluateDenialCode, null, 'corr-evaluate-denied');
        }
        return new AddonContextAccessDecisionV1(
            true,
            '',
            new AddonContextSnapshotV1(
                $request->contextType(),
                $request->contextId(),
                'Context',
                'active',
                $request->expectedRevision() ?? 'ctx-result',
                'teacher'
            ),
            'corr-report-allowed'
        );
    };
    $resultService = new \OJML\Playgrounds\Application\AssignmentService(
        new \OJML\Playgrounds\Database\Repository($resultDatabase),
        new ContextService(ojMlReadyRuntime($resultGateway))
    );
    $resultList = $resultService->teacherResults(1, 7, 1, 20, null, null);
    $singleResult = $resultService->teacherResult(
        '00000000-0000-4000-8000-000000000123',
        7,
        'report'
    );
    $evaluateRequests = array_values(array_filter(
        $resultGateway->authorizationRequests,
        static fn (AddonContextAccessRequestV1 $request): bool => $request->operation() === 'evaluate'
    ));
    ojMlContextAssert(
        ($resultList['items'][0]['context_may_evaluate'] ?? null) === false
            && ($singleResult['context_may_evaluate'] ?? null) === false
            && count($evaluateRequests) === 2
            && array_reduce(
                $evaluateRequests,
                static fn (bool $valid, AddonContextAccessRequestV1 $request): bool =>
                    $valid && $request->learnerUserId() === null,
                true
            ),
        'REPORT list/single reads must stay available and fail closed when Context evaluate returns ' . $evaluateDenialCode . '.',
        ['list' => $resultList, 'single' => $singleResult]
    );
}

$evaluateAllowedDatabase = ojMlResultDatabase();
$evaluateAllowedGateway = new OjMlContextGatewayStub();
$evaluateAllowedService = new \OJML\Playgrounds\Application\AssignmentService(
    new \OJML\Playgrounds\Database\Repository($evaluateAllowedDatabase),
    new ContextService(ojMlReadyRuntime($evaluateAllowedGateway))
);
$evaluateAllowedList = $evaluateAllowedService->teacherResults(1, 7, 1, 20, null, null);
$evaluateAllowedSingle = $evaluateAllowedService->teacherResult(
    '00000000-0000-4000-8000-000000000123',
    7,
    'report'
);
ojMlContextAssert(
    ($evaluateAllowedList['items'][0]['context_may_evaluate'] ?? null) === true
        && ($evaluateAllowedSingle['context_may_evaluate'] ?? null) === true,
    'REPORT list/single reads must expose the internal evaluate marker only when Context evaluate succeeds.',
    ['list' => $evaluateAllowedList, 'single' => $evaluateAllowedSingle]
);
$failureDatabase = new wpdb();
$failureDatabase->assignmentRows = ojMlAssignmentRows(2);
$failureGateway = new OjMlContextGatewayStub();
$failureGateway->teachingPages[''] = ojMlTeachingScopePage();
$failureGateway->authorization = static fn (AddonContextAccessRequestV1 $request): AddonContextAccessDecisionV1 =>
    new AddonContextAccessDecisionV1(false, 'addon_context_unavailable', null, 'corr-failed');
$failureService = new \OJML\Playgrounds\Application\AssignmentService(
    new \OJML\Playgrounds\Database\Repository($failureDatabase),
    new ContextService(ojMlReadyRuntime($failureGateway))
);
$failureStatus = null;
try {
    $failureService->reportAssignmentOptions(7);
} catch (Problem $problem) {
    $failureStatus = $problem->httpStatus();
}
ojMlContextAssert(
    $failureStatus === 503 && count($failureGateway->authorizationRequests) === 1,
    'Authorization infrastructure failures must be rethrown instead of cached or skipped.',
    $failureStatus
);

$limitDatabase = new wpdb();
$limitDatabase->assignmentRows = ojMlAssignmentRows(1);
$limitDatabase->totalOverride = 10001;
$limitGateway = new OjMlContextGatewayStub();
$limitGateway->teachingPages[''] = ojMlTeachingScopePage();
$limitService = new \OJML\Playgrounds\Application\AssignmentService(
    new \OJML\Playgrounds\Database\Repository($limitDatabase),
    new ContextService(ojMlReadyRuntime($limitGateway))
);
$limitCode = null;
$limitStatus = null;
try {
    $limitService->reportAssignmentOptions(7);
} catch (Problem $problem) {
    $limitCode = $problem->problemCode();
    $limitStatus = $problem->httpStatus();
}
ojMlContextAssert(
    $limitCode === 'OJ_ML_ASSIGNMENT_SCAN_LIMIT'
        && $limitStatus === 503
        && $limitGateway->authorizationRequests === [],
    'Oversized report-option scans must fail closed before per-item authorization.',
    ['code' => $limitCode, 'status' => $limitStatus]
);

$driftGateway = new OjMlContextGatewayStub();
$driftContext = new ContextService(ojMlReadyRuntime($driftGateway));
$historical = ojMlAssignmentRows(1)[0];
$historical['context_revision'] = 'old-snapshot-revision';
$driftContext->assertAssignmentAccess(7, $historical, 'report', 22);
$driftRequest = end($driftGateway->authorizationRequests);
ojMlContextAssert($driftRequest->expectedRevision() === null && $driftRequest->learnerUserId() === null,
    'Historical teacher authorization must use current context rights without rechecking removed learner membership.');
$driftContext->assertAssignmentAccess(22, $historical, 'participate', 22);
$driftRequest = end($driftGateway->authorizationRequests);
ojMlContextAssert($driftRequest->expectedRevision() === null && $driftRequest->learnerUserId() === 22,
    'New participation must still authorize the current learner.');
fwrite(STDOUT, "OJ ML ContextService release regression passed.\n");
