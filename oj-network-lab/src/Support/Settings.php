<?php
declare(strict_types=1);
namespace OjNetworkLab\Support;
use OjNetworkLab\Storage\Installer;
final class Settings
{
    public static function register(): void
    {
        add_action('admin_menu', static function (): void { add_options_page('OJ Network Lab', 'OJ Network Lab', 'manage_options', 'oj-network-lab', [self::class, 'page']); });
        add_action('admin_init', static function (): void {
            register_setting('oj_network_lab', 'oj_network_lab_retention_days', ['type' => 'integer', 'default' => 0, 'sanitize_callback' => static fn ($v) => max(0, min(3650, (int) $v))]);
            register_setting('oj_network_lab', 'oj_network_lab_delete_on_uninstall', ['type' => 'boolean', 'default' => false, 'sanitize_callback' => static fn ($v) => (bool) $v]);
        });
        add_filter('site_status_tests', static function (array $tests): array { $tests['direct']['oj_network_lab'] = ['label' => '네트워크 실습 저장소', 'test' => [self::class, 'health']]; return $tests; });
        foreach (['oj_network_lab_retention_days', 'oj_network_lab_delete_on_uninstall'] as $option) { add_action('update_option_' . $option, static function (): void { \OjNetworkLab\Core\Plugin::audit('settings'); }, 10, 0); }
    }
    public static function retention(): int { return max(0, min(3650, (int) get_option('oj_network_lab_retention_days', 0))); }
    public static function health(): array
    {
        $ok = get_option('oj_network_lab_schema', '') === '1';
        return ['label' => $ok ? '네트워크 실습 저장소가 준비되었습니다.' : '네트워크 실습 저장소 준비가 필요합니다.', 'status' => $ok ? 'good' : 'critical', 'badge' => ['label' => 'OJ Network Lab', 'color' => 'blue'], 'description' => '<p>패킷 계산은 브라우저에서 수행하며 별도 Linux 실습 서버는 필요하지 않습니다.</p>', 'actions' => '', 'test' => 'oj_network_lab'];
    }
    public static function page(): void
    {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap"><h1>OJ Network Lab</h1><p>별도 실습 서버 없이 브라우저에서 실행합니다. 계정당 서버 프로젝트 20개, 장비 20개, 링크 40개입니다.</p><p>도구 → 사이트 건강에서 저장소 상태를 확인하세요. 역할·권한에서 학생에게 네트워크 실습 참여 권한을 배정하세요.</p><form method="post" action="options.php">';
        settings_fields('oj_network_lab');
        echo '<p><label for="ojnl-retention">서버 프로젝트 보관 일수 (0은 무기한, 최대 3650)</label> <input id="ojnl-retention" type="number" min="0" max="3650" name="oj_network_lab_retention_days" value="' . esc_attr((string) self::retention()) . '"></p>';
        echo '<p><label><input type="checkbox" name="oj_network_lab_delete_on_uninstall" value="1" ' . checked((bool) get_option('oj_network_lab_delete_on_uninstall', false), true, false) . '> 플러그인 삭제 시 이 사이트의 서버 프로젝트와 설정도 삭제</label></p><p>브라우저 초안은 실습 화면의 로컬 초안 삭제로 별도 정리합니다. 보관 기간이 지난 서버 기록은 매일 최대 500개씩 정리합니다.</p>';
        submit_button('설정 저장'); echo '</form></div>';
    }
}
