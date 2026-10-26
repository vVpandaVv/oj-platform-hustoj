<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard;

use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardBlock;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use DateTimeImmutable;
use InvalidArgumentException;

final class DashboardPageState
{
    /** @param list<DashboardBlock> $blocks */
    public function __construct(
        private readonly UserIdentity $identity,
        private readonly DateTimeImmutable $now,
        private readonly string $roleLabel,
        private readonly bool $approvalOnly,
        private readonly array $blocks
    ) {
        foreach ($this->blocks as $block) {
            if (! $block instanceof DashboardBlock) {
                throw new InvalidArgumentException('관리자 대시보드 블록 형식이 올바르지 않습니다.');
            }
        }
    }

    public function identity(): UserIdentity { return $this->identity; }
    public function now(): DateTimeImmutable { return $this->now; }
    public function roleLabel(): string { return $this->roleLabel; }
    public function approvalOnly(): bool { return $this->approvalOnly; }
    /** @return list<DashboardBlock> */
    public function blocks(): array { return $this->blocks; }
}
