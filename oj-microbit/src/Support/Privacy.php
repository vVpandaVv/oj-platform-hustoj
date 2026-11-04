<?php
declare(strict_types=1);
namespace OjMicrobit\Support;

use OjMicrobit\Domain\ResultVisibility;

final class Privacy
{
    public const ERASE_BATCH = 100;
    // Each audience table has at most 50 links per exercise: two parent keys
    // bound writes to 100 rows per link table (200 combined for stale links) without scanning or deleting an entire teacher history.
    public const ERASE_EXERCISE_BATCH = 2;
    private const ERASE_HOOK = 'oj_microbit_erase_user';
    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', static function (array $items): array {
            $items['oj-microbit'] = ['exporter_friendly_name' => '마이크로비트 프로젝트와 채점 기록', 'callback' => [self::class, 'exportData']]; return $items;
        });
        add_filter('wp_privacy_personal_data_erasers', static function (array $items): array {
            $items['oj-microbit'] = ['eraser_friendly_name' => '마이크로비트 프로젝트와 채점 기록', 'callback' => [self::class, 'eraseData']]; return $items;
        });
        add_action('delete_user', [self::class, 'eraseUser']);
        add_action('wpmu_delete_user', [self::class, 'eraseUser']);
        add_action(self::ERASE_HOOK, [self::class, 'eraseUser']);
    }
    public static function exportData(string $email, int $page = 1): array|\WP_Error
    {
        $user = get_user_by('email', $email);
        if (!$user) { return ['data' => [], 'done' => true]; }
        global $wpdb;
        // WordPress supplies a page number, not a cursor. Skip covering keys
        // only, and hydrate one potentially multi-MiB record per table.
        $data = []; $done = true; $offset = max(0, $page - 1);
        foreach (['projects', 'lesson_projects', 'lesson_results', 'attempts', 'exercises', 'assignments'] as $name) {
            $table = '`' . str_replace('`', '``', $wpdb->prefix . 'oj_microbit_' . $name) . '`';
            $key = in_array($name, ['exercises', 'assignments'], true) ? 'owner_id' : 'user_id';
            $order = $name === 'projects' ? 'exercise_id' : (in_array($name, ['lesson_projects', 'lesson_results'], true) ? 'assignment_id' : 'id');
            $rows = $wpdb->get_results($wpdb->prepare("SELECT record.* FROM {$table} record INNER JOIN"
                . " (SELECT {$order} FROM {$table} WHERE {$key}=%d ORDER BY {$order} LIMIT 1 OFFSET %d) page_keys"
                . " ON record.{$order}=page_keys.{$order} WHERE record.{$key}=%d", $user->ID, $offset, $user->ID), ARRAY_A);
            if (!is_array($rows) || (string) $wpdb->last_error !== '') { return new \WP_Error('oj_microbit_export_failed', '마이크로비트 기록을 내보내지 못했습니다.'); }
            if ($rows !== []) { $done = false; }
            foreach ($rows as $row) {
                if ($name === 'attempts') {
                    try {
                        $result = json_decode($row['result_json'], true, 64, JSON_THROW_ON_ERROR);
                        if (!is_array($result)) { throw new \RuntimeException('Invalid stored attempt.'); }
                        $row['result_json'] = wp_json_encode(ResultVisibility::learner($result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } catch (\Throwable) { return new \WP_Error('oj_microbit_export_failed', '마이크로비트 채점 기록을 내보내지 못했습니다.'); }
                }
                $data[] = ['group_id' => 'oj-microbit', 'group_label' => '마이크로비트 학습 기록',
                    'item_id' => 'oj-microbit-' . $name . '-' . ($row['id'] ?? $row['exercise_id'] ?? $row['assignment_id']),
                    'data' => [['name' => '기록 종류', 'value' => $name], ['name' => '저장 내용', 'value' => wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]];
            }
        }
        return ['data' => $data, 'done' => $done];
    }
    public static function eraseData(string $email, int $page = 1): array|\WP_Error
    {
        $user = get_user_by('email', $email);
        if (!$user) { return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true]; }
        try { $batch = self::eraseBatch((int) $user->ID); }
        catch (\Throwable) { return new \WP_Error('oj_microbit_erase_failed', '마이크로비트 기록을 삭제하지 못했습니다.'); }
        return ['items_removed' => $batch['removed'] > 0, 'items_retained' => false, 'messages' => [], 'done' => !$batch['more']];
    }
    public static function eraseUser(int $userId): void
    {
        if ($userId < 1) { return; }
        try {
            global $wpdb;
            $limits = '`' . str_replace('`', '``', $wpdb->prefix . 'oj_microbit_limits') . '`';
            // Persist pending IDs beyond cron consumption or deactivation.
            // Reserved erase: keys never expire under quota timestamp cleanup.
            if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$limits} (scope_key,window_start,hits) VALUES (%s,%d,0)", 'erase:' . $userId, PHP_INT_MAX)) === false) {
                throw new \RuntimeException('Microbit privacy queue persistence failed.');
            }
            $batch = self::eraseBatch($userId);
            if ($batch['more']) { self::queueErasure($userId); }
            delete_option('oj_microbit_privacy_cleanup_error');
        } catch (\Throwable) {
            update_option('oj_microbit_privacy_cleanup_error', gmdate('c'), false);
            // A deleted account can no longer be looked up by email. Retain
            // its numeric ID in a bounded retry event until erasure succeeds.
            try { self::queueErasure($userId); } catch (\Throwable) { /* Site health reports the scheduling/storage failure. */ }
        }
    }
    public static function resumePendingErasure(): bool
    {
        global $wpdb;
        $limits = '`' . str_replace('`', '``', $wpdb->prefix . 'oj_microbit_limits') . '`';
        $key = $wpdb->get_var("SELECT scope_key FROM {$limits} WHERE scope_key LIKE 'erase:%' ORDER BY scope_key LIMIT 1");
        if ((string) $wpdb->last_error !== '') { throw new \RuntimeException('Microbit privacy queue query failed.'); }
        if ($key === null) { return false; }
        if (!is_string($key) || preg_match('/\Aerase:([1-9][0-9]*)\z/D', $key, $match) !== 1) { throw new \RuntimeException('Invalid privacy queue key.'); }
        self::eraseUser((int) $match[1]);
        return true;
    }
    private static function queueErasure(int $userId): void
    {
        $args = [$userId];
        if (!wp_next_scheduled(self::ERASE_HOOK, $args)
            && wp_schedule_single_event(time() + 60, self::ERASE_HOOK, $args) !== true) {
            throw new \RuntimeException('Microbit privacy retry scheduling failed.');
        }
    }
    /** One request never deletes more than ERASE_BATCH rows from each table. */
    public static function eraseBatch(int $userId): array
    {
        if ($userId < 1) { throw new \InvalidArgumentException('Valid user ID required.'); }
        global $wpdb;
        // Match WpdbRepository::withRunLease exactly. Do not declare erasure
        // complete while an already running grade can still commit a record.
        $runLock = 'oj_microbit_' . substr(hash('sha256', $wpdb->prefix . ':' . $userId), 0, 36);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $runLock)) !== '1') {
            return ['removed' => 0, 'more' => true];
        }
        try { return self::eraseUnlockedBatch($userId); }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $runLock)); }
    }

    private static function eraseUnlockedBatch(int $userId): array
    {
        global $wpdb;
        $more = false; $removed = 0;
        foreach (['projects', 'lesson_projects', 'lesson_results', 'attempts', 'exercises', 'assignments'] as $name) {
            $table = '`' . str_replace('`', '``', $wpdb->prefix . 'oj_microbit_' . $name) . '`';
            $scope = in_array($name, ['exercises', 'assignments'], true) ? 'owner_id' : 'user_id';
            $primary = $name === 'projects' ? 'exercise_id' : (in_array($name, ['lesson_projects', 'lesson_results'], true) ? 'assignment_id' : 'id');
            // No OFFSET: the next request selects the next remaining keys.
            $batchLimit = $name === 'exercises' ? self::ERASE_EXERCISE_BATCH : self::ERASE_BATCH;
            $rows = $wpdb->get_results($wpdb->prepare("SELECT {$primary} FROM {$table} WHERE {$scope}=%d LIMIT %d", $userId, $batchLimit), ARRAY_A);
            if (!is_array($rows) || (string) $wpdb->last_error !== '') { throw new \RuntimeException('Microbit erasure key query failed.'); }
            if ($rows === []) { continue; }
            $more = $more || count($rows) === $batchLimit;
            $keys = array_map(static fn (array $row): int => (int) $row[$primary], $rows);
            $slots = implode(',', array_fill(0, count($keys), '%d'));
            if ($name === 'exercises') {
                $removed += self::eraseExercises($wpdb, $table, $userId, $keys);
                continue;
            }
            if ($name === 'assignments') {
                // Creator attribution is personal data, not the authority for the lesson. Preserve other learners' results and class management access.
                $changed = $wpdb->query($wpdb->prepare("UPDATE {$table} SET owner_id=0,revision=revision+1 WHERE owner_id=%d AND id IN ({$slots})", $userId, ...$keys));
                if ($changed === false) { throw new \RuntimeException('Microbit assignment attribution erasure failed.'); }
                $removed += $changed; continue;
            }
            // Scope is checked again so another user's project with the same
            // exercise ID is never deleted by the composite-key operation.
            $changed = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE {$scope}=%d AND {$primary} IN ({$slots})", $userId, ...$keys));
            if ($changed === false) { throw new \RuntimeException('Microbit erasure failed.'); }
            $removed += $changed;
        }
        $limits = '`' . str_replace('`', '``', $wpdb->prefix . 'oj_microbit_limits') . '`';
        $changed = $wpdb->query($wpdb->prepare("DELETE FROM {$limits} WHERE scope_key=%s", 'run:' . $userId));
        if ($changed === false) { throw new \RuntimeException('Microbit limit erasure failed.'); }
        $removed += $changed;
        if (!$more && $wpdb->query($wpdb->prepare("DELETE FROM {$limits} WHERE scope_key=%s", 'erase:' . $userId)) === false) {
            throw new \RuntimeException('Microbit privacy queue completion failed.');
        }
        return ['removed' => $removed, 'more' => $more];
    }

    private static function eraseExercises(object $wpdb, string $table, int $userId, array $keys): int
    {
        foreach (['exercises', 'exercise_classes', 'exercise_groups'] as $name) {
            $engine = $wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s', $wpdb->prefix . 'oj_microbit_' . $name), ARRAY_A);
            if (!is_array($engine) || count($engine) !== 1 || (string) $wpdb->last_error !== '' || strcasecmp((string) ($engine[0]['Engine'] ?? ''), 'InnoDB') !== 0) {
                throw new \RuntimeException('Microbit erasure requires transactional storage.');
            }
        }
        if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('Microbit erasure transaction failed.'); }
        try {
            $slots = implode(',', array_fill(0, count($keys), '%d'));
            // The same parent rows are locked by exercise CAS updates. Hold
            // these locks through link + parent deletion so a concurrent save
            // cannot insert a new assignment between the two deletes.
            $owned = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$table} WHERE owner_id=%d AND id IN ({$slots}) ORDER BY id FOR UPDATE", $userId, ...$keys), ARRAY_A);
            if (!is_array($owned) || (string) $wpdb->last_error !== '') { throw new \RuntimeException('Microbit erasure lock failed.'); }
            $changed = 0;
            if ($owned !== []) {
                $lockedKeys = array_map(static fn (array $row): int => (int) $row['id'], $owned);
                $slots = implode(',', array_fill(0, count($lockedKeys), '%d'));
                foreach (['exercise_classes', 'exercise_groups'] as $name) {
                    $links = '`' . str_replace('`', '``', $wpdb->prefix . 'oj_microbit_' . $name) . '`';
                    if ($wpdb->query($wpdb->prepare("DELETE FROM {$links} WHERE exercise_id IN ({$slots})", ...$lockedKeys)) === false) {
                        throw new \RuntimeException('Microbit audience assignment erasure failed.');
                    }
                }
                $changed = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE owner_id=%d AND id IN ({$slots})", $userId, ...$lockedKeys));
                if ($changed === false) { throw new \RuntimeException('Microbit exercise erasure failed.'); }
            }
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Microbit erasure commit failed.'); }
            return $changed;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }
}
