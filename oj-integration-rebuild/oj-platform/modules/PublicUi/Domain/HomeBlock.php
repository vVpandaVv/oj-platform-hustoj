<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Domain;

use CodingPanda\OjPlatform\Ui\View\RouteTarget;
use InvalidArgumentException;

final class HomeBlock
{
    /**
     * @param list<HomeBlockItem> $items
     */
    public function __construct(
        private readonly string $key,
        private readonly string $title,
        private readonly string $description,
        private readonly string $presentation,
        private readonly array $items,
        private readonly ?RouteTarget $allTarget = null,
        private readonly string $emptyTitle = '',
        private readonly string $emptyDescription = '',
        private readonly bool $failed = false,
        private readonly bool $partial = false
    ) {
        if (preg_match('/^[a-z][a-z0-9_.]*$/', $this->key) !== 1) {
            throw new InvalidArgumentException('홈 블록 키가 올바르지 않습니다.');
        }
        if (! in_array($this->presentation, ['banner', 'cards', 'list', 'notices', 'shortcuts'], true)) {
            throw new InvalidArgumentException('홈 블록 표시 형식이 올바르지 않습니다.');
        }
        if (count($this->items) > 6) {
            throw new InvalidArgumentException('홈 블록은 항목을 최대 6개까지 표시할 수 있습니다.');
        }
        foreach ($this->items as $item) {
            if (! $item instanceof HomeBlockItem) {
                throw new InvalidArgumentException('홈 블록 항목 형식이 올바르지 않습니다.');
            }
        }
    }

    public static function failure(string $key, string $title): self
    {
        return new self(
            $key,
            $title,
            '',
            'list',
            [],
            null,
            $title . '을 불러오지 못했습니다.',
            '잠시 후 다시 확인해 주세요. 다른 홈 기능은 계속 사용할 수 있습니다.',
            true
        );
    }

    public function key(): string { return $this->key; }
    public function title(): string { return $this->title; }
    public function description(): string { return $this->description; }
    public function presentation(): string { return $this->presentation; }
    /** @return list<HomeBlockItem> */
    public function items(): array { return $this->items; }
    public function allTarget(): ?RouteTarget { return $this->allTarget; }
    public function emptyTitle(): string { return $this->emptyTitle; }
    public function emptyDescription(): string { return $this->emptyDescription; }
    public function failed(): bool { return $this->failed; }
    public function partial(): bool { return $this->partial; }
}
