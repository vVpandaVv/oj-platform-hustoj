<?php
declare(strict_types=1);

namespace OjCodeArena\Support;

use OjCodeArena\Database\Round_Repository;

/**
 * Tools → Export/Erase Personal Data: a learner's game records.
 */
final class Privacy {
    private const PER_PAGE = 100;

    public static function register(): void {
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'exporters'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'erasers'));
        add_action('admin_init', array(__CLASS__, 'policy_text'));
    }

    /** @param array<string,mixed> $list */
    public static function exporters(array $list): array {
        $list['oj-code-arena'] = array('exporter_friendly_name' => __('코드 아레나 경기 기록', 'oj-code-arena'), 'callback' => array(__CLASS__, 'export'));
        return $list;
    }

    /** @param array<string,mixed> $list */
    public static function erasers(array $list): array {
        $list['oj-code-arena'] = array('eraser_friendly_name' => __('코드 아레나 경기 기록', 'oj-code-arena'), 'callback' => array(__CLASS__, 'erase'));
        return $list;
    }

    /** @return array{data: list<array<string,mixed>>, done: bool} */
    public static function export(string $email, int $page = 1): array {
        $user = get_user_by('email', $email);
        if (! $user) {
            return array('data' => array(), 'done' => true);
        }
        $rows = (new Round_Repository())->for_user((int) $user->ID, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $data = array();
        foreach ($rows as $k => $r) {
            $data[] = array(
                'group_id' => 'oj-code-arena',
                'group_label' => __('코드 아레나 경기 기록', 'oj-code-arena'),
                'item_id' => 'oj-code-arena-' . $page . '-' . $k,
                'data' => array(
                    array('name' => __('날짜', 'oj-code-arena'), 'value' => $r['date']),
                    array('name' => __('게임', 'oj-code-arena'), 'value' => $r['title']),
                    array('name' => __('팀 순위', 'oj-code-arena'), 'value' => (string) $r['team_rank']),
                    array('name' => __('팀 진행(%)', 'oj-code-arena'), 'value' => (string) $r['team_pct']),
                    array('name' => __('푼 블록', 'oj-code-arena'), 'value' => (string) $r['solved']),
                    array('name' => __('보스', 'oj-code-arena'), 'value' => (string) $r['bosses']),
                ),
            );
        }
        return array('data' => $data, 'done' => count($rows) < self::PER_PAGE);
    }

    /** @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool} */
    public static function erase(string $email, int $page = 1): array {
        $user = get_user_by('email', $email);
        $removed = $user ? (new Round_Repository())->erase_user((int) $user->ID) : 0;
        return array('items_removed' => $removed > 0, 'items_retained' => false, 'messages' => array(), 'done' => true);
    }

    public static function policy_text(): void {
        if (function_exists('wp_add_privacy_policy_content')) {
            wp_add_privacy_policy_content(
                __('코드 아레나', 'oj-code-arena'),
                wp_kses_post(__('코드 아레나 게임을 하면 경기마다 학생의 사용자 번호, 표시 이름, 팀, 푼 블록 수와 보스 수가 저장됩니다. 경기 중에는 진행 상태와 마지막 접속 시각이 잠시 저장되고 몇 분 안에 지워집니다. 기록 보관 기간은 OJ 콘솔의 [교사 메뉴 › 코드 아레나 › 설정]에서 정할 수 있습니다.', 'oj-code-arena'))
            );
        }
    }
}
