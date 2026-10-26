<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\PlatformConnection;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\PlatformTableState;
use CodingPanda\OjPlatform\Modules\PlatformDb\Domain\SqlIdentifier;
use RuntimeException;

final class WordPressPlatformConnection implements PlatformConnection
{
    public function __construct(private readonly ?object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : null);
    }

    public function isAvailable(): bool
    {
        return $this->database !== null
            && method_exists($this->database, 'get_results')
            && method_exists($this->database, 'query');
    }

    public function databaseName(): string
    {
        if ($this->database === null) {
            return '';
        }

        return isset($this->database->dbname)
            ? (string) $this->database->dbname
            : '';
    }

    public function serverVersion(): string
    {
        if (! $this->isAvailable()) {
            return '';
        }

        try {
            if (method_exists($this->database, 'db_server_info')) {
                return trim((string) $this->database->db_server_info());
            }
            if (method_exists($this->database, 'db_version')) {
                return trim((string) $this->database->db_version());
            }
            if (method_exists($this->database, 'get_var')) {
                return trim((string) $this->database->get_var('SELECT VERSION()'));
            }
        } catch (\Throwable) {
            return '';
        }

        return '';
    }

    public function inspectTables(array $tableNames): array
    {
        if (! $this->isAvailable() || $tableNames === []) {
            return [];
        }

        $allowed = [];
        foreach (array_values(array_unique($tableNames)) as $tableName) {
            $identifier = new SqlIdentifier((string) $tableName);
            $allowed[$identifier->value()] = true;
        }

        $outputMode = defined('ARRAY_A') ? constant('ARRAY_A') : 'ARRAY_A';
        $rows = $this->database->get_results('SHOW TABLE STATUS', $outputMode);
        if (! is_array($rows)) {
            throw new RuntimeException('플랫폼 테이블 상태를 읽지 못했습니다.');
        }

        $states = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = (string) ($row['Name'] ?? '');
            if (! isset($allowed[$name])) {
                continue;
            }

            $states[$name] = new PlatformTableState(
                $name,
                (string) ($row['Comment'] ?? ''),
                (string) ($row['Engine'] ?? ''),
                (string) ($row['Collation'] ?? '')
            );
        }

        return $states;
    }

    public function execute(string $statement): void
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('WordPress 데이터베이스 연결을 사용할 수 없습니다.');
        }

        $result = $this->database->query($statement);
        if ($result === false) {
            $this->throwDatabaseError('플랫폼 스키마 명령을 실행하지 못했습니다.');
        }
    }

    public function synchronize(string $createStatement): void
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('WordPress 데이터베이스 연결을 사용할 수 없습니다.');
        }
        if (! function_exists('dbDelta')) {
            $upgradeFile = defined('ABSPATH')
                ? constant('ABSPATH') . 'wp-admin/includes/upgrade.php'
                : '';
            if ($upgradeFile !== '' && is_file($upgradeFile)) {
                require_once $upgradeFile;
            }
        }
        if (! function_exists('dbDelta')) {
            throw new RuntimeException('WordPress 스키마 동기화 기능을 불러오지 못했습니다.');
        }

        if (property_exists($this->database, 'last_error')) {
            $this->database->last_error = '';
        }
        \dbDelta($createStatement);
        $lastError = property_exists($this->database, 'last_error')
            ? trim((string) $this->database->last_error)
            : '';
        if ($lastError !== '' && ! self::isBenignSyncConflict($lastError)) {
            $this->throwDatabaseError('플랫폼 스키마를 동기화하지 못했습니다.');
        }
    }

    /**
     * dbDelta는 기존 테이블에 이미 있는 색인·컬럼을 다시 추가하려 시도하는 경우가 있어,
     * 이런 "이미 존재함" 충돌은 원하는 스키마 상태가 이미 이루어졌다는 뜻이므로 치명적 오류로
     * 취급하지 않는다.
     */
    private static function isBenignSyncConflict(string $message): bool
    {
        return preg_match('/duplicate (key|column) name/i', $message) === 1;
    }

    private function throwDatabaseError(string $fallback): never
    {
        $message = isset($this->database->last_error)
            ? trim((string) $this->database->last_error)
            : '';

        throw new RuntimeException(
            $message === '' ? $fallback : $fallback . ': ' . $message
        );
    }
}
