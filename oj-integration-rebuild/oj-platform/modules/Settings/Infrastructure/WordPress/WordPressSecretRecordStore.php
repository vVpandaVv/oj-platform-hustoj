<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretRecordStore;
use RuntimeException;

final class WordPressSecretRecordStore implements SecretRecordStore
{
    private const OPTION = 'oj_platform_secret_store';
    private const LOCK_OPTION = 'oj_platform_secret_store_lock';

    public function put(string $recordId, array $record): void
    {
        $this->assertRecordId($recordId);
        $this->withLock(function () use ($recordId, $record): void {
            $store = $this->readStore();
            if (isset($store['records'][$recordId])) {
                throw new RuntimeException('비밀값 참조가 중복되었습니다.');
            }

            $store['records'][$recordId] = $record;
            $this->writeStore($store);
        });
    }

    public function find(string $recordId): ?array
    {
        $this->assertRecordId($recordId);
        $record = $this->readStore()['records'][$recordId] ?? null;

        return is_array($record) ? $record : null;
    }

    public function remove(string $recordId): void
    {
        $this->assertRecordId($recordId);
        $this->withLock(function () use ($recordId): void {
            $store = $this->readStore();
            if (! isset($store['records'][$recordId])) {
                return;
            }

            unset($store['records'][$recordId]);
            $this->writeStore($store);
        });
    }

    public function replace(string $recordId, array $record): void
    {
        $this->assertRecordId($recordId);
        $this->withLock(function () use ($recordId, $record): void {
            $store = $this->readStore();
            if (! isset($store['records'][$recordId])) {
                throw new RuntimeException('비밀값 참조가 없거나 사용할 수 없습니다.');
            }

            $store['records'][$recordId] = $record;
            $this->writeStore($store);
        });
    }

    public function recordIds(): array
    {
        return array_keys($this->readStore()['records']);
    }

    /**
     * @return array{revision: int, records: array<string, array<string, mixed>>}
     */
    private function readStore(): array
    {
        if (! function_exists('get_option')) {
            throw new RuntimeException('WordPress 옵션 저장소를 사용할 수 없습니다.');
        }

        $value = get_option(self::OPTION, []);
        $records = is_array($value) && is_array($value['records'] ?? null)
            ? array_filter($value['records'], 'is_array')
            : [];

        return [
            'revision' => is_array($value) ? max(0, (int) ($value['revision'] ?? 0)) : 0,
            'records' => $records,
        ];
    }

    /**
     * @param array{revision: int, records: array<string, array<string, mixed>>} $store
     */
    private function writeStore(array $store): void
    {
        if (! function_exists('update_option')) {
            throw new RuntimeException('WordPress 옵션 저장소를 사용할 수 없습니다.');
        }

        $store['revision']++;
        if (! update_option(self::OPTION, $store, false)) {
            $current = get_option(self::OPTION, null);
            if ($current !== $store) {
                throw new RuntimeException('암호화된 비밀값 저장소를 갱신하지 못했습니다.');
            }
        }
    }

    private function withLock(callable $callback): void
    {
        if (
            ! function_exists('add_option')
            || ! function_exists('delete_option')
            || ! function_exists('get_option')
        ) {
            throw new RuntimeException('WordPress 비밀값 잠금을 사용할 수 없습니다.');
        }

        $token = bin2hex(random_bytes(16));
        $acquired = false;
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $acquired = add_option(
                self::LOCK_OPTION,
                ['token' => $token, 'expires_at' => time() + 15],
                '',
                'no'
            );
            if ($acquired) {
                break;
            }

            $lock = get_option(self::LOCK_OPTION, null);
            if (is_array($lock) && (int) ($lock['expires_at'] ?? 0) < time()) {
                delete_option(self::LOCK_OPTION);
            }
            usleep(20000);
        }

        if (! $acquired) {
            throw new RuntimeException('다른 비밀값 변경이 진행 중입니다.');
        }

        try {
            $callback();
        } finally {
            $lock = get_option(self::LOCK_OPTION, null);
            if (is_array($lock) && hash_equals((string) ($lock['token'] ?? ''), $token)) {
                delete_option(self::LOCK_OPTION);
            }
        }
    }

    private function assertRecordId(string $recordId): void
    {
        if (
            preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
                $recordId
            ) !== 1
        ) {
            throw new RuntimeException('비밀값 레코드 식별자가 올바르지 않습니다.');
        }
    }
}
