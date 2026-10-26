<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Domain;

use InvalidArgumentException;

final class SqlIdentifier
{
    public function __construct(private readonly string $identifier)
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $this->identifier) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'SQL 식별자 형식이 올바르지 않습니다: %s',
                $this->identifier
            ));
        }
    }

    public function value(): string
    {
        return $this->identifier;
    }

    public function quoted(): string
    {
        return '`' . $this->identifier . '`';
    }
}
