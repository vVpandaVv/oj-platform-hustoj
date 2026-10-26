<?php
declare(strict_types=1);

namespace TeachingTools\Sessions;

use WP_Error;

/** Limits distinct failed guesses per account without blocking assigned lessons. */
final class Session_Code_Guard {
    private const MAX_ATTEMPTS = 10;
    private const WINDOW_SECONDS = 300;

    public static function check(int $user_id, string $code): true|WP_Error {
        if (self::known_session($user_id, $code, true)) {
            return true;
        }
        $attempts = self::attempts($user_id);
        if (count($attempts['codes']) >= self::MAX_ATTEMPTS) {
            return new WP_Error(
                'teaching_tools_too_many_attempts',
                __('잘못된 참여 코드가 반복 입력되었습니다. 배정된 수업은 바로 참여할 수 있으며, 다른 코드는 잠시 뒤 다시 입력해 주세요.', 'teaching-tools'),
                array('status' => 429, 'retryAfter' => max(1, $attempts['expiresAt'] - time()))
            );
        }

        return true;
    }

    public static function record_failed_attempt(int $user_id, string $code): void {
        if (self::known_session($user_id, $code, false)) {
            return;
        }
        $attempts = self::attempts($user_id);
        $fingerprint = hash('sha256', strtoupper(trim($code)));
        if (isset($attempts['codes'][$fingerprint])) {
            return;
        }
        if (count($attempts['codes']) < self::MAX_ATTEMPTS) {
            $attempts['codes'][$fingerprint] = true;
        }
        set_transient(self::key($user_id), $attempts, max(1, $attempts['expiresAt'] - time()));
    }

    private static function known_session(int $user_id, string $code, bool $allow_public_join): bool {
        $session = Tool_Session_Repository::find_by_code($code);
        if (null === $session) {
            return false;
        }
        if (null !== Tool_Session_Repository::host_role((int) $session['id'], $user_id)
            || Tool_Session_Repository::is_participant((int) $session['id'], $user_id)
        ) {
            return true;
        }

        return $allow_public_join && 'live' === (string) $session['mode']
            && 'active' === (string) $session['status']
            && (string) $session['expires_at'] > current_time('mysql', true)
            && '' === (string) ($session['context_type'] ?? '');
    }

    /** @return array{codes: array<string, bool>, expiresAt: int} */
    private static function attempts(int $user_id): array {
        $value = get_transient(self::key($user_id));
        if (! is_array($value) || ! is_array($value['codes'] ?? null)
            || (int) ($value['expiresAt'] ?? 0) <= time()
        ) {
            return array('codes' => array(), 'expiresAt' => time() + self::WINDOW_SECONDS);
        }

        return array('codes' => $value['codes'], 'expiresAt' => (int) $value['expiresAt']);
    }

    private static function key(int $user_id): string {
        return 'httt_code_guard_v2_' . $user_id;
    }
}
