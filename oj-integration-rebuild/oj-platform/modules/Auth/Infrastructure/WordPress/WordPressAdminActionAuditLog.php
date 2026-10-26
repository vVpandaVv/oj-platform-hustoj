<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AdminActionAuditLog;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

/**
 * Stores a capped, rolling audit trail in a single WordPress option instead
 * of a new platform table. Only actor/scope/target/action/result/timestamp
 * are recorded — never plaintext secrets or other PII.
 */
final class WordPressAdminActionAuditLog implements AdminActionAuditLog
{
    private const OPTION = 'oj_platform_admin_audit_log';
    private const MAX_ENTRIES = 500;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function record(
        int $actorUserId,
        string $scope,
        int $targetUserId,
        string $action,
        string $result
    ): void {
        if (! function_exists('get_option') || ! function_exists('update_option')) {
            return;
        }

        $entries = $this->read();
        $entries[] = [
            'actor_user_id' => $actorUserId,
            'scope' => $scope,
            'target_user_id' => $targetUserId,
            'action' => $action,
            'result' => $result,
            'recorded_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES);
        }

        update_option(self::OPTION, $entries, false);
    }

    public function recent(int $limit = 100): array
    {
        $entries = array_reverse($this->read());

        return array_slice($entries, 0, max(0, $limit));
    }

    /**
     * @return list<array{
     *   actor_user_id: int,
     *   scope: string,
     *   target_user_id: int,
     *   action: string,
     *   result: string,
     *   recorded_at: string
     * }>
     */
    private function read(): array
    {
        if (! function_exists('get_option')) {
            return [];
        }
        $value = get_option(self::OPTION, []);
        if (! is_array($value)) {
            return [];
        }

        $entries = [];
        foreach ($value as $entry) {
            if (
                ! is_array($entry)
                || ! isset(
                    $entry['actor_user_id'],
                    $entry['scope'],
                    $entry['target_user_id'],
                    $entry['action'],
                    $entry['result'],
                    $entry['recorded_at']
                )
            ) {
                continue;
            }
            $entries[] = [
                'actor_user_id' => (int) $entry['actor_user_id'],
                'scope' => (string) $entry['scope'],
                'target_user_id' => (int) $entry['target_user_id'],
                'action' => (string) $entry['action'],
                'result' => (string) $entry['result'],
                'recorded_at' => (string) $entry['recorded_at'],
            ];
        }

        return $entries;
    }
}
