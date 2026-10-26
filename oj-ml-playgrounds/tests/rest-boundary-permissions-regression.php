<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Security {
    final class Addon_Capabilities
    {
        public const USE = 'oj_ml_playgrounds_use';
        public const SUBMIT = 'oj_ml_playgrounds_submit';
        public const MANAGE = 'oj_ml_playgrounds_manage';
        public const REVIEW = 'oj_ml_playgrounds_review';
        public const REPORT = 'oj_ml_playgrounds_report';
        public const SETTINGS = 'oj_ml_playgrounds_settings';

        public static function wordpress(mixed $declaredCapability): string
        {
            return is_string($declaredCapability) ? $declaredCapability : '';
        }
    }
}

namespace {
    use OJML\Playgrounds\Rest\ArtifactPreviewRoute;
    use OJML\Playgrounds\Rest\Permissions;
    use OJML\Playgrounds\Rest\ReinspectionRoute;
    use OJML\Playgrounds\Rest\Presenter;
    use OJML\Playgrounds\Rest\Routes;

    final class WP_Error
    {
        /** @param array<string,mixed> $data */
        public function __construct(
            private readonly string $code,
            private readonly string $message = '',
            private readonly array $data = []
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }
    }

    final class WP_REST_Response
    {
        /** @param array<string,mixed> $data */
        public function __construct(public array $data = [], public int $status = 200)
        {
        }

        public function header(string $name, string $value): void
        {
        }
    }

    final class WP_REST_Server
    {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
        public const EDITABLE = 'POST, PUT, PATCH';
        public const DELETABLE = 'DELETE';
    }

    /** @implements ArrayAccess<string,mixed> */
    final class WP_REST_Request implements ArrayAccess
    {
        /**
         * @param array<string,mixed> $attributes
         * @param array<string,string> $headers
         * @param array<string,mixed> $params
         * @param array<string,mixed> $json
         */
        public function __construct(
            private array $attributes = [],
            private array $headers = [],
            private array $params = [],
            private array $json = []
        ) {
            $this->headers = array_change_key_case($this->headers, CASE_LOWER);
        }

        public function get_header(string $name): string
        {
            return $this->headers[strtolower($name)] ?? '';
        }

        public function get_param(string $name): mixed
        {
            return $this->params[$name] ?? null;
        }

        /** @return array<string,mixed> */
        public function get_json_params(): array
        {
            return $this->json;
        }

        /** @return array<string,mixed> */
        public function get_file_params(): array
        {
            return [];
        }

        public function offsetExists(mixed $offset): bool
        {
            return is_string($offset) && array_key_exists($offset, $this->attributes);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return is_string($offset) ? ($this->attributes[$offset] ?? null) : null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            if (is_string($offset)) {
                $this->attributes[$offset] = $value;
            }
        }

        public function offsetUnset(mixed $offset): void
        {
            if (is_string($offset)) {
                unset($this->attributes[$offset]);
            }
        }
    }

    $GLOBALS['oj_ml_test_user_id'] = 7;
    $GLOBALS['oj_ml_test_caps'] = [];
    $GLOBALS['oj_ml_test_routes'] = [];
    $GLOBALS['oj_ml_test_cache_user_calls'] = [];
    $GLOBALS['oj_ml_test_user_cache'] = [];
    $GLOBALS['oj_ml_test_user_db_misses'] = 0;

    function get_current_user_id(): int
    {
        return (int) $GLOBALS['oj_ml_test_user_id'];
    }

    function current_user_can(string $capability): bool
    {
        return ($GLOBALS['oj_ml_test_caps'][$capability] ?? false) === true;
    }

    /** @param list<int> $userIds */
    function cache_users(array $userIds): void
    {
        $GLOBALS['oj_ml_test_cache_user_calls'][] = $userIds;
        foreach ($userIds as $userId) {
            $GLOBALS['oj_ml_test_user_cache'][$userId] = (object) ['display_name' => '사용자 ' . $userId];
        }
    }

