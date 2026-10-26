<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Contract;

use CodingPanda\OjPlatform\Modules\PlatformDb\Domain\SqlIdentifier;
use InvalidArgumentException;

final class ColumnDefinition
{
    private readonly SqlIdentifier $columnName;

    public function __construct(
        string $name,
        private readonly string $sqlType,
        private readonly bool $nullable,
        private readonly mixed $defaultValue,
        private readonly ?string $defaultExpression,
        private readonly bool $autoIncrement,
        private readonly bool $hasDefault
    ) {
        $this->columnName = new SqlIdentifier($name);

        if (preg_match('/^[A-Z]+(?:\([0-9,]+\))?(?: UNSIGNED)?$/', $this->sqlType) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '열 SQL 타입 형식이 올바르지 않습니다: %s',
                $this->sqlType
            ));
        }

        if ($this->defaultExpression !== null && $this->hasDefault) {
            throw new InvalidArgumentException('열 기본값과 기본 표현식을 함께 지정할 수 없습니다.');
        }

        if (
            $this->defaultExpression !== null
            && ! in_array($this->defaultExpression, ['CURRENT_TIMESTAMP'], true)
        ) {
            throw new InvalidArgumentException('허용되지 않은 열 기본 표현식입니다.');
        }

        if ($this->autoIncrement && ($this->nullable || $this->hasDefault || $this->defaultExpression !== null)) {
            throw new InvalidArgumentException('AUTO_INCREMENT 열에는 NULL 또는 기본값을 지정할 수 없습니다.');
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            (string) ($definition['name'] ?? ''),
            strtoupper((string) ($definition['type'] ?? '')),
            (bool) ($definition['nullable'] ?? false),
            $definition['default'] ?? null,
            isset($definition['default_expression'])
                ? strtoupper((string) $definition['default_expression'])
                : null,
            (bool) ($definition['auto_increment'] ?? false),
            array_key_exists('default', $definition)
        );
    }

    public function name(): string
    {
        return $this->columnName->value();
    }

    public function quotedName(): string
    {
        return $this->columnName->quoted();
    }

    public function type(): string
    {
        return $this->sqlType;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function defaultValue(): mixed
    {
        return $this->defaultValue;
    }

    public function defaultExpression(): ?string
    {
        return $this->defaultExpression;
    }

    public function isAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    /**
     * @return array<string, mixed>
     */
    public function canonical(): array
    {
        return [
            'name' => $this->name(),
            'type' => $this->type(),
            'nullable' => $this->isNullable(),
            'has_default' => $this->hasDefault(),
            'default' => $this->defaultValue(),
            'default_expression' => $this->defaultExpression(),
            'auto_increment' => $this->isAutoIncrement(),
        ];
    }
}
