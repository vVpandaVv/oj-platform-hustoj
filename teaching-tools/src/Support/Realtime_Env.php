<?php
declare(strict_types=1);

namespace TeachingTools\Support;

final class Realtime_Env {
    public const CODE_PREFIX = 'ojrt1.';

    /** @return array<string, string> */
    public static function build(): array {
        $public_url = Realtime_Settings::public_url();
        $parsed = wp_parse_url($public_url);
        $home = wp_parse_url(home_url());
        $path = (string) ($parsed['path'] ?? '/classroom-live');
        if ('' === $path || '/' === $path) {
            $path = '/classroom-live';
        }

        $home_scheme = (string) ($home['scheme'] ?? 'https');
        $home_host = (string) ($home['host'] ?? '');
        $home_port = isset($home['port']) ? ':' . (int) $home['port'] : '';

        return array(
            'REALTIME_SECRET' => Realtime_Settings::secret(),
            'ALLOWED_ORIGINS' => $home_scheme . '://' . $home_host . $home_port,
            'PORT' => '3210',
            'HOST' => '127.0.0.1',
            'WS_PATH' => '/' . ltrim($path, '/'),
        );
    }

    /** @param array<string, string> $env */
    public static function setup_code(array $env): string {
        $json = wp_json_encode(array('v' => 1, 'env' => $env));
        if (false === $json) {
            return '';
        }

        return self::CODE_PREFIX . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function install_code(array $env): string {
        $json = wp_json_encode(array('v' => 1, 'app' => 'teaching-tools', 'env' => $env));
        return false === $json ? '' : 'ojhub1.' . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
