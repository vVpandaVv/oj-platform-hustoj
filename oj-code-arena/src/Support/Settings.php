<?php
declare(strict_types=1);

namespace OjCodeArena\Support;

/** Site settings (OJ console › 교사 메뉴 › 코드 아레나 › 설정). */
final class Settings {
    public const OPTION = 'oj_code_arena_settings';

    public const ENGINE_BUILTIN = 'builtin';
    public const ENGINE_NODE = 'node';

    /** @return array{engine: string, public_url: string, api_url: string, secret: string, free_enabled: bool, keep_days: int} */
    public static function all(): array {
        $raw = get_option(self::OPTION, array());
        $raw = is_array($raw) ? $raw : array();
        return array(
            // 0.1.0 sites had only the Node server: keep them on it.
            'engine' => self::ENGINE_NODE === ($raw['engine'] ?? ('' !== (string) ($raw['public_url'] ?? '') ? self::ENGINE_NODE : '')) ? self::ENGINE_NODE : self::ENGINE_BUILTIN,
            'public_url' => (string) ($raw['public_url'] ?? ''),
            'api_url' => (string) ($raw['api_url'] ?? ''),
            'secret' => (string) ($raw['secret'] ?? ''),
            'free_enabled' => ! empty($raw['free_enabled']),
            'keep_days' => max(0, min(3650, (int) ($raw['keep_days'] ?? 0))),
        );
    }

    /** Browser WebSocket address, e.g. wss://arena.example.com/arena */
    public static function public_url(): string {
        return self::all()['public_url'];
    }

    /** Address WordPress uses for server-to-server calls, e.g. http://127.0.0.1:3200/arena */
    public static function api_url(): string {
        $s = self::all();
        if ('' !== $s['api_url']) {
            return untrailingslashit($s['api_url']);
        }
        // Same host as the public address, over http(s).
        return untrailingslashit((string) preg_replace('#^ws(s?)://#', 'http$1://', $s['public_url']));
    }

    public static function secret(): string {
        return self::all()['secret'];
    }

    /** Days to keep round records (0 = forever). */
    public static function keep_days(): int {
        return self::all()['keep_days'];
    }

    public static function free_enabled(): bool {
        return self::all()['free_enabled'];
    }

    /**
     * Built-in mode (default) runs the game inside WordPress and needs
     * nothing else; Node mode uses the separate game server.
     */
    public static function uses_node(): bool {
        return self::ENGINE_NODE === self::all()['engine'];
    }

    public static function configured(): bool {
        $s = self::all();
        if (! self::uses_node()) {
            return strlen($s['secret']) >= 32;
        }
        return '' !== $s['public_url'] && strlen($s['secret']) >= 32;
    }

    /** @param array<string,mixed> $values */
    public static function save(array $values): void {
        $current = self::all();
        $next = array(
            'engine' => self::ENGINE_NODE === ($values['engine'] ?? $current['engine']) ? self::ENGINE_NODE : self::ENGINE_BUILTIN,
            'public_url' => self::clean_url((string) ($values['public_url'] ?? $current['public_url']), array('ws', 'wss')),
            'api_url' => self::clean_url((string) ($values['api_url'] ?? $current['api_url']), array('http', 'https')),
            'secret' => (string) ($values['secret'] ?? $current['secret']),
            'free_enabled' => ! empty($values['free_enabled']),
            'keep_days' => max(0, min(3650, (int) ($values['keep_days'] ?? $current['keep_days']))),
        );
        update_option(self::OPTION, $next, false);
    }

    public static function ensure_secret(): void {
        if (strlen(self::secret()) < 32) {
            $s = self::all();
            $s['secret'] = self::new_secret();
            update_option(self::OPTION, $s, false);
        }
    }

    public static function new_secret(): string {
        return bin2hex(random_bytes(24));
    }

    /** @param list<string> $schemes */
    private static function clean_url(string $url, array $schemes): string {
        $url = trim($url);
        if ('' === $url) {
            return '';
        }
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, $schemes, true)) {
            return '';
        }
        return untrailingslashit(esc_url_raw($url, $schemes));
    }
}
