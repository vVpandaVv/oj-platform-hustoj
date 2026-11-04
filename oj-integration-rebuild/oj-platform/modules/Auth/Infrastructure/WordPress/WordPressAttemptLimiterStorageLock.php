<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AttemptLimiterStorageLock;
use RuntimeException;

/** WordPress transient timeout/value rows and the index share this short lock. */
final class WordPressAttemptLimiterStorageLock implements AttemptLimiterStorageLock
{
    public function synchronized(callable $operation): mixed
    {
        $database = $GLOBALS['wpdb'] ?? null;
        if (! is_object($database) || ! method_exists($database, 'get_var') || ! method_exists($database, 'prepare')) {
            throw new RuntimeException('요청 횟수 제한 저장소를 잠그지 못했습니다.');
        }
        $name = $database->get_var('SELECT DATABASE()');
        $options = (string) ($database->options ?? '');
        if (! is_string($name) || $name === '' || $options === '') {
            throw new RuntimeException('요청 횟수 제한 저장소를 잠그지 못했습니다.');
        }
        // MySQL named locks are server-scoped, so isolate both database and
        // WordPress table prefix. No account identifier enters the lock name.
        $key = 'oj_auth_rate_' . substr(hash('sha256', $name . "\0" . $options), 0, 48);
        if ((string) $database->get_var($database->prepare('SELECT GET_LOCK(%s, 2)', $key)) !== '1') {
            throw new RuntimeException('요청 횟수 제한 저장소를 잠그지 못했습니다.');
        }
        try {
            return $operation();
        } finally {
            if ((string) $database->get_var($database->prepare('SELECT RELEASE_LOCK(%s)', $key)) !== '1') {
                throw new RuntimeException('요청 횟수 제한 저장소의 잠금을 해제하지 못했습니다.');
            }
        }
    }
}
