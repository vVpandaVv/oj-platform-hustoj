<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Navigation;

use InvalidArgumentException;

final class PublicMenuItem
{
    /**
     * @param list<PublicMenuItem> $children
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $url,
        private readonly array $children = [],
        private readonly bool $external = false
    ) {
        if (trim($this->key) === '' || trim($this->label) === '') {
            throw new InvalidArgumentException('사용자 메뉴 식별자와 이름은 비어 있을 수 없습니다.');
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

    public function url(): string
    {
        return $this->url;
    }

    /**
     * @return list<PublicMenuItem>
     */
    public function children(): array
    {
        return $this->children;
    }

    public function isExternal(): bool
    {
        return $this->external;
    }
}
