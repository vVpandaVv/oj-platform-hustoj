<?php
declare(strict_types=1);

namespace FocusTracker\Support;

use FocusTracker\Database\Installer;
use WP_Error;

/** Installation failures stay visible and never turn into partial tracking. */
final class Release_Readiness {
    public static function register(): void {
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_filter('site_status_tests', array(__CLASS__, 'site_tests'));
        add_filter('rest_pre_dispatch', array(__CLASS__, 'guard'), 10, 3);
    }

    public static function guard($result, $server, $request) {
        if ($result !== null || ! str_starts_with((string) $request->get_route(), '/focus-tracker/v1/')) { return $result; }
        if (! Installer::ready()) {
            return new WP_Error('focus_tracker_schema_unavailable', '집중도 기록 저장소를 준비하지 못해 기능을 중단했습니다. 관리자에게 설치 상태 확인을 요청해 주세요.', array('status' => 503));
        }
        return $result;
    }

    public static function notice(): void {
        if (! Capabilities::can_manage_settings() || Installer::ready()) { return; }
        echo '<div class="notice notice-error"><p>' . esc_html__('Focus Tracker 설치 또는 업그레이드가 완료되지 않았습니다. 집중도 평가 설정의 운영 상태에서 저장소·인덱스·InnoDB 상태를 확인해 주세요. 데이터는 삭제하지 않습니다.', 'focus-tracker') . '</p></div>';
    }

    public static function site_tests(array $tests): array {
        $tests['direct']['focus_tracker_storage'] = array('label' => 'Focus Tracker 저장소', 'test' => array(__CLASS__, 'site_test'));
        return $tests;
    }

    public static function site_test(): array {
        $health = Installer::inspect_schema();
        update_option('focus_tracker_schema_health', $health, false);
        $ready = $health['ready'] && get_option('focus_tracker_db_version') === FOCUS_TRACKER_VERSION;
        return array(
            'label' => $ready ? 'Focus Tracker 저장소가 준비되었습니다' : 'Focus Tracker 저장소 확인이 필요합니다',
            'status' => $ready ? 'good' : 'critical',
            'badge' => array('label' => 'Focus Tracker', 'color' => 'blue'),
            'description' => '<p>' . esc_html($ready ? '필수 컬럼, 중복 방지 인덱스와 InnoDB를 확인했습니다.' : '집중도 평가 설정의 운영 상태를 확인하고 DB 백업 후 업그레이드를 다시 실행해 주세요.') . '</p>',
            'actions' => '', 'test' => 'focus_tracker_storage',
        );
    }
}
