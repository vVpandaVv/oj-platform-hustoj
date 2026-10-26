<?php

declare(strict_types=1);

namespace OjPrimm\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use DomainException;
use InvalidArgumentException;
use OjPrimm\Core\Plugin;
use Throwable;
use WP_Error;
use WP_REST_Request;

final class RestController
{
    public static function registerRoutes(): void
    {
        $routes = [
            ['/teacher/contexts', 'GET', 'teacherContexts', 'teacher'],
            ['/learner/contexts', 'GET', 'learnerContexts', 'student'],
            ['/problems', 'GET', 'problems', 'teacher'],
            ['/teacher/activities', 'GET', 'teacherActivities', 'teacher'],
            ['/teacher/activities', 'POST', 'saveActivity', 'teacher'],
            ['/teacher/activities/(?P<id>\d+)/manage', 'GET', 'manageActivity', 'teacher'],
            ['/teacher/activities/(?P<id>\d+)/edit', 'POST', 'editActivity', 'teacher'],
            ['/teacher/activities/(?P<id>\d+)/publish', 'POST', 'publishActivity', 'teacher'],
            ['/teacher/activities/(?P<id>\d+)/withdraw', 'POST', 'withdrawActivity', 'teacher'],
            ['/teacher/activities/(?P<id>\d+)/clone', 'POST', 'cloneActivity', 'teacher'],
            ['/teacher/activities/(?P<id>\d+)/validate', 'POST', 'validateActivity', 'teacher'],
            ['/teacher/activities/(?P<id>\d+)/regenerate', 'POST', 'regenerateActivity', 'teacher'],
            ['/teacher/report', 'GET', 'report', 'teacher'],
            ['/teacher/report.csv', 'GET', 'exportReport', 'teacher'],
            ['/teacher/settings', 'GET', 'readSettings', 'teacher'],
            ['/teacher/settings', 'POST', 'saveSettings', 'teacher'],
            ['/teacher/generation-status', 'GET', 'generationStatus', 'teacher'],
            ['/teacher/generations', 'POST', 'startGeneration', 'teacher'],
            ['/teacher/generations/(?P<id>\d+)', 'GET', 'generation', 'teacher'],
            ['/teacher/generations/(?P<id>\d+)/retry', 'POST', 'retryGeneration', 'teacher'],
            ['/teacher/generations/(?P<id>\d+)/process', 'POST', 'processGeneration', 'teacher'],
            ['/teacher/generations/(?P<id>\d+)/cancel', 'POST', 'cancelGeneration', 'teacher'],
            ['/teacher/generations/(?P<id>\d+)/resume', 'POST', 'resumeGeneration', 'teacher'],
            ['/teacher/generations/(?P<id>\d+)/stop', 'POST', 'stopGeneration', 'admin'],
            ['/teacher/generations-summary', 'GET', 'generationSummary', 'teacher'],
            ['/activities', 'GET', 'activities', 'student'],
            ['/activities/(?P<id>\d+)', 'GET', 'activity', 'reader'],
            ['/activities/(?P<id>\d+)/progress', 'POST', 'progress', 'student'],
            ['/activities/(?P<id>\d+)/submission-watch', 'POST', 'submissionWatch', 'student'],
            ['/activities/(?P<id>\d+)/submission-launch', 'POST', 'submissionLaunch', 'student'],
            ['/activities/(?P<id>\d+)/submission-check', 'POST', 'submissionCheck', 'student'],
            ['/activities/(?P<id>\d+)/support', 'GET', 'support', 'reader'],
            ['/activities/(?P<id>\d+)/support/assemble', 'POST', 'assemble', 'student'],
            ['/activities/(?P<id>\d+)/practice', 'POST', 'practice', 'student'],
        ];
        foreach ($routes as [$path, $method, $callback, $role]) {
            register_rest_route('oj-primm/v1', $path, [
                'methods' => $method, 'callback' => [self::class, $callback],
                'permission_callback' => static fn (WP_REST_Request $request): bool|WP_Error => self::authorize($request, $role),
            ]);
        }
        if(function_exists('add_filter')){add_filter('rest_pre_serve_request',[self::class,'serveCsv'],10,4);}
    }
    public static function serveCsv(bool $served,mixed $result,mixed $request,mixed $server): bool
    {
        if(!$served&&$request->get_route()==='/oj-primm/v1/teacher/report.csv'&&$result instanceof \WP_REST_Response
            &&str_starts_with((string)($result->get_headers()['Content-Type']??''),'text/csv')){echo (string)$result->get_data();return true;}return $served;
    }

