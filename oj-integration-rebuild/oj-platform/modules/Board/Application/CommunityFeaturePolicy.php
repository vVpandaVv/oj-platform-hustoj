<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Application;

use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use DomainException;
use Throwable;

final class CommunityFeaturePolicy
{
    public function __construct(private readonly FeatureFlags $features)
    {
    }

    public function boardEnabled(): bool
    {
        return $this->enabled('board');
    }

    public function noticesEnabled(): bool
    {
        return $this->enabled('notices');
    }

    public function anyEnabled(): bool
    {
        return $this->boardEnabled() || $this->noticesEnabled();
    }

    public function requireBoard(): void
    {
        if (! $this->boardEnabled()) {
            throw new DomainException('현재 게시판 기능을 사용할 수 없습니다.');
        }
    }

    public function requireNotices(): void
    {
        if (! $this->noticesEnabled()) {
            throw new DomainException('현재 공지 기능을 사용할 수 없습니다.');
        }
    }

    public function requireAny(): void
    {
        if (! $this->anyEnabled()) {
            throw new DomainException('현재 사용할 수 있는 커뮤니티 기능이 없습니다.');
        }
    }

    private function enabled(string $feature): bool
    {
        try {
            return $this->features->isEnabled($feature);
        } catch (Throwable) {
            return false;
        }
    }
}
