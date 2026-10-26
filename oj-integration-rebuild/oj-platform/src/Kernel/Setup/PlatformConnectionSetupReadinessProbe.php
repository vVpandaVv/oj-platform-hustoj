<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup;

use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\PlatformConnection;

final class PlatformConnectionSetupReadinessProbe implements SetupReadinessProbe
{
    private const MINIMUM_MYSQL_VERSION = '5.6.5';
    private const MINIMUM_MARIADB_VERSION = '10.1.0';

    public function __construct(private readonly PlatformConnection $connection)
    {
    }

    public function blockers(): array
    {
        if (! $this->connection->isAvailable()) {
            return ['WordPress 데이터베이스 연결을 확인해 주세요.'];
        }

        $serverInfo = trim($this->connection->serverVersion());
        $mariaDb = stripos($serverInfo, 'mariadb') !== false;
        $version = $this->extractVersion($serverInfo, $mariaDb);
        if ($version === null) {
            return ['플랫폼 데이터베이스 서버 버전을 확인하지 못했습니다.'];
        }

        $minimum = $mariaDb
            ? self::MINIMUM_MARIADB_VERSION
            : self::MINIMUM_MYSQL_VERSION;
        if (version_compare($version, $minimum, '<')) {
            return [sprintf(
                '플랫폼 데이터베이스 서버 %s은(는) 지원되지 않습니다. %s %s 이상이 필요합니다.',
                $version,
                $mariaDb ? 'MariaDB' : 'MySQL',
                $minimum
            )];
        }

        return [];
    }

    private function extractVersion(string $serverInfo, bool $mariaDb): ?string
    {
        if ($serverInfo === '') {
            return null;
        }

        if (
            $mariaDb
            && preg_match('/(\d+\.\d+(?:\.\d+)?)(?=-MariaDB)/i', $serverInfo, $matches) === 1
        ) {
            return $matches[1];
        }

        if (preg_match('/\d+\.\d+(?:\.\d+)?/', $serverInfo, $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }
}
