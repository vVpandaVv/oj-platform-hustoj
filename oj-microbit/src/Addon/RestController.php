<?php
declare(strict_types=1);
namespace OjMicrobit\Addon;

use DomainException;
use InvalidArgumentException;
use OjMicrobit\Core\Plugin;
use OjMicrobit\Support\PlatformAccess;
use OjMicrobit\Support\OperationError;
use OjMicrobit\Support\RequestContext;
use Throwable;
use WP_Error;
use WP_REST_Request;

final class RestController
{
    public static function registerRoutes(): void
    {
        $routes = [
            ['status', 'GET', 'status', false],
            ['public-exercises', 'GET', 'publicExercises', false], ['teacher/public-exercises', 'GET', 'teacherPublicExercises', true],
            ['teacher/classes/(?P<classId>\d+)/assignments', 'GET', 'teacherAssignments', true],
            ['teacher/classes/(?P<classId>\d+)/assignments', 'POST', 'saveAssignment', true],
            ['teacher/assignments/(?P<id>\d+)', 'GET', 'teacherAssignment', true], ['teacher/assignments/(?P<id>\d+)', 'DELETE', 'archiveAssignment', true],
            ['teacher/assignments/(?P<id>\d+)/results', 'GET', 'lessonResults', true],
            ['teacher/assignments/(?P<id>\d+)/attempts', 'GET', 'teacherLessonAttempts', true],
            ['teacher/assignments/(?P<id>\d+)/attempts/(?P<attemptId>\d+)', 'GET', 'teacherLessonAttempt', true],
            ['assignments', 'GET', 'assignments', false], ['assignments/(?P<id>\d+)', 'GET', 'assignment', false],
            ['assignments/(?P<id>\d+)/project', 'GET', 'lessonProject', false], ['assignments/(?P<id>\d+)/project', 'POST', 'saveLessonProject', false],
            ['assignments/(?P<id>\d+)/run', 'POST', 'lessonRun', false], ['assignments/(?P<id>\d+)/preview', 'POST', 'lessonPreview', false],
            ['assignments/(?P<id>\d+)/attempts', 'GET', 'lessonAttempts', false],
            ['assignments/(?P<id>\d+)/attempts/(?P<attemptId>\d+)', 'GET', 'lessonAttempt', false],
            ['teacher/groups', 'GET', 'teacherGroups', true], ['teacher/classes', 'GET', 'teacherClasses', true], ['teacher/exercises/review', 'POST', 'reviewExercise', true],
            ['teacher/exercises', 'GET', 'teacherExercises', true], ['teacher/exercises', 'POST', 'saveExercise', true],
            ['teacher/exercises/(?P<id>\d+)', 'GET', 'teacherExercise', true], ['teacher/exercises/(?P<id>\d+)', 'DELETE', 'deleteExercise', true],
            ['teacher/exercises/(?P<id>\d+)/attempts', 'GET', 'teacherAttempts', true],
            ['teacher/exercises/(?P<id>\d+)/attempts/(?P<attemptId>\d+)', 'GET', 'teacherAttempt', true],
            ['exercises', 'GET', 'exercises', false], ['exercises/(?P<id>\d+)', 'GET', 'exercise', false],
            ['exercises/(?P<id>\d+)/project', 'GET', 'project', false], ['exercises/(?P<id>\d+)/project', 'POST', 'saveProject', false],
            ['exercises/(?P<id>\d+)/run', 'POST', 'run', false], ['exercises/(?P<id>\d+)/preview', 'POST', 'preview', false],
            ['exercises/(?P<id>\d+)/attempts', 'GET', 'attempts', false],
            ['exercises/(?P<id>\d+)/attempts/(?P<attemptId>\d+)', 'GET', 'attempt', false],
        ];
        foreach ($routes as [$path, $method, $callback, $teacher]) {
            register_rest_route('oj-microbit/v1', '/' . $path, ['methods' => $method, 'callback' => [self::class, $callback],
                'permission_callback' => static fn (WP_REST_Request $request): bool|WP_Error => self::authorize($request, $teacher)]);
        }
    }
    public static function authorize(WP_REST_Request $request, bool $teacher): bool|WP_Error
    {
        RequestContext::begin();
        if (!is_user_logged_in()) { return self::error(new OperationError('unauthorized', 401, '로그인 후 다시 시도해 주세요.')); }
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) { return self::error(new OperationError('nonce_expired', 403, '페이지를 새로고침한 뒤 다시 시도해 주세요.')); }
        try { $access = new PlatformAccess(); $teacher ? $access->requireTeacher() : $access->requireLearner(); return true; }
        catch (OperationError $e) { return self::error($e); }
        catch (DomainException) { return self::error(new OperationError('access_denied', 403, '이 기능에 접근할 권한이 없습니다.')); }
    }
    public static function teacherGroups(WP_REST_Request $r): mixed { return self::teachingScopes($r, true); }
    public static function publicExercises(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->publicExercises(...self::page($r))); }
    public static function teacherPublicExercises(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->publicExercises(...[...self::page($r), true])); }
    public static function assignments(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->assignments(...self::page($r))); }
    public static function teacherAssignments(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->teacherAssignments((int) $r['classId'], ...self::page($r))); }
    public static function saveAssignment(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->saveAssignment((int) $r['classId'], self::input($r))); }
    public static function archiveAssignment(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->archiveAssignment((int) $r['id'], self::input($r))); }
    public static function assignment(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->assignment((int) $r['id'])); }
    public static function teacherAssignment(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->assignment((int) $r['id'], true)); }
    public static function lessonProject(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->project((int) $r['id'])); }
    public static function saveLessonProject(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->saveProject((int) $r['id'], self::input($r))); }
    public static function lessonRun(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->run((int) $r['id'], self::input($r))); }
    public static function lessonPreview(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->preview((int) $r['id'], self::input($r))); }
    public static function lessonAttempts(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->attempts((int) $r['id'], false, ...self::page($r))); }
    public static function teacherLessonAttempts(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->attempts((int) $r['id'], true, ...self::page($r))); }
    public static function lessonAttempt(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->attempt((int) $r['id'], (int) $r['attemptId'])); }
    public static function teacherLessonAttempt(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::lessons()->attempt((int) $r['id'], (int) $r['attemptId'], true)); }
    public static function lessonResults(WP_REST_Request $r): mixed
    {
        return self::call(function () use ($r): array {
            $cursor = $r['cursor'] ?? ''; $context = $r['contextRevision'] ?? null; $roster = $r['rosterRevision'] ?? null;
            if (!is_string($cursor) || strlen($cursor) > 512 || ($context !== null && !is_string($context)) || ($roster !== null && !is_string($roster))) { throw new InvalidArgumentException('수업 명단의 페이지 상태를 확인해 주세요.'); }
            return Plugin::lessons()->results((int) $r['id'], $cursor, self::page($r)[1], $context, $roster);
        });
    }
    public static function teacherClasses(WP_REST_Request $r): mixed { return self::teachingScopes($r, false); }
    private static function teachingScopes(WP_REST_Request $r, bool $groups): mixed
    {
        return self::call(function () use ($r, $groups): array {
            $cursor = $r['cursor'] ?? '';
            if (!is_string($cursor) || strlen($cursor) > 2048 || preg_match('/[\x00-\x20\x7f]/', $cursor)) { throw new InvalidArgumentException('exercise.audience: Invalid cursor.'); }
            $raw = $r['limit'] ?? 20;
            $limit = is_int($raw) || is_string($raw) ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]) : false;
            if ($limit === false) { throw new InvalidArgumentException('exercise.audience: Invalid limit.'); }
            return $groups ? Plugin::service()->teacherGroups($cursor, $limit) : Plugin::service()->teacherClasses($cursor, $limit);
        });
    }
    public static function reviewExercise(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->reviewExercise(self::input($r))); }
    public static function teacherExercises(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->teacherExercises(...self::page($r))); }
    public static function teacherExercise(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->teacherExercise((int) $r['id'])); }
    public static function saveExercise(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->saveExercise(self::input($r))); }
    public static function deleteExercise(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->deleteExercise((int) $r['id'], self::input($r))); }
    public static function teacherAttempts(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->attempts((int) $r['id'], true, ...self::page($r))); }
    public static function teacherAttempt(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->attempt((int) $r['id'], (int) $r['attemptId'], true)); }
    public static function exercises(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->exercises(...self::page($r))); }
    public static function exercise(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->exercise((int) $r['id'])); }
    public static function project(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->project((int) $r['id'])); }
    public static function saveProject(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->saveProject((int) $r['id'], self::input($r))); }
    public static function run(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->run((int) $r['id'], self::input($r))); }
    public static function preview(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->preview((int) $r['id'], self::input($r))); }
    public static function attempts(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->attempts((int) $r['id'], false, ...self::page($r))); }
    public static function attempt(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->attempt((int) $r['id'], (int) $r['attemptId'])); }
    public static function status(WP_REST_Request $r): mixed { return self::call(fn () => Plugin::service()->status()); }
    private static function page(WP_REST_Request $r): array
    {
        $values = [];
        foreach (['beforeId' => [0, 0, PHP_INT_MAX], 'limit' => [20, 1, 50]] as $key => [$default, $minimum, $maximum]) {
            $raw = $r[$key] ?? $default;
            $value = is_int($raw) || is_string($raw) ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]) : false;
            if ($value === false) { throw new InvalidArgumentException('목록 기준 번호와 페이지 크기(1~50)를 확인해 주세요.'); }
            $values[] = $value;
        }
        return $values;
    }
    private static function input(WP_REST_Request $r): array
    {
        if (strlen($r->get_body()) > 1048576) { throw new InvalidArgumentException('요청 내용이 너무 큽니다. 프로젝트와 테스트 수를 줄여 주세요.'); }
        $data = $r->get_json_params();
        if (!is_array($data)) { throw new InvalidArgumentException('JSON 요청 내용을 확인해 주세요.'); }
        return $data;
    }
    private static function call(callable $operation): mixed
    {
        RequestContext::begin();
        $started = hrtime(true);
        try {
            $data = $operation();
            if (is_array($data) && in_array($data['status'] ?? null, ['graded', 'preview', 'compile_error', 'runtime_error'], true)) { $data['requestId'] = RequestContext::id(); }
            $response = rest_ensure_response($data);
            $response->header('Cache-Control', 'private, no-store');
            $response->header('X-Request-ID', RequestContext::id());
            return $response;
        } catch (OperationError $e) { return self::error($e, $started); }
        catch (InvalidArgumentException $e) { return self::error(self::inputError($e), $started); }
        catch (DomainException) { return self::error(new OperationError('rejected', 400, '요청을 처리할 수 없습니다. 최신 내용과 접근 권한을 확인해 주세요.'), $started); }
        catch (Throwable) { return self::error(new OperationError('unavailable', 503, '자료를 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.', 5), $started); }
    }
    private static function error(OperationError $error, ?int $started = null): WP_Error
    {
        RequestContext::log($error->errorCode, $error->httpStatus, $started === null ? 0 : (int) ((hrtime(true) - $started) / 1000000));
        return new WP_Error('oj_microbit_' . $error->errorCode, $error->getMessage(), $error->data());
    }
    private static function inputError(InvalidArgumentException $error): OperationError
    {
        $path = null;
        if (preg_match('/\A((?:exercise|project)(?:\.[A-Za-z][A-Za-z0-9]*|\.[0-9]{1,3}){0,12}):/', $error->getMessage(), $match)) {
            $known = ['exercise', 'project', 'id', 'title', 'description', 'status', 'revision', 'starterProject', 'starterMode', 'connections', 'pin', 'direction', 'signal', 'device', 'label', 'scenarios', 'name', 'visible', 'durationMs', 'inputs', 'atMs', 'value', 'assertions', 'expected', 'afterMs', 'withinMs', 'holdMs', 'tolerance', 'outputSequences', 'steps', 'i2cDevices', 'address', 'registers', 'register', 'writable', 'i2cInputs', 'i2cAssertions', 'operation', 'data', 'repeated', 'audience', 'mode', 'classIds', 'groupIds', 'publicationReview', 'dependencies', 'files', 'supportedTargets', 'preferredEditor', 'targetVersions', 'testFiles', 'public', 'version', 'core', 'radio', 'microphone', 'servo'];
            $valid = true;
            foreach (explode('.', $match[1]) as $part) { if (!in_array($part, $known, true) && !(ctype_digit($part) && (int) $part <= 255)) { $valid = false; break; } }
            if ($valid) { $path = $match[1]; }
        }
        $message = $path === 'exercise.publicationReview' ? '공개 전 점검을 다시 실행한 뒤 저장해 주세요.' : '입력한 항목의 값과 형식을 확인해 주세요.';
        if ($path !== null) {
            // Only exact schema-owned phrases are translated. A suffix with a
            // filename, source text, hidden value or upstream detail never matches.
            $detail = trim(substr($error->getMessage(), strlen($path) + 1));
            $guidance = [
                '응답 시간과 유지 시간이 시나리오 종료를 넘습니다.' => '반응 대기 시간과 유지 시간의 합이 실행 시간을 넘습니다. 두 시간을 줄이거나 단계의 실행 시간을 늘려 주세요.',
                '순서의 응답 시간과 유지 시간이 시나리오 종료를 넘습니다.' => '출력 순서의 반응 대기 시간과 유지 시간이 실행 시간을 넘습니다. 각 동작의 시간을 줄이거나 실행 시간을 늘려 주세요.',
                '모든 입력 핀에 0ms 초기값이 필요합니다.' => '각 입력 핀에 시작 시점(0ms)의 센서 값을 설정해 주세요.',
                '한 핀에 연결을 중복 설정할 수 없습니다.' => '하나의 핀에는 장치를 하나만 연결해 주세요. 겹친 연결의 핀 번호를 바꿔 주세요.',
                '아날로그 입력은 P0~P4, P10만 사용할 수 있습니다.' => '아날로그 센서는 P0~P4 또는 P10에 연결해 주세요.',
                '장치와 핀 방향 또는 신호 종류가 맞지 않습니다.' => '선택한 장치에 맞는 입력·출력 방향과 신호 종류를 선택해 주세요.',
                '선언된 핀 방향과 신호 종류에 맞지 않습니다.' => '연결 설정에 등록한 핀 번호·입력 또는 출력 방향·신호 종류와 같게 설정해 주세요.',
                '동작 순서는 선언된 출력 핀과 신호 종류에 맞아야 합니다.' => '출력 순서의 핀 번호와 신호 종류를 장치 연결 설정과 같게 선택해 주세요.',
                '점검 후 과제 내용이 바뀌었습니다. 최신 내용으로 공개 전 점검을 다시 진행해 주세요.' => '점검 후 과제 내용이 바뀌었습니다. 공개 전 점검을 다시 실행하고 최신 내용을 확인해 주세요.',
                '학생에게 공개하기 전에 배포 코드와 실험 예시를 확인해 주세요.' => '공개 전 점검에서 학생에게 배포할 코드와 실험 예시를 확인해 주세요.',
            ];
            $message = $guidance[$detail] ?? $message;
        }
        return new OperationError('invalid_input', 400, $message, null, null, $path);
    }
}
