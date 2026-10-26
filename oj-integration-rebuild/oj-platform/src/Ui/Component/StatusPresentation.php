<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Component;

use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use InvalidArgumentException;

final class StatusPresentation
{
    public function __construct(
        private readonly string $statusKey,
        private readonly string $label,
        private readonly MessageTone $tone
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $this->statusKey) !== 1) {
            throw new InvalidArgumentException('상태 표시 키 형식이 올바르지 않습니다.');
        }

        if (trim($this->label) === '') {
            throw new InvalidArgumentException('상태 표시 문구는 비어 있을 수 없습니다.');
        }
    }

    public function key(): string
    {
        return $this->statusKey;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function tone(): MessageTone
    {
        return $this->tone;
    }
}