    public static function authorize(WP_REST_Request $request, string $role): bool|WP_Error
    {
        if (!is_user_logged_in() || !class_exists(AddonCapabilityKeyV1::class)) {
            return new WP_Error('oj_primm_login_required', '로그인 후 다시 시도해 주세요.', ['status' => 401]);
        }
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
            return new WP_Error('oj_primm_nonce_expired', '로그인 상태를 확인하려면 페이지를 새로고침해 주세요.', ['status' => 403]);
        }
        $teach = current_user_can(AddonCapabilityKeyV1::wordpress(Registration::TEACH))
            || (function_exists('is_super_admin') && is_super_admin());
        $learn = current_user_can(AddonCapabilityKeyV1::wordpress(Registration::LEARN));
        if (($role === 'teacher' && $teach) || ($role === 'student' && $learn)
            || ($role === 'admin' && current_user_can('manage_options'))
            || ($role === 'reader' && ($teach || $learn))) {
            return true;
        }
        return new WP_Error('oj_primm_forbidden', '이 PRIMM 활동을 이용할 권한이 없습니다.', ['status' => 403]);
    }

    public static function teacherContexts(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::service()->teachingContexts()); }
    public static function learnerContexts(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::service()->learningContexts()); }
    public static function problems(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::service()->searchProblems((string) $request->get_param('search'))); }
    public static function teacherActivities(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::service()->teacherActivities((string) $request->get_param('context_type'), (string) $request->get_param('context_id'),(string)($request->get_param('cursor')??''),(int)($request->get_param('limit')??25))); }
    public static function saveActivity(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::service()->saveActivity(self::input($request))); }
    public static function generationStatus(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::generation()->availability()); }
    public static function startGeneration(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::generation()->start(self::input($request))); }
    public static function generation(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::generation()->job((int) $request->get_param('id'))); }
    public static function retryGeneration(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::generation()->retry((int) $request->get_param('id'))); }
    public static function processGeneration(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::generation()->processForTeacher((int) $request->get_param('id'))); }
    public static function cancelGeneration(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::generation()->cancel((int) $request->get_param('id'))); }
    public static function resumeGeneration(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::generation()->resume((int) $request->get_param('id'))); }
    public static function stopGeneration(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::generation()->stop((int) $request->get_param('id'))); }
    public static function generationSummary(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::generation()->overview(current_user_can('manage_options'))); }
    public static function manageActivity(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::management()->manage((int) $request->get_param('id'))); }
    public static function editActivity(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::management()->edit((int) $request->get_param('id'),self::input($request))); }
    public static function publishActivity(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::management()->publish((int) $request->get_param('id'),self::input($request))); }
    public static function withdrawActivity(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::management()->withdraw((int) $request->get_param('id'),self::input($request))); }
    public static function cloneActivity(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::management()->cloneActivity((int) $request->get_param('id'),self::input($request))); }
    public static function validateActivity(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::generation()->validateActivity((int) $request->get_param('id'),self::input($request))); }
    public static function regenerateActivity(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::generation()->regenerate((int) $request->get_param('id'),self::input($request))); }
    private static function query(WP_REST_Request $request): array
    {
        $input=[];foreach(['context_type','context_id','activity_id','search','cursor','limit'] as $key){$value=$request->get_param($key);if($value!==null){$input[$key]=$value;}}return $input;
    }
    public static function report(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::management()->report(self::query($request))); }
    public static function exportReport(WP_REST_Request $request): mixed
    {
        return self::call(static function() use($request): mixed {
            $response=new \WP_REST_Response(Plugin::management()->exportCsv(self::query($request)),200);
            $response->header('Content-Type','text/csv; charset=UTF-8');
            $response->header('Content-Disposition','attachment; filename="primm-progress.csv"');return $response;
        });
    }
    public static function readSettings(WP_REST_Request $request): mixed { return self::call(fn()=>Plugin::curriculum()->get((string)$request->get_param('context_type'),(string)$request->get_param('context_id'))); }
    public static function saveSettings(WP_REST_Request $request): mixed { return self::call(fn()=>Plugin::curriculum()->save((string)$request->get_param('context_type'),(string)$request->get_param('context_id'),self::input($request))); }
    public static function activities(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::service()->studentActivities()); }
    public static function activity(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::service()->activity((int) $request->get_param('id'),is_string($request->get_param('stage'))?$request->get_param('stage'):null)); }
    public static function practice(WP_REST_Request $request): mixed { return self::call(fn()=>Plugin::practice()->run((int)$request->get_param('id'),self::input($request))); }
    public static function progress(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::service()->progress((int) $request->get_param('id'), self::input($request))); }
    public static function submissionWatch(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::service()->submissionWatch((int) $request->get_param('id'), self::input($request))); }
    public static function submissionLaunch(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::service()->submissionLaunch((int) $request->get_param('id'), self::input($request))); }
    public static function submissionCheck(WP_REST_Request $request): mixed { return self::call(fn () => Plugin::service()->submissionCheck((int) $request->get_param('id'), self::input($request))); }

    public static function support(WP_REST_Request $request): mixed
    {
        return self::call(fn () => Plugin::service()->support((int) $request->get_param('id')));
    }

    public static function assemble(WP_REST_Request $request): mixed
    {
        return self::call(fn () => Plugin::service()->assembleSupport((int) $request->get_param('id'), self::input($request)));
    }

    private static function input(WP_REST_Request $request): array
    {
        if (strlen($request->get_body()) > 262144) { throw new InvalidArgumentException('저장할 내용이 너무 큽니다.'); }
        $input = $request->get_json_params();
        if (!is_array($input)) { throw new InvalidArgumentException('저장할 요청을 확인해 주세요.'); }
        return $input;
    }

    private static function call(callable $operation): mixed
    {
        try {
            $response = rest_ensure_response($operation());
            $response->header('Cache-Control', 'private, no-store');
            return $response;
        } catch (DomainException|InvalidArgumentException $error) {
            $message = $error->getMessage();
            $status = preg_match('/권한|접근|소속/', $message) ? 403
                : (preg_match('/버전|최신|변경|다른 창/', $message) ? 409 : 400);
            return new WP_Error('oj_primm_request_rejected', $message, ['status' => $status]);
        } catch (Throwable) {
            return new WP_Error('oj_primm_unavailable', 'PRIMM 활동을 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.', ['status' => 503]);
        }
    }
}
