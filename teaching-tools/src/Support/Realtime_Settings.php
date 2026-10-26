<?php
declare(strict_types=1);

namespace TeachingTools\Support;

final class Realtime_Settings {
    public const OPTION = 'teaching_tools_realtime';

    /** @return array{enabled: bool, public_url: string, secret: string} */
    public static function all(): array {
        $raw = get_option(self::OPTION, array());
        $raw = is_array($raw) ? $raw : array();

        return array(
            'enabled' => ! array_key_exists('enabled', $raw) || ! empty($raw['enabled']),
            'public_url' => '' !== (string) ($raw['public_url'] ?? '')
                ? (string) $raw['public_url']
                : self::default_public_url(),
            'secret' => (string) ($raw['secret'] ?? ''),
        );
    }

    public static function enabled(): bool {
        return self::all()['enabled'];
    }

    public static function public_url(): string {
        return self::all()['public_url'];
    }

    public static function secret(): string {
        return self::all()['secret'];
    }

    public static function configured(): bool {
        $settings = self::all();

        return $settings['enabled']
            && '' !== $settings['public_url']
            && strlen($settings['secret']) >= 32;
    }

    /** @param array<string, mixed> $values */
    public static function save(array $values): void {
        $current = self::all();
        $url = self::clean_url((string) ($values['public_url'] ?? $current['public_url']));

        update_option(
            self::OPTION,
            array(
                'enabled' => ! empty($values['enabled']),
                'public_url' => '' !== $url ? $url : self::default_public_url(),
                'secret' => (string) ($values['secret'] ?? $current['secret']),
            ),
            false
        );
    }

    public static function ensure_secret(): void {
        if (strlen(self::secret()) >= 32) {
            return;
        }

        $settings = self::all();
        $settings['secret'] = self::new_secret();
        update_option(self::OPTION, $settings, false);
    }

    public static function new_secret(): string {
        return bin2hex(random_bytes(24));
    }

    public static function health_url(): string {
        $http_url = (string) preg_replace('#^ws(s?)://#i', 'http$1://', self::public_url());

        return untrailingslashit($http_url) . '/healthz';
    }

    private static function default_public_url(): string {
        $home = wp_parse_url(home_url());
        $host = (string) ($home['host'] ?? '');
        if ('' === $host) {
            return '';
        }

        $scheme = 'https' === strtolower((string) ($home['scheme'] ?? '')) ? 'wss' : 'ws';
        $port = isset($home['port']) ? ':' . (int) $home['port'] : '';

        return $scheme . '://' . $host . $port . '/classroom-live';
    }

    private static function clean_url(string $url): string {
        $url = trim($url);
        if ('' === $url) {
            return '';
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, array('ws', 'wss'), true)) {
            return '';
        }
        $parts = wp_parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }

        return untrailingslashit(esc_url_raw($url, array('ws', 'wss')));
    }
}
