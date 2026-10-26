<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Security {
    final class Addon_Capabilities {
        public const MANAGE = 'manage_ml';
        public static function wordpress(string $key): string { return $key; }
    }
}
namespace OJML\Playgrounds\Ops {
    final class Options {
        public static bool $maintenance = false;
        public static function all(): array { return ['maintenanceMode' => self::$maintenance]; }
    }
}
namespace OJML\Playgrounds\Database {
    use OJML\Playgrounds\Domain\Problem;
    final class Repository {
        public array $attempt = [
            'uuid' => '11111111-1111-4111-8111-111111111111', 'status' => 'needs_resubmit',
            'evaluation' => ['id' => 17], 'artifact' => ['id' => 3, 'sha256' => 'unchanged'],
            'attempt_no' => 2, 'review' => ['score' => 80],
        ];
        public array $requests = [];
        public int $calls = 0;
        public bool $explode = false;
        public function queueReconsideration(string $uuid, int $actorId, int $expectedEvaluationId, string $reason, string $idempotencyKey, string $correlationId): array {
            $this->calls++;
            if ($this->explode) { throw new \RuntimeException('private database diagnostics must not escape'); }
            $payload = [$uuid, $actorId, $expectedEvaluationId, $reason];
            if (isset($this->requests[$idempotencyKey])) {
                if ($this->requests[$idempotencyKey] !== $payload) { throw new Problem('OJ_ML_RECONSIDERATION_KEY_CONFLICT', '다른 요청', 409); }
                return ['attempt' => $this->attempt, 'created' => false];
            }
            if ($this->attempt['status'] !== 'needs_resubmit') { throw new Problem('OJ_ML_ATTEMPT_NOT_REJECTED', '반려 아님', 409); }
            if ($this->attempt['evaluation']['id'] !== $expectedEvaluationId) { throw new Problem('OJ_ML_RECONSIDERATION_VERSION_CONFLICT', '검사 변경', 409); }
            $this->requests[$idempotencyKey] = $payload;
            $this->attempt['status'] = 'system_pending';
            return ['attempt' => $this->attempt, 'created' => true];
        }
    }
}
namespace OJML\Playgrounds\Application {
    use OJML\Playgrounds\Database\Repository;
    use OJML\Playgrounds\Domain\Problem;
    final class AssignmentService {
        public bool $allowed = true;
        public array $requests = [];
        public function __construct(private Repository $repository) {}
        public function teacherResult(string $uuid, int $actorId, string $operation): array {
            $this->requests[] = [$uuid, $actorId, $operation];
            if (!$this->allowed) { throw new Problem('OJ_ML_CONTEXT_FORBIDDEN', '현재 수업 권한 없음', 403); }
            if ($uuid !== $this->repository->attempt['uuid']) { throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '없음', 404); }
            return $this->repository->attempt;
        }
    }
}
namespace OJML\Playgrounds\Rest {
    final class Presenter {
        public function attempt(array $attempt, bool $teacher = false): array {
            return ['id' => $attempt['uuid'], 'status' => $attempt['status'], 'teacher' => $teacher];
        }
    }
    final class Routes { public const NAMESPACE = 'oj-ml/v1'; }
}
namespace {
    use OJML\Playgrounds\Application\AssignmentService;
    use OJML\Playgrounds\Application\ReconsiderationService;
    use OJML\Playgrounds\Database\Repository;
    use OJML\Playgrounds\Domain\Problem;
    use OJML\Playgrounds\Ops\Options;
    use OJML\Playgrounds\Rest\Permissions;
    use OJML\Playgrounds\Rest\Presenter;
    use OJML\Playgrounds\Rest\ReconsiderationRoute;

    final class WP_Error {
        public function __construct(private string $code, private string $message, public array $data = []) {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
    }
    final class WP_REST_Response {
        public array $headers = [];
        public function __construct(public array $data = [], public int $status = 200) {}
        public function header(string $name, string $value): void { $this->headers[$name] = $value; }
    }
    final class WP_REST_Server { public const CREATABLE = 'POST'; }
    /** @implements ArrayAccess<string,mixed> */
    final class WP_REST_Request implements ArrayAccess {
        public function __construct(private mixed $json = [], private string $nonce = 'valid', private string $uuid = '11111111-1111-4111-8111-111111111111') {}
        public function get_json_params(): mixed { return $this->json; }
        public function get_header(string $name): string { return strtolower($name) === 'x-wp-nonce' ? $this->nonce : ''; }
        public function get_param(string $name): mixed { return null; }
        public function offsetExists(mixed $offset): bool { return $offset === 'uuid'; }
        public function offsetGet(mixed $offset): mixed { return $offset === 'uuid' ? $this->uuid : null; }
        public function offsetSet(mixed $offset, mixed $value): void { throw new RuntimeException('read only'); }
        public function offsetUnset(mixed $offset): void { throw new RuntimeException('read only'); }
    }

