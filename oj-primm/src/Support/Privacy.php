<?php
declare(strict_types=1);
namespace OjPrimm\Support;

use OjPrimm\Core\Plugin;
use Throwable;

final class Privacy
{
    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'erasers']);
        add_action('delete_user', [self::class, 'deleteUser']);
        add_action('wpmu_delete_user', [self::class, 'deleteUser']);
    }
    public static function exporters(array $items): array
    {
        $items['oj-primm'] = ['exporter_friendly_name' => 'OJ PRIMM 학습 기록', 'callback' => [self::class, 'exportData']];
        return $items;
    }
    public static function erasers(array $items): array
    {
        $items['oj-primm'] = ['eraser_friendly_name' => 'OJ PRIMM 학습 기록', 'callback' => [self::class, 'eraseData']];
        return $items;
    }
    public static function exportData(string $email, int $page = 1): array|\WP_Error
    {
        $user = get_user_by('email', $email);
        if (! $user) { return ['data' => [], 'done' => true]; }
        try {
            $records = Plugin::repository()->exportUser((int) $user->ID, $page);
            $data = [];
            foreach (['activities', 'generations', 'attempts', 'events', 'practices', 'usage'] as $type) {
                if (! isset($records[$type])) { continue; }
                foreach ($records[$type] as $row) {
                    $data[] = ['group_id' => 'oj-primm', 'group_label' => 'OJ PRIMM 학습 기록',
                        'item_id' => 'oj-primm-' . $type . '-' . $row['id'],
                        'data' => [['name' => '기록 종류', 'value' => $type], ['name' => '저장 자료',
                            'value' => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]];
                }
            }
            return ['data' => $data, 'done' => $records['done']];
        } catch (Throwable) { return new \WP_Error('oj_primm_export_failed', 'PRIMM 개인 기록을 내보내지 못했습니다.'); }
    }
    public static function eraseData(string $email, int $page = 1): array|\WP_Error
    {
        $user = get_user_by('email', $email);
        try {
            if ($user) { Plugin::repository()->eraseUser((int) $user->ID); }
            return ['items_removed' => (bool) $user, 'items_retained' => false, 'messages' => [], 'done' => true];
        } catch (Throwable) { return new \WP_Error('oj_primm_erase_failed', 'PRIMM 개인 기록을 삭제하지 못했습니다.'); }
    }
    public static function deleteUser(int $userId): void { Plugin::repository()->eraseUser($userId); }
}
