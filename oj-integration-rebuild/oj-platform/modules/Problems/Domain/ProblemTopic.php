<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicRecord;
use InvalidArgumentException;

final class ProblemTopic implements ProblemTopicRecord
{
    /**
     * @param list<string> $aliases
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly ?string $categoryKey,
        private readonly array $aliases,
        private readonly bool $active = true
    ) {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->key) !== 1) {
            throw new InvalidArgumentException('문제 주제 키 형식이 올바르지 않습니다.');
        }
        if (trim($this->label) === '') {
            throw new InvalidArgumentException('문제 주제 이름은 비어 있을 수 없습니다.');
        }
        if (
            $this->categoryKey !== null
            && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->categoryKey) !== 1
        ) {
            throw new InvalidArgumentException('상위 분야 키 형식이 올바르지 않습니다.');
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function categoryKey(): ?string
    {
        return $this->categoryKey;
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return $this->aliases;
    }

    public function isCategory(): bool
    {
        return $this->categoryKey === null;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