    $actor = 7; $manage = true; $diagnosticThrows = false; $registered = [];
    function get_current_user_id(): int { return $GLOBALS['actor']; }
    function user_can(int $id, string $capability): bool { return $GLOBALS['manage'] && $id === 7 && $capability === 'manage_ml'; }
    function current_user_can(string $capability): bool { return user_can(get_current_user_id(), $capability); }
    function wp_verify_nonce(string $nonce, string $action): int|false { return $nonce === 'valid' && $action === 'wp_rest' ? 1 : false; }
    function sanitize_textarea_field(string $text): string { return trim(strip_tags($text)); }
    function wp_generate_uuid4(): string { return '33333333-3333-4333-8333-333333333333'; }
    function register_rest_route(string $namespace, string $route, array $args): void { $GLOBALS['registered'][] = [$namespace, $route, $args]; }
    function do_action(string $name, mixed ...$args): void { if ($GLOBALS['diagnosticThrows']) { throw new RuntimeException('diagnostic listener failure'); } }
    require_once __DIR__ . '/bootstrap.php';
    require_once dirname(__DIR__) . '/src/Domain/Problem.php';
    require_once dirname(__DIR__) . '/src/Application/ReconsiderationService.php';
    require_once dirname(__DIR__) . '/src/Rest/Permissions.php';
    require_once dirname(__DIR__) . '/src/Rest/ReconsiderationRoute.php';

    $checks = 0;
    function reconsiderAssert(bool $ok, string $label): void {
        global $checks;
        if (!$ok) { throw new RuntimeException($label); }
        $checks++;
    }
    function reconsiderReject(callable $call, string $code, int $status): void {
        try { $call(); } catch (Problem $problem) {
            reconsiderAssert($problem->problemCode() === $code && $problem->httpStatus() === $status, 'Expected error: ' . $code);
            return;
        }
        throw new RuntimeException('Expected rejection: ' . $code);
    }

