<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);
namespace OjCodeLab\Support;
use OjCodeLab\Database\Installer;
use OjCodeLab\Database\Notebook_Repository;

final class Privacy {
    public static function register(): void {
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'exporters'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'erasers'));
        add_action('delete_user', array(__CLASS__, 'delete_user'));
    }
    public static function exporters(array $items): array {
        $items['oj-code-lab'] = array('exporter_friendly_name' => 'OJ Code Lab', 'callback' => array(__CLASS__, 'export'));
        return $items;
    }
    public static function erasers(array $items): array {
        $items['oj-code-lab'] = array('eraser_friendly_name' => 'OJ Code Lab', 'callback' => array(__CLASS__, 'erase'));
        return $items;
    }
    public static function export(string $email, int $page = 1): array {
        $user = get_user_by('email', $email);
        if (! $user || $page > 1) { return array('data' => array(), 'done' => true); }
        $book = Notebook_Repository::find_for_user((int) $user->ID);
        if (is_wp_error($book)) { throw new \RuntimeException('OJ Code Lab 노트북 내보내기에 실패했습니다. 다시 시도해 주세요.'); }
        if (null === $book['updatedAt']) { return array('data' => array(), 'done' => true); }
        return array('data' => array(array('group_id' => 'oj-code-lab', 'group_label' => 'OJ Code Lab', 'item_id' => 'notebook-' . $user->ID,
            'data' => array(array('name' => '노트북 (코드·텍스트·입력·출력)', 'value' => esc_html((string) wp_json_encode($book))))
        )), 'done' => true);
    }
    public static function erase(string $email, int $page = 1): array {
        $user = get_user_by('email', $email);
        $result = $user ? self::delete_user((int) $user->ID) : 0;
        return array('items_removed' => is_int($result) && $result > 0, 'items_retained' => false === $result,
            'messages' => false === $result ? array('노트북 삭제 또는 공유 자료의 작성자 연결 해제에 실패했습니다. 다시 시도해 주세요.') : array(), 'done' => true);
    }
    public static function delete_user(int $user_id): int|false {
        global $wpdb;
        $result = $wpdb->delete(Installer::notebooks_table(), array('user_id' => $user_id), array('%d'));
        // Shared teaching resources remain available, with their author unlinked.
        $unlinked = $wpdb->update(Installer::datasets_table(), array('created_by' => 0), array('created_by' => $user_id), array('%d'), array('%d'));
        return false === $result || false === $unlinked ? false : $result + $unlinked;
    }
}
