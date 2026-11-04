<?php
declare(strict_types=1);
namespace OjMicrobit\Installer;

use Closure;
use RuntimeException;

/** CLI-only installer support; never loaded by WordPress web requests. */
final class WordPressConfigurator
{
    private const OPTION = 'oj_microbit_settings';
    public function __construct(private readonly array $overrides = [], private readonly ?Closure $privateWriter = null, private readonly ?Closure $optionWriter = null) {}

    public function execute(array $request): array
    {
        $mode = $request['mode'] ?? '';
        if (!in_array($mode, ['inspect', 'prepare', 'configure', 'check'], true)) { throw new RuntimeException('invalid_mode'); }
        $active = get_option('active_plugins', []);
        if (!is_array($active) || !in_array('oj-microbit/oj-microbit.php', $active, true)) { throw new RuntimeException('plugin_inactive'); }
        $old = get_option(self::OPTION, null);
        if ($old !== null && !is_array($old)) { throw new RuntimeException('invalid_stored_settings'); }
        $settings = $old ?? [];
        $snapshot = ['exists' => $old !== null, 'settings' => $settings];
        $hash = hash('sha256', $this->json($snapshot));
        $effectiveUrl = $this->overrides['url'] ?? ($settings['runnerUrl'] ?? '');
        $effectiveToken = $this->overrides['token'] ?? ($settings['runnerToken'] ?? '');
        $base = ['ok' => true, 'mode' => $mode, 'active' => true,
            'configured' => is_string($effectiveUrl) && $effectiveUrl !== '' && $this->validToken($effectiveToken),
            'constantsOverride' => array_key_exists('url', $this->overrides) || array_key_exists('token', $this->overrides)];
        if ($mode === 'inspect') { return $base; }
        $url = $this->url($request['url'] ?? null);
        if (array_key_exists('url', $this->overrides) && $this->url($this->overrides['url']) !== $url) { throw new RuntimeException('url_constant_conflict'); }
        if ($mode === 'prepare') {
            if (($request['backupFile'] ?? null) === ($request['secretFile'] ?? null)) { throw new RuntimeException('invalid_private_file'); }
            $candidate = $request['token'] ?? null;
            if ($candidate !== null && !$this->validToken($candidate)) { throw new RuntimeException('invalid_token'); }
            $token = $candidate ?? ($this->validToken($effectiveToken) ? $effectiveToken : bin2hex(random_bytes(32)));
            $this->checkTokenOverride($token);
            $this->writePrivate($request['backupFile'] ?? null, $this->json($snapshot) . "\n");
            $this->writePrivate($request['secretFile'] ?? null, $token . "\n");
            return $base + ['prepared' => true, 'expectedHash' => $hash];
        }
        $token = $request['token'] ?? null;
        if (!$this->validToken($token)) { throw new RuntimeException('invalid_token'); }
        $this->checkTokenOverride($token);
        if ($mode === 'check') {
            if (!is_string($effectiveUrl) || rtrim($effectiveUrl, '/') !== $url || !is_string($effectiveToken) || !hash_equals($effectiveToken, $token)) {
                throw new RuntimeException('connection_settings_mismatch');
            }
            return array_replace($base, ['configured' => true]);
        }
        $expectedHash = $request['expectedHash'] ?? null;
        if (!is_string($expectedHash) || preg_match('/\A[a-f0-9]{64}\z/D', $expectedHash) !== 1 || !hash_equals($hash, $expectedHash)) {
            throw new RuntimeException('settings_changed');
        }
        $next = array_replace($settings, ['runnerUrl' => $url, 'runnerToken' => $token]);
        if ($next === $settings) { return array_replace($base, ['changed' => false, 'configured' => true]); }
        try {
            if (!$this->compareAndSwap($old, $next)) { throw new RuntimeException('settings_changed'); }
            if (get_option(self::OPTION, null) !== $next) { throw new RuntimeException('settings_write_failed'); }
        } catch (\Throwable $error) {
            if ($error instanceof RuntimeException && $error->getMessage() === 'settings_changed') { throw $error; }
            // Restore only the value we wrote. Do not overwrite a concurrent
            // administrator update that happened after this process's write.
            $current = get_option(self::OPTION, null);
            if ($current === $next) {
                $restored = $this->compareAndSwap($next, $old);
                if (!$restored || get_option(self::OPTION, null) !== $old) { throw new RuntimeException('settings_rollback_failed'); }
            } elseif ($current !== $old) { throw new RuntimeException('settings_changed_after_write'); }
            throw new RuntimeException('settings_write_failed');
        }
        return array_replace($base, ['changed' => true, 'configured' => true]);
    }

    private function checkTokenOverride(string $token): void
    {
        if (array_key_exists('token', $this->overrides)
            && (!is_string($this->overrides['token']) || !hash_equals($this->overrides['token'], $token))) { throw new RuntimeException('token_constant_conflict'); }
    }
    private function validToken(mixed $token): bool
    {
        return is_string($token) && strlen($token) >= 32 && strlen($token) <= 256 && preg_match('/\A[\x21-\x7e]+\z/D', $token) === 1;
    }
    private function url(mixed $value): string
    {
        if (!is_string($value) || preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})/?\z~D', $value, $match) !== 1
            || (int) $match[1] > 65535) { throw new RuntimeException('invalid_local_url'); }
        return rtrim($value, '/');
    }
    private function json(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function compareAndSwap(?array $expected, ?array $replacement): bool
    {
        if ($this->optionWriter !== null) { return ($this->optionWriter)($expected, $replacement); }
        if ($expected === null) { return add_option(self::OPTION, $replacement, '', false); }
        global $wpdb;
        $table = '`' . str_replace('`', '``', $wpdb->options) . '`';
        // Guard the exact serialized value in the same PK-scoped statement:
        // a settings form saved during installation must not be overwritten.
        $query = $replacement === null
            ? $wpdb->prepare("DELETE FROM {$table} WHERE option_name=%s AND BINARY option_value=BINARY %s", self::OPTION, maybe_serialize($expected))
            : $wpdb->prepare("UPDATE {$table} SET option_value=%s,autoload='no' WHERE option_name=%s AND BINARY option_value=BINARY %s", maybe_serialize($replacement), self::OPTION, maybe_serialize($expected));
        $changed = $wpdb->query($query);
        wp_cache_delete(self::OPTION, 'options'); wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options');
        if ($changed === false) { throw new RuntimeException('settings_write_failed'); }
        return $changed === 1;
    }
    private function writePrivate(mixed $path, string $content): void
    {
        if (!is_string($path) || $path === '' || str_contains($path, "\0")) { throw new RuntimeException('invalid_private_file'); }
        if ($this->privateWriter !== null) { ($this->privateWriter)($path, $content); return; }
        $before = lstat($path);
        if ($before === false || is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0777) !== 0600 || $before['nlink'] !== 1 || $before['uid'] !== posix_geteuid()) { throw new RuntimeException('unsafe_private_file'); }
        $file = fopen($path, 'r+b');
        if ($file === false) { throw new RuntimeException('private_file_write_failed'); }
        try {
            $opened = fstat($file);
            if ($opened === false || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino'] || !flock($file, LOCK_EX)
                || !ftruncate($file, 0) || fwrite($file, $content) !== strlen($content) || !fflush($file)) { throw new RuntimeException('private_file_write_failed'); }
        } finally { fclose($file); }
    }
}
