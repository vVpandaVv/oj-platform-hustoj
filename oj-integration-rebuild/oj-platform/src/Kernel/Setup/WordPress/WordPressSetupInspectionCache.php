<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup\WordPress;

use CodingPanda\OjPlatform\Kernel\Setup\SetupInspectionCache;
use CodingPanda\OjPlatform\Kernel\Setup\SetupMode;
use CodingPanda\OjPlatform\Kernel\Setup\SetupSnapshot;

final class WordPressSetupInspectionCache implements SetupInspectionCache
{
    private const KEY = 'oj_platform_setup_inspection';

    public function __construct(private readonly string $platformVersion) {}

    public function read(string $fingerprint): ?SetupSnapshot
    {
        if (! function_exists('get_transient')) { return null; }
        $record = get_transient(self::KEY);
        if (! is_array($record) || ($record['key'] ?? '') !== $this->key($fingerprint)
            || ! is_array($record['tables'] ?? null) || ! is_array($record['legacy'] ?? null)
            || ! is_string($record['installation'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $record['installation']) !== 1
            || ! in_array($record['status'] ?? null, ['complete', 'migration_ready'], true)
            || ! is_int($record['targets'] ?? null) || $record['targets'] < 1
            || ! is_int($record['schemas'] ?? null) || $record['schemas'] < $record['targets']
            || count($record['tables']) !== $record['targets']
            || ! is_int($record['checked_at'] ?? null) || ! is_int($record['expires_at'] ?? null)
            || $record['checked_at'] > time() || $record['expires_at'] <= time()
            || $record['expires_at'] > $record['checked_at'] + 300) {
            return null;
        }
        foreach ([$record['tables'], $record['legacy']] as $names) {
            if (count(array_unique($names, SORT_REGULAR)) !== count($names)) { return null; }
            foreach ($names as $name) {
                if (! is_string($name) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) !== 1) { return null; }
            }
        }
        return new SetupSnapshot(SetupMode::AlreadyInstalled, $record['installation'], $record['status'],
            $record['tables'], [], [], [], (int) ($record['targets'] ?? 0), (int) ($record['schemas'] ?? 0),
            is_string($record['plan'] ?? null) ? $record['plan'] : null, $record['legacy'], []);
    }

    public function write(string $fingerprint, SetupSnapshot $snapshot): void
    {
        if (! function_exists('set_transient') || $snapshot->mode() !== SetupMode::AlreadyInstalled
            || ! $snapshot->isRuntimeReady()) { return; }
        set_transient(self::KEY, [
            'key' => $this->key($fingerprint), 'installation' => $snapshot->installationId(),
            'status' => $snapshot->status(), 'tables' => $snapshot->existingTables(),
            'targets' => $snapshot->targetTableCount(), 'schemas' => $snapshot->registeredSchemaCount(),
            'plan' => $snapshot->planHash(), 'legacy' => $snapshot->legacyTables(),
            'checked_at' => time(), 'expires_at' => time() + 300,
        ], 300);
    }

    public function clear(): void
    {
        if (function_exists('delete_transient')) { delete_transient(self::KEY); }
    }

    private function key(string $fingerprint): string
    {
        $database = $GLOBALS['wpdb'] ?? null;
        return hash('sha256', serialize([$this->platformVersion, $fingerprint,
            is_object($database) ? [(string) ($database->dbname ?? ''), (string) ($database->dbhost ?? ''), (string) ($database->prefix ?? '')] : [],
            defined('ABSPATH') ? ABSPATH : '', function_exists('get_option') ? get_option('siteurl', '') : '',
        ]));
    }
}
