<?php
declare(strict_types=1);

namespace TeachingTools\Support;

final class Realtime_Ticket {
    private const LIFETIME_SECONDS = 120;

    public static function issue(int $session_id, int $user_id, string $role, string $channel = 'whiteboard'): string {
        if (! in_array($channel, array('whiteboard', 'classroom', 'quickboard'), true)) {
            return '';
        }

        $claims = array(
            'v' => 1,
            'app' => 'teaching-tools',
            'channel' => $channel,
            'room' => $channel . ':' . $session_id,
            'role' => 'host' === $role ? 'host' : 'viewer',
            'uid' => $user_id,
            'iat' => time(),
            'exp' => time() + self::LIFETIME_SECONDS,
        );
        $json = wp_json_encode($claims);
        if (false === $json) {
            return '';
        }

        $payload = self::base64url($json);
        $signature = hash_hmac('sha256', $payload, Realtime_Settings::secret(), true);

        return $payload . '.' . self::base64url($signature);
    }

    public static function lifetime(): int {
        return self::LIFETIME_SECONDS;
    }

    private static function base64url(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
