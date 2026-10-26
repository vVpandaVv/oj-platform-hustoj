<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureBlocker;
use InvalidArgumentException;

final class AccountErasureImpact
{
    /** @param list<AccountErasureBlocker> $blockers */
    public function __construct(private readonly array $blockers)
    {
        foreach ($this->blockers as $blocker) {
            if (! $blocker instanceof AccountErasureBlocker) {
                throw new InvalidArgumentException('계정 익명화 영향 정보가 올바르지 않습니다.');
            }
        }
    }

    /** @return list<AccountErasureBlocker> */
    public function blockers(): array
    {
        return $this->blockers;
    }

    public function canRequest(): bool
    {
        return $this->blockers === [];
    }
}
