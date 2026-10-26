<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Domain;

use InvalidArgumentException;

final class DashboardBlock
{
    /** @param list<DashboardItem> $items */
    public function __construct(
        private readonly string $key,
        private readonly string $title,
        private readonly string $description,
        private readonly string $kind,
        private readonly array $items,
        private readonly bool $failed = false
    ) {
        if (preg_match('/^[a-z][a-z0-9_.]*$/', $this->key) !== 1) {
            throw new InvalidArgumentException('관리자 대시보드 블록 키가 올바르지 않습니다.');
        }
        if (! in_array($this->kind, ['tasks', 'jobs', 'alerts', 'shortcuts'], true)) {
            throw new InvalidArgumentException('관리자 대시보드 블록 종류가 올바르지 않습니다.');
        }
        if (count($this->items) > 8) {
            throw new InvalidArgumentException('관리자 대시보드 블록은 항목을 최대 8개까지 표시할 수 있습니다.');
        }
        foreach ($this->items as $item) {
            if (! $item instanceof DashboardItem) {
                throw new InvalidArgumentException('관리자 대시보드 항목 형식이 올바르지 않습니다.');
            }
        }
    }

    public static function failure(
        string $key,
        string $title,
        string $kind
    ): self {
        return new self($key, $title, '', $kind, [], true);
    }

    public function key(): string { return $this->key; }
    public function title(): string { return $this->title; }
    public function description(): string { return $this->description; }
    public function kind(): string { return $this->kind; }
    /** @return list<DashboardItem> */
    public function items(): array { return $this->items; }
    public function failed(): bool { return $this->failed; }
}
