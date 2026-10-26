<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

use InvalidArgumentException;

final class FormDraftReference
{
    public function __construct(private readonly string $value)
    {
        if (
            preg_match(
                '/^draft:v1:[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
                $this->value
            ) !== 1
        ) {
            throw new InvalidArgumentException('Form Draft 참조가 올바르지 않습니다.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function recordId(): string
    {
        return substr($this->value, strlen('draft:v1:'));
    }
}
