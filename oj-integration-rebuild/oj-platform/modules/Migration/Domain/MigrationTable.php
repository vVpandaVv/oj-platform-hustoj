<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Domain;

use InvalidArgumentException;

final class MigrationTable
{
    public function __construct(
        private readonly string $key,
        private readonly MigrationWave $wave,
        private readonly ?string $legacyLogicalName,
        private readonly string $targetTable,
        private readonly string $sourceKey,
        private readonly string $transformer,
        private readonly bool $sameNameCollision = false
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_]*$/', $this->key) !== 1
            || preg_match('/^[a-z][a-z0-9_]*$/', $this->targetTable) !== 1
            || preg_match('/^[a-z][a-z0-9_]*$/', $this->sourceKey) !== 1
            || preg_match('/^[a-z][a-z0-9_]*$/', $this->transformer) !== 1
            || ($this->legacyLogicalName !== null
                && preg_match('/^[a-z][a-z0-9_]*$/', $this->legacyLogicalName) !== 1)
        ) {
            throw new InvalidArgumentException('이전 테이블 정의가 올바르지 않습니다.');
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function wave(): MigrationWave
    {
        return $this->wave;
    }

    public function legacyLogicalName(): ?string
    {
        return $this->legacyLogicalName;
    }

    public function targetTable(): string
    {
        return $this->targetTable;
    }

    public function sourceKey(): string
    {
        return $this->sourceKey;
    }

    public function transformer(): string
    {
        return $this->transformer;
    }

    public function hasLegacySource(): bool
    {
        return $this->legacyLogicalName !== null;
    }

    public function hasSameNameCollision(): bool
    {
        return $this->sameNameCollision;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function canonical(): array
    {
        return [
            'key' => $this->key,
            'wave' => $this->wave->value,
            'legacy' => $this->legacyLogicalName,
            'target' => $this->targetTable,
            'source_key' => $this->sourceKey,
            'transformer' => $this->transformer,
            'same_name_collision' => $this->sameNameCollision,
        ];
    }
}
