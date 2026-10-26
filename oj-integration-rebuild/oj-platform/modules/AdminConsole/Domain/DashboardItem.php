<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Domain;

use CodingPanda\OjPlatform\Ui\View\RouteTarget;
use InvalidArgumentException;

final class DashboardItem
{
    public function __construct(
        private readonly string $title,
        private readonly string $description,
        private readonly RouteTarget $target,
        private readonly ?int $count = null,
        private readonly string $badge = '',
        private readonly string $tone = 'neutral',
        private readonly string $meta = '',
        private readonly ?int $progress = null
    ) {
        if (trim($this->title) === '') {
            throw new InvalidArgumentException('관리자 대시보드 항목 제목은 비어 있을 수 없습니다.');
        }
        if ($this->count !== null && $this->count < 0) {
            throw new InvalidArgumentException('관리자 대시보드 항목 수는 음수일 수 없습니다.');
        }
        if ($this->progress !== null && ($this->progress < 0 || $this->progress > 100)) {
            throw new InvalidArgumentException('관리자 대시보드 진행률은 0~100이어야 합니다.');
        }
        if (! in_array($this->tone, ['neutral', 'info', 'success', 'warning', 'danger'], true)) {
            throw new InvalidArgumentException('관리자 대시보드 표시 색상이 올바르지 않습니다.');
        }
    }

    public function title(): string { return $this->title; }
    public function description(): string { return $this->description; }
    public function target(): RouteTarget { return $this->target; }
    public function count(): ?int { return $this->count; }
    public function badge(): string { return $this->badge; }
    public function tone(): string { return $this->tone; }
    public function meta(): string { return $this->meta; }
    public function progress(): ?int { return $this->progress; }
}
