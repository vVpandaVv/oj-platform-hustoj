<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Contract;

use CodingPanda\OjPlatform\Modules\PlatformDb\Domain\SqlIdentifier;
use InvalidArgumentException;

final class IndexDefinition
{
    /**
     * @var list<SqlIdentifier>
     */
    private readonly array $indexColumns;

    private readonly ?SqlIdentifier $indexName;

    /**
     * @param list<string> $columns
     */
    public function __construct(
        ?string $name,
        array $columns,
        private readonly bool $unique,
        private readonly bool $primary
    ) {
        if ($columns === []) {
            throw new InvalidArgumentException('인덱스에는 열이 하나 이상 필요합니다.');
        }

        if ($this->primary && ($name !== null || ! $this->unique)) {
            throw new InvalidArgumentException('기본 키는 이름 없이 고유 인덱스로 정의해야 합니다.');
        }

        if (! $this->primary && $name === null) {
            throw new InvalidArgumentException('일반 인덱스에는 이름이 필요합니다.');
        }

        $normalized = array_values(array_unique(array_map('strval', $columns)));
        if (count($normalized) !== count($columns)) {
            throw new InvalidArgumentException('한 인덱스에 같은 열을 중복 지정할 수 없습니다.');
        }

        $this->indexName = $name === null ? null : new SqlIdentifier($name);
        $this->indexColumns = array_map(
            static fn (string $column): SqlIdentifier => new SqlIdentifier($column),
            $normalized
        );
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            isset($definition['name']) ? (string) $definition['name'] : null,
            array_values((array) ($definition['columns'] ?? [])),
            (bool) ($definition['unique'] ?? false),
            (bool) ($definition['primary'] ?? false)
        );
    }

    public function name(): ?string
    {
        return $this->indexName?->value();
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return array_map(
            static fn (SqlIdentifier $column): string => $column->value(),
            $this->indexColumns
        );
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    /**
     * @return array<string, mixed>
     */
    public function canonical(): array
    {
        return [
            'name' => $this->name(),
            'columns' => $this->columns(),
            'unique' => $this->isUnique(),
            'primary' => $this->isPrimary(),
        ];
    }
}
