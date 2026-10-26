<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProbeRunGuard;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use DomainException;
use RuntimeException;

final class WordPressAiProbeRunGuard implements AiProbeRunGuard
{
    private const LEASE_SECONDS = 90;
    private const COOLDOWN_SECONDS = 10;

    public function __construct(
        private readonly Clock $clock,
        private readonly ?object $database = null
    ) {
    }

    public function acquire(string $profileKey, int $actorUserId): string
    {
        $token = bin2hex(random_bytes(16));
        $this->mutate($profileKey, $actorUserId, function (array $state) use ($token): array {
            $now = $this->now();
            if ($this->running($state, $now)) {
                throw new DomainException('이 프로필의 AI 연결 검사가 이미 진행 중입니다. 완료 후 다시 시도해 주세요.');
            }
            $remaining = max(0, (int) ($state['retry_at'] ?? 0) - $now);
            if ($remaining > 0) {
                throw new DomainException('AI 연결 검사는 ' . $remaining . '초 후 다시 시도하거나 검사 대기를 초기화해 주세요.');
            }
            return ['token' => $token, 'expires_at' => $now + self::LEASE_SECONDS, 'retry_at' => 0];
        });

        return $token;
    }

    public function release(string $profileKey, int $actorUserId, string $token): void
    {
        $this->mutate($profileKey, $actorUserId, function (array $state) use ($token): ?array {
            if ($token === '' || ! hash_equals((string) ($state['token'] ?? ''), $token)) {
                return null;
            }
            return ['token' => '', 'expires_at' => 0, 'retry_at' => $this->now() + self::COOLDOWN_SECONDS];
        });
    }

    public function reset(string $profileKey, int $actorUserId): void
    {
        $this->mutate($profileKey, $actorUserId, function (array $state): ?array {
            if ($this->running($state, $this->now())) {
                throw new DomainException('진행 중인 AI 연결 검사는 초기화할 수 없습니다. 완료 후 다시 시도해 주세요.');
            }
            return $state === [] ? null : ['token' => '', 'expires_at' => 0, 'retry_at' => 0];
        });
    }

    public function status(string $profileKey, int $actorUserId): array
    {
        $state = $this->decode($this->read($this->key($profileKey, $actorUserId)));
        $now = $this->now();
        $running = $this->running($state, $now);
        return [
            'running' => $running,
            'retry_after' => max(0, (int) ($state[$running ? 'expires_at' : 'retry_at'] ?? 0) - $now),
        ];
    }

    /** @param callable(array): ?array $change */
    private function mutate(string $profileKey, int $actorUserId, callable $change): void
    {
        $key = $this->key($profileKey, $actorUserId);
        $db = $this->db();
        $table = $this->table();
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $before = $this->read($key);
            $next = $change($this->decode($before));
            if ($next === null) {
                return;
            }
            $after = json_encode($next, JSON_THROW_ON_ERROR);
            if ($before === $after) {
                return;
            }
            // Compare-and-swap against the DB row, not a potentially stale object cache.
            // A delayed release/reset must never replace a newer request's lease.
            $sql = $before === null
                ? $db->prepare("INSERT IGNORE INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, $after)
                : $db->prepare("UPDATE `{$table}` SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", $after, $key, $before);
            $changed = $db->query($sql);
            if ($changed === false) {
                throw new RuntimeException('AI 연결 검사 상태를 저장하지 못했습니다.');
            }
            if ($changed === 1) {
                if (function_exists('wp_cache_delete')) {
                    wp_cache_delete($key, 'options');
                }
                return;
            }
        }
        throw new DomainException('AI 연결 검사 상태가 변경되었습니다. 화면을 새로 고친 뒤 다시 시도해 주세요.');
    }

    private function read(string $key): ?string
    {
        $db = $this->db();
        $table = $this->table();
        $value = $db->get_var($db->prepare("SELECT option_value FROM `{$table}` WHERE option_name = %s", $key));
        if ((string) ($db->last_error ?? '') !== '') {
            throw new RuntimeException('AI 연결 검사 상태를 읽지 못했습니다.');
        }
        return is_string($value) ? $value : null;
    }

    private function decode(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        $state = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($state)) {
            throw new RuntimeException('AI 연결 검사 상태가 올바르지 않습니다.');
        }
        return $state;
    }

    private function running(array $state, int $now): bool
    {
        return ($state['token'] ?? '') !== '' && (int) ($state['expires_at'] ?? 0) > $now;
    }

    private function key(string $profileKey, int $actorUserId): string
    {
        if ($actorUserId < 1 || preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $profileKey) !== 1) {
            throw new DomainException('검사할 프로필 키를 먼저 입력해 주세요.');
        }
        return 'oj_platform_ai_probe_' . hash('sha256', $actorUserId . ':' . $profileKey);
    }

    private function db(): object
    {
        global $wpdb;
        $db = $this->database ?? ($wpdb ?? null);
        if (! is_object($db) || ! method_exists($db, 'prepare') || ! method_exists($db, 'query') || ! method_exists($db, 'get_var')) {
            throw new RuntimeException('AI 연결 검사 저장소를 사용할 수 없습니다.');
        }
        return $db;
    }

    private function table(): string
    {
        $table = (string) ($this->db()->options ?? '');
        if (preg_match('/^[a-zA-Z0-9_]+$/', $table) !== 1) {
            throw new RuntimeException('WordPress 설정 테이블이 올바르지 않습니다.');
        }
        return $table;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
