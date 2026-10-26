<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Contract;

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\RegistryDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Domain\SqlIdentifier;
use InvalidArgumentException;

final class TargetTableDefinition implements RegistryDefinition
{
    public function __construct(
        private readonly SqlIdentifier $tableName,
        private readonly ModuleId $moduleOwner,
        private readonly int $installWave,
        private readonly string $engine,
        private readonly string $charset
    ) {
        if ($this->installWave < 0) {
            throw new InvalidArgumentException('테이블 설치 파동은 0 이상이어야 합니다.');
        }

        if ($this->engine !== 'InnoDB') {
            throw new InvalidArgumentException('플랫폼 테이블 엔진은 InnoDB여야 합니다.');
        }

        if ($this->charset !== 'utf8mb4') {
            throw new InvalidArgumentException('플랫폼 테이블 문자셋은 utf8mb4여야 합니다.');
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            new SqlIdentifier((string) ($definition['name'] ?? '')),
            new ModuleId((string) ($definition['owner_module'] ?? '')),
            (int) ($definition['wave'] ?? -1),
            (string) ($definition['engine'] ?? ''),
            (string) ($definition['charset'] ?? '')
        );
    }

    public function key(): string
    {
        return $this->tableName->value();
    }

    public function owner(): ModuleId
    {
        return $this->moduleOwner;
    }

    public function wave(): int
    {
        return $this->installWave;
    }

    public function engine(): string
    {
        return $this->engine;
    }

    public function charset(): string
    {
        return $this->charset;
    }
}
