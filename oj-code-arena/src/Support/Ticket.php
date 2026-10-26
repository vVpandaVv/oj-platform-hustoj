<?php
declare(strict_types=1);

namespace OjCodeArena\Support;

/**
 * HMAC-SHA256 tickets and request signatures shared with the game server
 * (game-server/src/sign.js — keep the two in step).
 *
 *  ticket    = base64url(json claims) . "." . base64url(hmac(first part))
 *  signature = hex(hmac("<METHOD>\n<route>\n<unix time>\n<raw body>")) in X-Arena-Time /
 *              X-Arena-Signature; route is relative to the API base.
 */
final class Ticket {
    public const TTL = 300;
    private const SKEW = 300;

    /**
     * @param array<string,mixed> $claims uid, name, role, sid, free
     */
    public static function issue(array $claims, string $secret, ?int $now = null): string {
        $claims['v'] = 1;
        $claims['app'] = 'oj-code-arena';
        $claims['channel'] = 'arena';
        $claims['room'] = ! empty($claims['sid']) ? 'c' . (int) $claims['sid'] : 'lobby';
        $claims['exp'] = ($now ?? time()) + self::TTL;
        $body = self::b64url((string) wp_json_encode($claims, JSON_UNESCAPED_UNICODE));
        return $body . '.' . self::b64url(hash_hmac('sha256', $body, $secret, true));
    }

    private static function base(string $method, string $route, string $time, string $body): string {
        return strtoupper($method) . "\n" . $route . "\n" . $time . "\n" . $body;
    }

    /** @return array{time: string, signature: string} */
    public static function sign(string $method, string $route, string $body, string $secret, ?int $now = null): array {
        $time = (string) ($now ?? time());
        return array('time' => $time, 'signature' => hash_hmac('sha256', self::base($method, $route, $time, $body), $secret));
    }

    public static function verify(string $method, string $route, string $time, string $signature, string $body, string $secret, ?int $now = null): bool {
        if (strlen($secret) < 32 || ! preg_match('/^\d{9,12}$/', $time) || ! preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $time) > self::SKEW) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', self::base($method, $route, $time, $body), $secret), $signature);
    }

    private static function b64url(string $raw): string {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
