<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Ops;

use OJML\Playgrounds\Core\Composition_Root;
use OJML\Playgrounds\Database\Installer;
use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Database\SchemaUpgrade;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Inspection\MlpxInspector;
use OJML\Playgrounds\Rest\Permissions;
use OJML\Playgrounds\Security\Addon_Capabilities;
use OJML\Playgrounds\Storage\PrivateArtifactStore;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Management remains available when the add-on graph cannot boot. */
final class Bootstrap
{
    private readonly Diagnostics $diagnostics;
    public function __construct(Composition_Root $composition) { $this->diagnostics = new Diagnostics($composition); }

    public function register(): void
    {
        add_action('admin_post_oj_ml_migrate', [$this, 'migrate']);
        add_action('rest_api_init', [$this, 'routes'], 20);
        add_action('oj_ml_playgrounds_rest_exception', [Audit::class, 'log'], 10, 2);
        add_action('oj_ml_playgrounds_composition_failed', [Audit::class, 'log'], 10, 2);
        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['oj_ml_five_minutes'] = ['interval' => 300, 'display' => 'ML 제출 검사 복구 (5분)']; return $schedules;
        });
        add_action('init', static function (): void {
            if ((!function_exists('is_multisite') || !is_multisite()) && get_option(Installer::DB_VERSION_OPTION) === Installer::DB_VERSION && !wp_next_scheduled('oj_ml_recover')) {
                wp_schedule_event(time() + 300, 'oj_ml_five_minutes', 'oj_ml_recover');
            }
        });
        add_action('oj_ml_recover', function (): void {
            try { $this->worker()->recover(); } catch (Throwable $error) { Audit::log($error, wp_generate_uuid4()); }
        });
        add_filter('site_status_tests', function (array $tests): array {
            $tests['direct']['oj_ml_playgrounds'] = ['label' => 'ML 실습 운영 상태', 'test' => function (): array {
                $health = $this->diagnostics->collect();
                return ['label' => $health['ok'] ? 'ML 실습 운영 환경이 정상입니다.' : 'ML 실습 운영 환경을 확인하세요.',
                    'status' => $health['ok'] ? 'good' : 'critical', 'badge' => ['label' => 'OJ ML', 'color' => 'blue'],
                    'description' => '<p>DB, ZIP, 저장 한도, 검사 대기와 OJ 연결을 확인합니다.</p>',
                    'actions' => '<a href="' . esc_url(self::settingsUrl()) . '">ML 실습 설정</a>', 'test' => 'oj_ml_playgrounds'];
            }]; return $tests;
        });
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('oj-ml', new Commands($this));
        }
        (new Privacy())->register();
    }

    public function routes(): void
    {
        register_rest_route('oj-ml/v1', '/console/health', [
            'methods' => 'GET', 'permission_callback' => [$this, 'readPermission'],
            'callback' => fn (): WP_REST_Response => $this->response(['health' => $this->diagnostics->collect()]),
        ], true);
        register_rest_route('oj-ml/v1', '/console/settings', [
            ['methods' => 'GET', 'permission_callback' => [$this, 'readPermission'],
                'callback' => fn (): WP_REST_Response => $this->response(['settings' => Options::all()])],
            ['methods' => 'POST', 'permission_callback' => [$this, 'writePermission'],
                'callback' => function (WP_REST_Request $request): WP_REST_Response|WP_Error {
                    try {
                        Installer::maybeUpgrade();
                        $settingsLease = Lease::acquire('settings');
                        $before = Options::all();
                        $input = $request->get_json_params();
                        global $wpdb;
                        // Record intent before the option mutation, so an audit outage blocks changes.
                        (new Repository($wpdb))->insertEvent(null, null, get_current_user_id(), 'settings.change_requested',
                            ['before' => $before, 'requestedKeys' => is_array($input) ? array_keys(array_intersect_key($input, Options::DEFAULTS)) : []], wp_generate_uuid4());
                        $settings = Options::save(is_array($input) ? $input : []);
                        return $this->response(['settings' => $settings]);
                    } catch (Throwable $error) { return $this->error($error); }
                }],
        ]);
        register_rest_route('oj-ml/v1', '/console/recover', [
            'methods' => 'POST', 'permission_callback' => [$this, 'writePermission'],
            'callback' => function (): WP_REST_Response|WP_Error {
                try { return $this->response(['recovery' => $this->worker()->recover(10)]); }
                catch (Throwable $error) { return $this->error($error); }
            },
        ]);
    }

    public function readPermission(WP_REST_Request $request): true|WP_Error
    {
        return Permissions::settingsRead($request);
    }

    public function writePermission(WP_REST_Request $request): true|WP_Error
    {
        $read = $this->readPermission($request);
        if ($read instanceof WP_Error) { return $read; }
        $nonce = sanitize_text_field($request->get_header('X-WP-Nonce'));
        return wp_verify_nonce($nonce, 'wp_rest') !== false ? true
            : new WP_Error('OJ_ML_REST_NONCE_INVALID', '보안 토큰이 만료되었습니다. 다시 로그인하세요.', ['status' => 403]);
    }

    public function worker(): InspectionWorker
    {
        Installer::maybeUpgrade();
        global $wpdb;
        return new InspectionWorker(new Repository($wpdb), new PrivateArtifactStore(), new MlpxInspector());
    }

    public function health(): array { return $this->diagnostics->collect(); }

    public function migrate(): void
    {
        if (!Addon_Capabilities::current_user_can_manage_settings()) { wp_die('ML 실습 설정 권한이 필요합니다.', '', ['response' => 403]); }
        check_admin_referer('oj_ml_migrate');
        try { Installer::install(); $state = 'updated'; }
        catch (Throwable $error) { Audit::log($error, wp_generate_uuid4()); $state = 'failed'; }
        wp_safe_redirect(self::settingsUrl() . '?migration=' . $state);
        exit;
    }

    /** The console settings page (교사 메뉴 › ML 실습 › 설정) replaces the former wp-admin Tools page. */
    public static function settingsUrl(): string
    {
        return home_url('/oj-console/addons/oj-ml-playgrounds/settings');
    }

    private function response(array $data): WP_REST_Response
    {
        $response = new WP_REST_Response(['data' => $data]);
        $response->header('Cache-Control', 'no-store, private');
        $response->header('Vary', 'Cookie');
        return $response;
    }

    private function error(Throwable $error): WP_Error
    {
        $id = wp_generate_uuid4();
        Audit::log($error, $id);
        return ($error instanceof Problem ? $error : new Problem('OJ_ML_OPERATION_FAILED', '운영 작업에 실패했습니다. 오류 로그를 확인하세요.', 503))->toWpError($id);
    }
}
