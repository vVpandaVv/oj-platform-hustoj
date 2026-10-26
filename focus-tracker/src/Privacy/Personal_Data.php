<?php
declare(strict_types=1);

namespace FocusTracker\Privacy;

use FocusTracker\Database\Installer;
use FocusTracker\Support\Diagnostics;
use FocusTracker\Support\Request_Limiter;
use FocusTracker\Support\Audit_Log;
use FocusTracker\Jobs\Retention_Cleanup;
use WP_Error;

/** WordPress-approved, account-scoped export/erasure; shared schedules survive. */
final class Personal_Data {
    private const PAGE_SIZE = 100;

    public static function register(): void {
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'exporters'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'erasers'));
        add_action('delete_user', array(__CLASS__, 'delete_user'));
        add_action('deleted_user', array(__CLASS__, 'delete_user'));
        add_action('wpmu_delete_user', array(__CLASS__, 'delete_user'));
    }

    public static function exporters(array $exporters): array {
        $exporters['focus-tracker'] = array('exporter_friendly_name' => 'Focus Tracker 학습 기록', 'callback' => array(__CLASS__, 'export_data'));
        return $exporters;
    }

    public static function erasers(array $erasers): array {
        $erasers['focus-tracker'] = array('eraser_friendly_name' => 'Focus Tracker 학습 기록', 'callback' => array(__CLASS__, 'erase_data'));
        return $erasers;
    }

    public static function export_data(string $email, int $page = 1): array|WP_Error {
        $user = get_user_by('email', $email);
        if (! $user) { return array('data' => array(), 'done' => true); }
        if ($page < 1 || $page > 1000000) { return self::failed(); }
        $offset = ($page - 1) * self::PAGE_SIZE;
        $remaining = self::PAGE_SIZE;
        $data = array();
        try {
            foreach (self::datasets((int) $user->ID) as $kind => $set) {
                $count = self::count($set);
                if ($offset >= $count) { $offset -= $count; continue; }
                $rows = self::rows($set, $remaining, $offset);
                $offset = 0;
                foreach ($rows as $row) {
                    if ($kind === 'checks') { $row = self::export_check($row); }
                    $fields = array(array('name' => '기록 종류', 'value' => $set['label']));
                    foreach ($row as $name => $value) {
                        $fields[] = array('name' => esc_html($name), 'value' => esc_html($value === null ? '—' : (is_scalar($value) ? (string) $value : wp_json_encode($value, JSON_UNESCAPED_UNICODE))));
                    }
                    $identity = implode('-', array_map(static fn (string $key): string => (string) ($row[$key] ?? ''), $set['keys']));
                    $data[] = array('group_id' => 'focus-tracker-learning-records', 'group_label' => 'Focus Tracker 학습 기록', 'item_id' => 'focus-tracker-' . $kind . '-' . $identity, 'data' => $fields);
                }
                $remaining -= count($rows);
                if ($remaining === 0) { return array('data' => $data, 'done' => false); }
            }
            $audit = Audit_Log::for_user((int) $user->ID);
            $metadata = array();
            foreach ($audit as $index => $row) { $metadata[] = array('id' => 'audit-' . $index, 'name' => '본인 설정·일정 변경 이력', 'value' => $row); }
            $cutoff = Privacy_Guard::cutoff((int) $user->ID);
            if ($cutoff === PHP_INT_MAX) { return self::failed(); }
            if ($cutoff > 0) { $metadata[] = array('id' => 'protection', 'name' => '개인정보 삭제 보호상태·삭제 시각', 'value' => array('state' => Privacy_Guard::state((int) $user->ID), 'discardEventsThroughMs' => $cutoff)); }
            foreach (array_slice($metadata, $offset, $remaining) as $row) {
                $data[] = array('group_id' => 'focus-tracker-learning-records', 'group_label' => 'Focus Tracker 학습 기록', 'item_id' => 'focus-tracker-' . $row['id'], 'data' => array(array('name' => $row['name'], 'value' => esc_html(wp_json_encode($row['value'], JSON_UNESCAPED_UNICODE)))));
            }
            if (count($metadata) > $offset + $remaining) { return array('data' => $data, 'done' => false); }
            return array('data' => $data, 'done' => true);
        } catch (\Throwable $error) { return self::failed(); }
    }

    /** Each WP erasure request removes at most 100 rows, with retryable rollback. */
    public static function erase_data(string $email, int $page = 1): array|WP_Error {
        $user = get_user_by('email', $email);
        if (! $user) { return self::erased(false, true); }
        $lease = Retention_Cleanup::acquire_lock();
        if ($lease === null) { return self::failed(); }
        try { return self::erase_page((int) $user->ID); }
        finally { Retention_Cleanup::release_lock($lease); }
    }

    private static function erase_page(int $user_id): array|WP_Error {
        global $wpdb;
        if (false === $wpdb->query('START TRANSACTION')) { return self::failed(); }
        $remaining = self::PAGE_SIZE;
        $removed = false;
        try {
            if (! Privacy_Guard::lock_for_erasure($user_id) || ! Privacy_Guard::set_state($user_id, 'erasing')) { throw new \RuntimeException('privacy_lock'); }
            foreach (self::datasets($user_id) as $set) {
                $rows = self::rows($set, $remaining, 0);
                foreach ($rows as $row) {
                    $where = array_intersect_key($row, array_flip($set['keys']));
                    if (count($where) !== count($set['keys']) || false === $wpdb->delete($set['table'], $where)) { throw new \RuntimeException('privacy_delete'); }
                    $removed = true;
                }
                $remaining -= count($rows);
                if ($remaining === 0) { break; }
            }
            $done = true;
            foreach (self::datasets($user_id) as $set) { if (self::count($set) > 0) { $done = false; break; } }
            if ($done) {
                $audit = Audit_Log::for_user($user_id);
                if (count($audit) > $remaining) { $done = false; }
                elseif ($audit !== array()) {
                    if (! Audit_Log::erase_user($user_id)) { throw new \RuntimeException('privacy_audit_delete'); }
                    $removed = true;
                }
            }
            if ($done && (! Request_Limiter::erase_user($user_id) || ! Privacy_Guard::set_state($user_id, 'open'))) { throw new \RuntimeException('privacy_limit_delete'); }
            if (false === $wpdb->query('COMMIT')) { throw new \RuntimeException('privacy_commit'); }
            return self::erased($removed, $done, true);
        } catch (\Throwable $error) { $wpdb->query('ROLLBACK'); return self::failed(); }
    }

    /** Core calls this before deleting the account; storage failure aborts it. */
    public static function delete_user(int $user_id): void {
        if (! self::erase_user($user_id)) { throw new \RuntimeException('Focus Tracker 개인정보를 삭제하지 못해 사용자 삭제를 중단했습니다. 다시 시도해 주세요.'); }
    }

    public static function erase_user(int $user_id): bool {
        if ($user_id < 1) { return false; }
        $lease = Retention_Cleanup::acquire_lock();
        if ($lease === null) { return false; }
        try { return self::erase_account($user_id); }
        finally { Retention_Cleanup::release_lock($lease); }
    }

    private static function erase_account(int $user_id): bool {
        global $wpdb;
        if (false === $wpdb->query('START TRANSACTION')) { return false; }
        try {
            if (! Privacy_Guard::lock_for_erasure($user_id) || ! Privacy_Guard::set_state($user_id, 'erasing')) { throw new \RuntimeException('privacy_lock'); }
            // Parent rows remain until both child tables have been removed.
            // No activity is deleted, including shared/teacher-owned schedules.
            $p = Installer::participations_table();
            foreach (array(Installer::events_table(), Installer::activity_links_table()) as $table) {
                if (false === $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE participation_id IN (SELECT id FROM {$p} WHERE user_id = %d)", $user_id))) { throw new \RuntimeException('privacy_children'); }
            }
            foreach (array(Installer::checks_table(), Installer::check_states_table(), Installer::scores_table(), Installer::statistics_table(), $p) as $table) {
                if (false === $wpdb->delete($table, array('user_id' => $user_id))) { throw new \RuntimeException('privacy_rows'); }
            }
            if (! Request_Limiter::erase_user($user_id) || ! Audit_Log::erase_user($user_id) || ! Privacy_Guard::set_state($user_id, 'deleted') || false === $wpdb->query('COMMIT')) { throw new \RuntimeException('privacy_commit'); }
            return true;
        } catch (\Throwable $error) { $wpdb->query('ROLLBACK'); Diagnostics::record('check', 'store'); return false; }
    }

    /** Child-first order also keeps paginated erasure free from orphan rows. */
    private static function datasets(int $user_id): array {
        global $wpdb;
        $p = Installer::participations_table();
        $own = $wpdb->prepare('p.user_id = %d', $user_id);
        $direct = $wpdb->prepare('r.user_id = %d', $user_id);
        $out = array();
        foreach (array(
            'events' => array(Installer::events_table(), '활동 이벤트'),
            'links' => array(Installer::activity_links_table(), '활동 연결'),
        ) as $kind => [$table, $label]) {
            $out[$kind] = array('table' => $table, 'from' => "{$table} r JOIN {$p} p ON p.id = r.participation_id", 'where' => $own, 'keys' => $kind === 'links' ? array('participation_id', 'activity_id') : array('id'), 'label' => $label);
        }
        foreach (array(
            'participations' => array($p, '참여 세션'), 'scores' => array(Installer::scores_table(), '활동별 집계'),
            'checks' => array(Installer::checks_table(), '자동 확인 문항·응답'), 'states' => array(Installer::check_states_table(), '문항 발급 상태'),
            'daily' => array(Installer::statistics_table(), '일별 누적 집계'),
        ) as $kind => [$table, $label]) {
            $out[$kind] = array('table' => $table, 'from' => "{$table} r", 'where' => $direct, 'keys' => $kind === 'states' ? array('user_id') : array('id'), 'label' => $label);
        }
        return $out;
    }

    private static function count(array $set): int {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$set['from']} WHERE {$set['where']}");
        if ($count === null || '' !== (string) $wpdb->last_error) { throw new \RuntimeException('privacy_read'); }
        return (int) $count;
    }

    private static function rows(array $set, int $limit, int $offset): array {
        global $wpdb;
        $order = implode(', ', array_map(static fn (string $key): string => 'r.' . $key, $set['keys']));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT r.* FROM {$set['from']} WHERE {$set['where']} ORDER BY {$order} LIMIT %d OFFSET %d", $limit, $offset), ARRAY_A);
        if (! is_array($rows) || '' !== (string) $wpdb->last_error) { throw new \RuntimeException('privacy_read'); }
        return $rows;
    }

    private static function export_check(array $row): array {
        $candidate = json_decode((string) ($row['candidate_json'] ?? ''), true);
        // Export the learner's shown question and response, not the private
        // answer key/verifier proof, including for a still-pending question.
        $row['candidate_json'] = is_array($candidate) ? array_intersect_key($candidate, array_flip(array('kind', 'prompt', 'codeSnippet', 'assignments', 'input', 'choices', 'sourceKind', 'sourceHash'))) : null;
        return $row;
    }

    private static function erased(bool $removed, bool $done, bool $protected = false): array {
        return array('items_removed' => $removed, 'items_retained' => $protected, 'messages' => $protected && $done ? array('학습 기록은 모두 삭제했습니다. 이전 브라우저 기록의 재저장을 막기 위한 비공개 보호키와 삭제 시각은 유지합니다.') : array(), 'done' => $done);
    }
    private static function failed(): WP_Error { return new WP_Error('focus_tracker_privacy_failed', 'Focus Tracker 개인정보 처리를 완료하지 못했습니다. 다시 시도해 주세요.', array('status' => 503)); }
}
