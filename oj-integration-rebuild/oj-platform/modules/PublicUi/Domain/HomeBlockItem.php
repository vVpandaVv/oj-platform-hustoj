<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Domain;

use CodingPanda\OjPlatform\Ui\View\RouteTarget;
use InvalidArgumentException;

final class HomeBlockItem
{
    public function __construct(
        private readonly string $title,
        private readonly string $description,
        private readonly RouteTarget $target,
        private readonly string $badge = '',
        private readonly string $tone = 'neutral',
        private readonly string $meta = '',
        private readonly string $actionLabel = '열기'
    ) {
        if (trim($this->title) === '') {
            throw new InvalidArgumentException('홈 항목 제목은 비어 있을 수 없습니다.');
        }
        if (! in_array($this->tone, ['neutral', 'info', 'success', 'warning', 'danger'], true)) {
            throw new InvalidArgumentException('홈 항목 표시 색상이 올바르지 않습니다.');
        }
    }

    public function title(): string { return $this->title; }
    public function description(): string { return $this->description; }
    public function target(): RouteTarget { return $this->target; }
    public function badge(): string { return $this->badge; }
    public function tone(): string { return $this->tone; }
    public function meta(): string { return $this->meta; }
    public function actionLabel(): string { return $this->actionLabel; }
}
