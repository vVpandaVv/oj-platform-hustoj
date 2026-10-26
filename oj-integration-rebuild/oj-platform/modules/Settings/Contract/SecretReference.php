<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

use InvalidArgumentException;

final class SecretReference
{
    public function __construct(private readonly string $reference)
    {
        if (
            preg_match(
                '/^secret:v2:[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
                $this->reference
            ) !== 1
        ) {
            throw new InvalidArgumentException('비밀값 참조 형식이 올바르지 않습니다.');
        }
    }

    public function value(): string
    {
        return $this->reference;
    }
}
