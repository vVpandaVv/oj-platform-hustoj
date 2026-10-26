<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Home;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlock;
use DateTimeImmutable;
use InvalidArgumentException;

final class HomePageState
{
    /** @param list<HomeBlock> $blocks */
    public function __construct(
        private readonly UserIdentity $identity,
        private readonly DateTimeImmutable $now,
        private readonly array $blocks
    ) {
        foreach ($this->blocks as $block) {
            if (! $block instanceof HomeBlock) {
                throw new InvalidArgumentException('홈 화면 블록 형식이 올바르지 않습니다.');
            }
        }
    }

    public function identity(): UserIdentity { return $this->identity; }
    public function now(): DateTimeImmutable { return $this->now; }
    /** @return list<HomeBlock> */
    public function blocks(): array { return $this->blocks; }
}