    function get_userdata(int $userId): object|false
    {
        if (!isset($GLOBALS['oj_ml_test_user_cache'][$userId])) {
            $GLOBALS['oj_ml_test_user_db_misses']++;
            $GLOBALS['oj_ml_test_user_cache'][$userId] = (object) ['display_name' => '사용자 ' . $userId];
        }
        return $GLOBALS['oj_ml_test_user_cache'][$userId];
    }
    function sanitize_text_field(string $value): string
    {
        return trim($value);
    }
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }

    function wp_create_nonce(string $action): string
    {
        return 'nonce-for-' . $action;
    }
    function wp_verify_nonce(string $nonce, string $action): int|false
    {
        return $nonce === 'valid-rest-nonce' && $action === 'wp_rest' ? 1 : false;
    }

    function wp_generate_uuid4(): string
    {
        return '00000000-0000-4000-8000-000000000099';
    }

    function do_action(string $hook, mixed ...$arguments): void
    {
        throw new RuntimeException('Diagnostic listener failure: ' . $hook);
    }

    function register_rest_route(string $namespace, string $route, array $arguments): void
    {
        $GLOBALS['oj_ml_test_routes'][$route] = $arguments;
    }

    require_once dirname(__DIR__) . '/src/Domain/Problem.php';
    require_once dirname(__DIR__) . '/src/Domain/AttemptStatus.php';
    require_once dirname(__DIR__) . '/src/Http/ArtifactDownload.php';
    require_once __DIR__ . '/bootstrap.php'; // classes the code under test now uses (Options, Rubric, PortfolioRequirements)
    require_once dirname(__DIR__) . '/src/Rest/Permissions.php';
    require_once dirname(__DIR__) . '/src/Rest/Presenter.php';
    require_once dirname(__DIR__) . '/src/Rest/Routes.php';
    require_once dirname(__DIR__) . '/src/Rest/ReinspectionRoute.php';
    require_once dirname(__DIR__) . '/src/Rest/ArtifactPreviewRoute.php';

    /** @param mixed $actual */
    function ojMlRestAssert(bool $condition, string $message, mixed $actual = null): void
    {
        if (!$condition) {
            throw new RuntimeException($message . ($actual === null ? '' : ' actual=' . var_export($actual, true)));
        }
    }

    $readRequest = new WP_REST_Request();
    $mutationRequest = new WP_REST_Request([], ['X-WP-Nonce' => 'valid-rest-nonce']);

    $GLOBALS['oj_ml_test_caps'] = ['oj_ml_playgrounds_submit' => true];
    ojMlRestAssert(Permissions::learnerRead($readRequest) === true, 'SUBMIT must imply learner read access.');
    ojMlRestAssert(Permissions::learnerMutation($mutationRequest) === true, 'SUBMIT plus a REST nonce must allow learner mutation.');

    $GLOBALS['oj_ml_test_caps'] = ['oj_ml_playgrounds_use' => true];
    ojMlRestAssert(Permissions::learnerRead($readRequest) === true, 'USE must retain learner read access.');
    $useOnlyMutation = Permissions::learnerMutation($mutationRequest);
    ojMlRestAssert(
        $useOnlyMutation instanceof WP_Error && $useOnlyMutation->get_error_code() === 'OJ_ML_CAPABILITY_REQUIRED',
        'USE alone must not authorize learner mutation.'
    );

    $GLOBALS['oj_ml_test_caps'] = ['oj_ml_playgrounds_submit' => true];
    $missingNonce = Permissions::learnerMutation(new WP_REST_Request());
    ojMlRestAssert(
        $missingNonce instanceof WP_Error && $missingNonce->get_error_code() === 'OJ_ML_REST_NONCE_INVALID',
        'SUBMIT mutations must still require a valid REST nonce.'
    );


    $GLOBALS['oj_ml_test_caps'] = ['oj_ml_playgrounds_review' => true];
    ojMlRestAssert(Permissions::artifactPreview($mutationRequest) === true, 'REVIEW plus a valid REST nonce must allow preview.');

    $previewMissingNonce = Permissions::artifactPreview(new WP_REST_Request());
    ojMlRestAssert(
        $previewMissingNonce instanceof WP_Error && $previewMissingNonce->get_error_code() === 'OJ_ML_REST_NONCE_INVALID',
        'Preview must reject a missing REST nonce.'
    );

    $GLOBALS['oj_ml_test_caps'] = ['oj_ml_playgrounds_report' => true];
    $reportPreview = Permissions::artifactPreview($mutationRequest);
    ojMlRestAssert(
        $reportPreview instanceof WP_Error && $reportPreview->get_error_code() === 'OJ_ML_CAPABILITY_REQUIRED',
        'REPORT-only actors must not read detailed submission content.'
    );
    ojMlRestAssert(Permissions::reportRead($readRequest) === true, 'REPORT must authorize result reads.');
    $GLOBALS['oj_ml_test_caps'] = ['oj_ml_playgrounds_review' => true];
    $reviewOnlyReport = Permissions::reportRead($readRequest);
    ojMlRestAssert(
        $reviewOnlyReport instanceof WP_Error && $reviewOnlyReport->get_error_code() === 'OJ_ML_CAPABILITY_REQUIRED',
        'REVIEW alone must not authorize report reads.'
    );
    $GLOBALS['oj_ml_test_caps'] = ['oj_ml_playgrounds_manage' => true];
    $manageOnlyReport = Permissions::reportRead($readRequest);
    ojMlRestAssert(
        $manageOnlyReport instanceof WP_Error && $manageOnlyReport->get_error_code() === 'OJ_ML_CAPABILITY_REQUIRED',
        'MANAGE alone must not authorize report reads.'
    );
    ojMlRestAssert(Permissions::manageRead($readRequest) === true, 'MANAGE must retain full assignment reads.');

    $registrationRoutes = (new ReflectionClass(Routes::class))->newInstanceWithoutConstructor();
    $registrationRoutes->register();
    $consoleDefinitions = $GLOBALS['oj_ml_test_routes']['/console/assignments'] ?? null;
    ojMlRestAssert(
        is_array($consoleDefinitions)
            && (($consoleDefinitions[0]['permission_callback'][1] ?? null) === 'manageRead'),
        'The full teacher assignment DTO route must require MANAGE.'
    );
    foreach (['unarchived', 'archived'] as $collectionStatus) {
        $collectionRequest = new WP_REST_Request([], [], ['status' => $collectionStatus]);
        $permission = $consoleDefinitions[0]['permission_callback'];
        foreach (['oj_ml_playgrounds_report', 'oj_ml_playgrounds_review', 'oj_ml_playgrounds_use'] as $insufficientCapability) {
            $GLOBALS['oj_ml_test_caps'] = [$insufficientCapability => true];
            $collectionDenied = $permission($collectionRequest);
            ojMlRestAssert($collectionDenied instanceof WP_Error
                && $collectionDenied->get_error_code() === 'OJ_ML_CAPABILITY_REQUIRED',
                'Archive collection filters must not grant MANAGE access to ' . $insufficientCapability . '.');
        }
        $GLOBALS['oj_ml_test_caps'] = ['oj_ml_playgrounds_manage' => true];
        ojMlRestAssert($permission($collectionRequest) === true,
            'MANAGE must retain read access to the ' . $collectionStatus . ' collection.');
    }
    $reportDefinition = $GLOBALS['oj_ml_test_routes']['/console/report-assignments'] ?? null;
    ojMlRestAssert(
        is_array($reportDefinition)
            && (($reportDefinition['permission_callback'][1] ?? null) === 'reportRead')
            && (($reportDefinition['callback'][1] ?? null) === 'reportAssignments'),
        'REPORT must receive a dedicated lean assignment chooser route.'
    );
    $optionsDefinition = $GLOBALS['oj_ml_test_routes']['/console/report-assignment-options'] ?? null;
    ojMlRestAssert(
        is_array($optionsDefinition)
            && (($optionsDefinition['permission_callback'][1] ?? null) === 'reportRead')
            && (($optionsDefinition['callback'][1] ?? null) === 'reportAssignmentOptions'),
        'The single-call result selector options route must require REPORT.'
    );    $attemptDefinition = $GLOBALS['oj_ml_test_routes']['/console/attempts/(?P<uuid>[0-9A-Fa-f-]{36})'] ?? null;
    ojMlRestAssert(
        is_array($attemptDefinition) && (($attemptDefinition['permission_callback'][1] ?? null) === 'reportRead'),
        'Single teacher attempt reads must require REPORT.'
    );

    $summary = (new Presenter())->assignmentSummary([
        'id' => 4,
        'title' => '결과 과제',
        'status' => 'closed',
        'description' => '숨김',
        'policy' => ['secret' => true],
    ]);
    ojMlRestAssert(
        $summary === ['id' => 4, 'title' => '결과 과제', 'status' => 'closed'],
        'Report assignment summaries must not expose teacher policy/context fields.',
        $summary
    );

    $draftFeedback = 'DRAFT-FEEDBACK-SENTINEL';
    $originalName = 'ORIGINAL-NAME-SENTINEL.mlpx';
    $attemptFixture = [
        'uuid' => '00000000-0000-4000-8000-000000000123',
        'assignment_id' => 4,
        'user_id' => 22,
        'attempt_no' => 2,
        'status' => 'accepted',
        'policy_revision' => 3,
        'client_app_version' => '0.12.1',
        'format_version' => 2,
        'progress' => ['phase' => 'done'],
        'failure' => null,
        'late' => false,
        'received_at' => '2026-09-01 00:10:00',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:10:00',
        'artifact' => [
            'uuid' => '10000000-0000-4000-8000-000000000123',
            'sha256' => str_repeat('a', 64),
            'original_name' => $originalName,
            'bytes' => 1024,
            'mime_type' => 'application/vnd.ojml.mlpx+zip',
            'created_at' => '2026-09-01 00:10:00',
        ],
        'evaluation' => null,
        'review' => [
            'version' => 2,
            'status' => 'draft',
            'score' => 9,
            'max_score' => 10,
            'feedback' => $draftFeedback,
            'rubric_revision' => 3,
            'created_at' => '2026-09-01 00:20:00',
            'released_at' => null,
            'reviewer_id' => 8,
        ],
        'released_review' => [
            'version' => 1,
            'status' => 'released',
            'score' => 8,
            'max_score' => 10,
            'feedback' => '공개 피드백',
            'rubric_revision' => 3,
            'created_at' => '2026-09-01 00:15:00',
            'released_at' => '2026-09-01 00:16:00',
            'reviewer_id' => 7,
        ],
    ];
    $presenter = new Presenter();

    $GLOBALS['oj_ml_test_caps'] = ['oj_ml_playgrounds_review' => true];
    $contextDeniedAttempt = $attemptFixture;
    $contextDeniedAttempt['context_may_evaluate'] = false;
    $contextDenied = $presenter->attempt($contextDeniedAttempt, true);
    ojMlRestAssert(
        ($contextDenied['canEvaluate'] ?? null) === false
            && !array_key_exists('originalName', $contextDenied['artifact'] ?? [])
            && !array_key_exists('download', $contextDenied['artifact'] ?? [])
            && ($contextDenied['review']['status'] ?? null) === 'released'
            && ($contextDenied['review']['score'] ?? null) === 8.0
            && ($contextDenied['review']['feedback'] ?? null) === ''
            && !array_key_exists('reviewerId', $contextDenied['review'] ?? [])
            && !str_contains((string) json_encode($contextDenied), $draftFeedback)
            && !str_contains((string) json_encode($contextDenied), $originalName)
            && !array_key_exists('context_may_evaluate', $contextDenied)
            && !array_key_exists('released_review', $contextDenied),
        'Context evaluate denial must return only a sanitized released-review report DTO.',
        $contextDenied
    );
    $contextDeniedRow = $presenter->resultRow([
        'user_id' => 22,
        'attempts_used' => 2,
        'context_may_evaluate' => false,
        'attempt' => $attemptFixture,
    ]);
    ojMlRestAssert(
        ($contextDeniedRow['canEvaluate'] ?? null) === false
            && ($contextDeniedRow['attempt']['canEvaluate'] ?? null) === false
            && ($contextDeniedRow['attempt']['review']['status'] ?? null) === 'released'
            && !str_contains((string) json_encode($contextDeniedRow), $draftFeedback),
        'Teacher result rows and single-attempt DTOs must share the same Context evaluate sanitization.',
        $contextDeniedRow
    );

    $GLOBALS['oj_ml_test_caps'] = ['oj_ml_playgrounds_report' => true];
    $globalDeniedAttempt = $attemptFixture;
    $globalDeniedAttempt['context_may_evaluate'] = true;
    $globalDenied = $presenter->attempt($globalDeniedAttempt, true);
    ojMlRestAssert(
        ($globalDenied['canEvaluate'] ?? null) === false
            && ($globalDenied['review']['status'] ?? null) === 'released'
            && !array_key_exists('originalName', $globalDenied['artifact'] ?? [])
            && !str_contains((string) json_encode($globalDenied), $draftFeedback),
        'Context evaluate alone must not bypass the global REVIEW/MANAGE capability.',
        $globalDenied
    );

    $GLOBALS['oj_ml_test_caps'] = ['oj_ml_playgrounds_review' => true];
    $allowedAttempt = $attemptFixture;
    $allowedAttempt['context_may_evaluate'] = true;
    $allowed = $presenter->attempt($allowedAttempt, true);
    ojMlRestAssert(
        ($allowed['canEvaluate'] ?? null) === true
            && ($allowed['artifact']['originalName'] ?? null) === $originalName
            && is_array($allowed['artifact']['download'] ?? null)
            && ($allowed['review']['status'] ?? null) === 'draft'
            && ($allowed['review']['feedback'] ?? null) === $draftFeedback
            && ($allowed['review']['reviewerId'] ?? null) === 8,
        'Global REVIEW/MANAGE plus Context evaluate must retain the full teacher DTO.',
        $allowed
    );

    $ownerAttempt = $attemptFixture;
    $ownerAttempt['review'] = $attemptFixture['released_review'];
    $ownerResult = $presenter->attempt($ownerAttempt);
    ojMlRestAssert(
        ($ownerResult['review']['feedback'] ?? null) === '공개 피드백'
            && !array_key_exists('reviewerId', $ownerResult['review'] ?? []),
        'Learner own-result DTOs must retain released feedback without exposing reviewer identity.',
        $ownerResult
    );
    $GLOBALS['oj_ml_test_cache_user_calls'] = [];
    $GLOBALS['oj_ml_test_user_cache'] = [];
    $GLOBALS['oj_ml_test_user_db_misses'] = 0;
    $cacheRows = array_map(
        static fn (int $userId): array => [
            'user_id' => $userId,
            'attempts_used' => 0,
            'context_may_evaluate' => false,
            'attempt' => null,
        ],
        range(1, 100)
    );
    $cacheRows[] = ['user_id' => 1, 'attempts_used' => 0, 'attempt' => null];
    $cacheRows[] = ['user_id' => 0, 'attempts_used' => 0, 'attempt' => null];
    $cacheRows[] = ['user_id' => -7, 'attempts_used' => 0, 'attempt' => null];
    $routeWithoutConstructor = (new ReflectionClass(Routes::class))->newInstanceWithoutConstructor();
    $primeResultUsers = new ReflectionMethod(Routes::class, 'primeResultUsers');
    $primeResultUsers->setAccessible(true);
    $primeResultUsers->invoke($routeWithoutConstructor, $cacheRows);
    foreach (array_slice($cacheRows, 0, 100) as $cacheRow) {
        $presenter->resultRow($cacheRow);
    }
    ojMlRestAssert(
        $GLOBALS['oj_ml_test_cache_user_calls'] === [range(1, 100)]
            && $GLOBALS['oj_ml_test_user_db_misses'] === 0,
        'One hundred cold result users must be bulk-primed once with no per-row user database misses.',
        [
            'cacheCalls' => $GLOBALS['oj_ml_test_cache_user_calls'],
            'databaseMisses' => $GLOBALS['oj_ml_test_user_db_misses'],
        ]
    );
    $body = new ReflectionMethod(Routes::class, 'body');
    $body->setAccessible(true);
    $deleteJson = $body->invoke(
        (new ReflectionClass(Routes::class))->newInstanceWithoutConstructor(),
        new WP_REST_Request(['id' => 4], [], [], ['expectedRevision' => 7])
    );
    ojMlRestAssert(($deleteJson['expectedRevision'] ?? null) === 7, 'DELETE must read expectedRevision from JSON.');

    $routesSource = file_get_contents(dirname(__DIR__) . '/src/Rest/Routes.php');
    ojMlRestAssert(is_string($routesSource), 'Routes source must be readable.');
    ojMlRestAssert(
        str_contains($routesSource, '$this->assignments->reportAssignments(')
            && str_contains($routesSource, '$this->presenter->assignmentSummary($item)')
            && str_contains($routesSource, "teacherResult((string) \$request['uuid'], \$this->actor(), 'report')")
            && str_contains($routesSource, 'deleteAssignment((int) $request[\'id\'], $revision, $this->actor())'),
        'Routes must use filtered report summaries, report context operation, and revision-guarded deletion.'
    );

    $reinspectionServiceSource = file_get_contents(dirname(__DIR__) . '/src/Application/ReinspectionService.php');
    ojMlRestAssert(
        is_string($reinspectionServiceSource)
            && substr_count($reinspectionServiceSource, "\$result['context_may_evaluate'] = true;") === 1
            && str_contains($reinspectionServiceSource, "->process(\$attemptUuid, \$actorId, \$correlationId)"),
        'Both successful reinspection result paths must preserve the already-authorized Context evaluate marker.'
    );
    $routes = (new ReflectionClass(Routes::class))->newInstanceWithoutConstructor();
    $run = new ReflectionMethod(Routes::class, 'run');
    $run->setAccessible(true);
    $routeError = $run->invoke($routes, static function (): array {
        throw new RuntimeException('Primary REST failure.');
    });
    ojMlRestAssert(
        $routeError instanceof WP_Error && $routeError->get_error_code() === 'OJ_ML_INTERNAL_ERROR',
        'A throwing diagnostic listener must not escape the main REST error boundary.'
    );

    $reinspection = (new ReflectionClass(ReinspectionRoute::class))->newInstanceWithoutConstructor();
    $reinspectionError = $reinspection->reinspect(new WP_REST_Request([
        'uuid' => '00000000-0000-4000-8000-000000000001',
    ]));
    ojMlRestAssert(
        $reinspectionError instanceof WP_Error && $reinspectionError->get_error_code() === 'OJ_ML_INTERNAL_ERROR',
        'A throwing diagnostic listener must not escape the reinspection REST error boundary.'
    );

    $previewRoute = (new ReflectionClass(ArtifactPreviewRoute::class))->newInstanceWithoutConstructor();
    $previewError = $previewRoute->preview(new WP_REST_Request([
        'uuid' => '00000000-0000-4000-8000-000000000001',
    ]));
    ojMlRestAssert(
        $previewError instanceof WP_Error && $previewError->get_error_code() === 'OJ_ML_INTERNAL_ERROR',
        'A throwing diagnostic listener must not escape the preview REST error boundary.'
    );
    fwrite(STDOUT, "OJ ML REST boundary and permissions regression passed.\n");
}

