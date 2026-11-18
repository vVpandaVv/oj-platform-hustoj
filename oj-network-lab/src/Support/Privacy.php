<?php
declare(strict_types=1);
namespace OjNetworkLab\Support;
use OjNetworkLab\Storage\Projects;
final class Privacy
{
    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', static function (array $items): array { $items['oj-network-lab'] = ['exporter_friendly_name' => '네트워크 실습 프로젝트', 'callback' => [self::class, 'export']]; return $items; });
        add_filter('wp_privacy_personal_data_erasers', static function (array $items): array { $items['oj-network-lab'] = ['eraser_friendly_name' => '네트워크 실습 프로젝트', 'callback' => [self::class, 'erase']]; return $items; });
        add_action('delete_user', [self::class, 'deleteUser']); add_action('wpmu_delete_user', [self::class, 'deleteUser']);
    }
    public static function export(string $email, int $page = 1): array|\WP_Error
    {
        $user = get_user_by('email', $email); $data = [];
        if (!$user || $page > 1) { return ['data' => [], 'done' => true]; }
        global $wpdb; $store = new Projects($wpdb);
        try {
            foreach ($store->list((int) $user->ID) as $item) {
                $row = $store->read((int) $user->ID, (int) $item['id']);
                $data[] = ['group_id' => 'oj-network-lab', 'group_label' => '네트워크 실습 프로젝트', 'item_id' => 'ojnl-' . $row['id'], 'data' => [['name' => '제목', 'value' => $row['title']], ['name' => '프로젝트 설정', 'value' => $row['project_json']], ['name' => '갱신 시각 UTC', 'value' => $row['updated_at']]]];
            }
            return ['data' => $data, 'done' => true];
        } catch (\Throwable) { return new \WP_Error('ojnl_privacy_export', '프로젝트를 내보내지 못했습니다.'); }
    }
    public static function erase(string $email, int $page = 1): array|\WP_Error
    {
        $user = get_user_by('email', $email);
        try { if ($user) { self::deleteUser((int) $user->ID); } return ['items_removed' => (bool) $user, 'items_retained' => false, 'messages' => [], 'done' => true]; }
        catch (\Throwable) { return new \WP_Error('ojnl_privacy_erase', '프로젝트를 삭제하지 못했습니다.'); }
    }
    public static function deleteUser(int $id): void { if (get_option('oj_network_lab_schema', '') !== '1') { return; } global $wpdb; (new Projects($wpdb))->erase($id); }
}