    $input = ['reason' => '  서버 수정 후 원본을 다시 확인합니다.  ', 'expectedEvaluationId' => 17, 'idempotencyKey' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA'];
    $normal = ReconsiderationService::normalizeRequest($input);
    reconsiderAssert($normal['reason'] === '서버 수정 후 원본을 다시 확인합니다.', 'Reason is normalized for audit.');
    reconsiderAssert($normal['idempotencyKey'] === strtolower($input['idempotencyKey']), 'UUID keys have a canonical case.');
    reconsiderAssert(ReconsiderationService::normalizeRequest(array_replace($input, ['reason' => str_repeat('가', 300)]))['reason'] === str_repeat('가', 300), 'The reason limit counts Unicode characters.');
    foreach (['', ' ', '<b></b>', str_repeat('가', 301), "\xFF", "reason\x00"] as $reason) {
        reconsiderReject(fn () => ReconsiderationService::normalizeRequest(array_replace($input, ['reason' => $reason])), 'OJ_ML_RECONSIDERATION_REASON_INVALID', 400);
    }
    foreach ([null, [], true, 0, -1, 1.5, '1e3', 'not-an-id'] as $version) {
        reconsiderReject(fn () => ReconsiderationService::normalizeRequest(array_replace($input, ['expectedEvaluationId' => $version])), 'OJ_ML_RECONSIDERATION_VERSION_INVALID', 400);
    }
    foreach (['', 'reusable-fixed-key', str_repeat('a', 128), null, []] as $key) {
        reconsiderReject(fn () => ReconsiderationService::normalizeRequest(array_replace($input, ['idempotencyKey' => $key])), 'OJ_ML_RECONSIDERATION_KEY_INVALID', 400);
    }
    reconsiderReject(fn () => ReconsiderationService::normalizeRequest($input + ['acceptAnyway' => true]), 'OJ_ML_RECONSIDERATION_INPUT_INVALID', 400);

    $repository = new Repository(); $assignments = new AssignmentService($repository);
    $service = new ReconsiderationService($repository, $assignments); $uuid = $repository->attempt['uuid'];
    reconsiderReject(fn () => $service->reconsider($uuid, 0, $input, 'corr-test'), 'OJ_ML_AUTHENTICATION_REQUIRED', 401);
    $manage = false;
    reconsiderReject(fn () => $service->reconsider($uuid, 7, $input, 'corr-test'), 'OJ_ML_CAPABILITY_REQUIRED', 403);
    $manage = true; Options::$maintenance = true;
    reconsiderReject(fn () => $service->reconsider($uuid, 7, $input, 'corr-test'), 'OJ_ML_MAINTENANCE', 503);
    Options::$maintenance = false; $assignments->allowed = false;
    reconsiderReject(fn () => $service->reconsider($uuid, 7, $input, 'corr-test'), 'OJ_ML_CONTEXT_FORBIDDEN', 403);
    reconsiderAssert($repository->calls === 0, 'No repository mutation before global and current Context authorization.');
    $assignments->allowed = true;
    reconsiderReject(fn () => $service->reconsider($uuid, 7, array_replace($input, ['expectedEvaluationId' => 16]), 'corr-test'), 'OJ_ML_RECONSIDERATION_VERSION_CONFLICT', 409);
    $original = $repository->attempt;
    $first = $service->reconsider($uuid, 7, $input, 'corr-test');
    reconsiderAssert($first['created'] && $first['httpStatus'] === 202 && $first['attempt']['status'] === 'system_pending', 'First authorized request only queues inspection.');
    reconsiderAssert(end($assignments->requests)[2] === 'evaluate', 'Current class evaluate permission is explicitly checked.');
    reconsiderAssert($first['attempt']['context_may_evaluate'] === true, 'Response is marked only after successful Context evaluation authorization.');
    reconsiderAssert($repository->attempt['artifact'] === $original['artifact'] && $repository->attempt['evaluation'] === $original['evaluation']
        && $repository->attempt['review'] === $original['review'] && $repository->attempt['attempt_no'] === $original['attempt_no'], 'Service preserves original artifact, evaluation, review and attempt number.');
    $again = $service->reconsider($uuid, 7, $input, 'corr-retry');
    reconsiderAssert(!$again['created'] && $again['httpStatus'] === 200 && count($repository->requests) === 1, 'Pending request replay reaches durable idempotency handling.');
    $repository->attempt['status'] = 'accepted';
    reconsiderAssert($service->reconsider($uuid, 7, $input, 'corr-after-worker')['created'] === false, 'Replay remains idempotent after the worker completes.');
    reconsiderReject(fn () => $service->reconsider($uuid, 7, array_replace($input, ['reason' => '같은 키 다른 이유']), 'corr-test'), 'OJ_ML_RECONSIDERATION_KEY_CONFLICT', 409);
    reconsiderReject(fn () => $service->reconsider($uuid, 7, array_replace($input, ['idempotencyKey' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']), 'corr-test'), 'OJ_ML_ATTEMPT_NOT_REJECTED', 409);
    $assignments->allowed = false;
    reconsiderReject(fn () => $service->reconsider($uuid, 7, $input, 'corr-replay-denied'), 'OJ_ML_CONTEXT_FORBIDDEN', 403);
    $assignments->allowed = true;

    $route = new ReconsiderationRoute($service, new Presenter()); $route->register();
    reconsiderAssert($registered[0][2]['methods'] === 'POST' && str_ends_with($registered[0][1], '/reconsider'), 'Reconsideration is a distinct POST endpoint.');
    $permission = $registered[0][2]['permission_callback'];
    reconsiderAssert($permission === [Permissions::class, 'manageMutation'], 'A view/review permission is insufficient for reconsideration.');
    reconsiderAssert($permission(new WP_REST_Request($input)) === true, 'Valid manager mutation is authorized.');
    foreach (['', 'expired'] as $nonce) {
        $error = $permission(new WP_REST_Request($input, $nonce));
        reconsiderAssert($error instanceof WP_Error && $error->get_error_code() === 'OJ_ML_REST_NONCE_INVALID', 'Missing/expired nonce is denied.');
    }
    $manage = false;
    reconsiderAssert($permission(new WP_REST_Request($input)) instanceof WP_Error, 'Mutation permission denies non-managers.');
    $manage = true; $actor = 0;
    reconsiderAssert($permission(new WP_REST_Request($input))->get_error_code() === 'OJ_ML_AUTHENTICATION_REQUIRED', 'Anonymous route requests are denied.');
    $actor = 7; Options::$maintenance = true;
    reconsiderAssert($permission(new WP_REST_Request($input))->get_error_code() === 'OJ_ML_MAINTENANCE', 'Route enforces maintenance mode.');
    Options::$maintenance = false;
    $result = $route->reconsider(new WP_REST_Request($input));
    reconsiderAssert($result instanceof WP_REST_Response && $result->status === 200 && $result->data['data']['created'] === false, 'Route returns a safe idempotent result.');
    reconsiderAssert($result->headers['Cache-Control'] === 'no-store, private' && $result->headers['Vary'] === 'Cookie'
        && $result->headers['X-Content-Type-Options'] === 'nosniff', 'Private mutation response is not cacheable.');
    $invalidBody = $route->reconsider(new WP_REST_Request(null));
    reconsiderAssert($invalidBody instanceof WP_Error && $invalidBody->data['status'] === 400, 'Missing JSON body is rejected.');
    $repository->explode = true; $diagnosticThrows = true;
    $failure = $route->reconsider(new WP_REST_Request($input));
    reconsiderAssert($failure instanceof WP_Error && $failure->get_error_code() === 'OJ_ML_INTERNAL_ERROR'
        && !str_contains($failure->get_error_message(), 'private database'), 'Internal failures and diagnostic listener errors remain sanitized.');

    echo "Reconsideration service and REST regression passed ($checks checks).\n";
}
