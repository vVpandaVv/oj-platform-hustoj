<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Infrastructure\Mysqli;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeCredentials;
use RuntimeException;

final class MysqliJudgeConnector implements JudgeConnector
{
    /** @var array<string, RequestReadConnection> */
    private array $readConnections = [];
    public function __construct(
        private readonly int $connectTimeoutSeconds = 3,
        private readonly int $readTimeoutSeconds = 5
    ) {
        if ($this->connectTimeoutSeconds < 1 || $this->readTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('Judge DB 시간 제한은 1초 이상이어야 합니다.');
        }
    }

    public function read(JudgeConnectionSettings $settings): \mysqli
    {
        $credentials = $settings->readCredentials();
        $key = hash('sha256', serialize([$settings->host(), $settings->port(), $settings->database(),
            $credentials->username(), $credentials->password(), $settings->tlsEnabled(), $settings->tlsCaPath(), $settings->utcStorageConfirmed()]));
        if (! isset($this->readConnections[$key])) {
            $connection = $this->connect($settings, $credentials, true);
            if (! $connection instanceof RequestReadConnection) { throw new RuntimeException('읽기 연결 소유권이 올바르지 않습니다.'); }
            $connection->retain();
            $this->readConnections[$key] = $connection;
        }
        return $this->readConnections[$key];
    }

    public function __destruct()
    {
        foreach ($this->readConnections as $connection) { $connection->release(); }
    }

    public function write(JudgeConnectionSettings $settings): \mysqli
    {
        return $this->connect($settings, $settings->writeCredentials());
    }

    private function connect(
        JudgeConnectionSettings $settings,
        JudgeCredentials $credentials,
        bool $requestRead = false
    ): \mysqli {
        if (! $settings->utcStorageConfirmed()) {
            throw new \DomainException(
                '저지 데이터베이스 설정에서 기존 제출 기록의 UTC 저장 여부를 확인한 뒤 후보를 적용해 주세요.'
            );
        }
        if (! extension_loaded('mysqli')) {
            throw new RuntimeException('mysqli 확장을 사용할 수 없습니다.');
        }

        $connection = $requestRead ? new RequestReadConnection() : mysqli_init();
        if (! $connection instanceof \mysqli) {
            throw new RuntimeException('Judge DB 연결을 초기화하지 못했습니다.');
        }

        $connection->options(
            MYSQLI_OPT_CONNECT_TIMEOUT,
            $this->connectTimeoutSeconds
        );
        if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
            $connection->options(
                MYSQLI_OPT_READ_TIMEOUT,
                $this->readTimeoutSeconds
            );
        }

        $flags = 0;
        if ($settings->tlsEnabled()) {
            if (
                $settings->tlsCaPath() !== null
                && (
                    ! is_file($settings->tlsCaPath())
                    || ! is_readable($settings->tlsCaPath())
                )
            ) {
                $connection->close();
                throw new RuntimeException('Judge DB TLS CA 파일을 읽을 수 없습니다.');
            }
            $connection->ssl_set(
                null,
                null,
                $settings->tlsCaPath(),
                null,
                null
            );
            if (defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
                $connection->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
            }
            $flags |= MYSQLI_CLIENT_SSL;
        }

        $connected = @$connection->real_connect(
            $settings->host(),
            $credentials->username(),
            $credentials->password(),
            $settings->database(),
            $settings->port(),
            null,
            $flags
        );
        if (! $connected) {
            $connection->close();
            throw new RuntimeException('Judge DB에 연결하지 못했습니다.');
        }
        if (! $connection->set_charset('utf8mb4')) {
            $connection->close();
            throw new RuntimeException('Judge DB 문자셋을 설정하지 못했습니다.');
        }
        if (! $connection->query("SET time_zone = '+00:00'")) {
            $connection->close();
            throw new RuntimeException('Judge DB 시간대를 UTC로 설정하지 못했습니다.');
        }

        return $connection;
    }
}
