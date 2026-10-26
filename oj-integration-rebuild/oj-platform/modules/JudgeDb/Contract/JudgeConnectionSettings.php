<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Contract;

use InvalidArgumentException;

final class JudgeConnectionSettings
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly JudgeCredentials $readCredentials,
        private readonly JudgeCredentials $writeCredentials,
        private readonly bool $tlsEnabled,
        private readonly ?string $tlsCaPath,
        private readonly bool $utcStorageConfirmed = false
    ) {
        if (trim($this->host) === '') {
            throw new InvalidArgumentException('Judge DB 호스트는 비어 있을 수 없습니다.');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException('Judge DB 포트 범위가 올바르지 않습니다.');
        }

        if ($this->database !== 'jol') {
            throw new InvalidArgumentException('Judge DB 이름은 jol이어야 합니다.');
        }

        if (! $this->isLocalHost($this->host) && ! $this->tlsEnabled) {
            throw new InvalidArgumentException('원격 Judge DB 연결에는 TLS가 필요합니다.');
        }

        if ($this->tlsEnabled && $this->tlsCaPath !== null && trim($this->tlsCaPath) === '') {
            throw new InvalidArgumentException('TLS CA 경로는 비우거나 올바른 경로를 지정해야 합니다.');
        }
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function database(): string
    {
        return $this->database;
    }

    public function readCredentials(): JudgeCredentials
    {
        return $this->readCredentials;
    }

    public function writeCredentials(): JudgeCredentials
    {
        return $this->writeCredentials;
    }

    public function usesSingleAccount(): bool
    {
        return $this->readCredentials->equals($this->writeCredentials);
    }

    public function tlsEnabled(): bool
    {
        return $this->tlsEnabled;
    }

    public function tlsCaPath(): ?string
    {
        return $this->tlsCaPath;
    }

    public function utcStorageConfirmed(): bool
    {
        return $this->utcStorageConfirmed;
    }

    private function isLocalHost(string $host): bool
    {
        return in_array(
            strtolower(trim($host, '[] ')),
            ['localhost', '127.0.0.1', '::1'],
            true
        );
    }
}
