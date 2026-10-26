<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Application;

use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDisableGuard;
use LogicException;

final class FeatureDisableGuardRegistry
{
    /** @var array<string, FeatureDisableGuard> */
    private array $guards = [];

    public function register(FeatureDisableGuard $guard): void
    {
        $featureKey = $guard->featureKey();
        if (isset($this->guards[$featureKey])) {
            throw new LogicException(sprintf(
                '기능 비활성 사전 검사기가 중복 등록되었습니다: %s',
                $featureKey
            ));
        }
        $this->guards[$featureKey] = $guard;
    }

    /** @return list<string> */
    public function blockers(string $featureKey): array
    {
        $guard = $this->guards[$featureKey] ?? null;

        return $guard === null ? [] : array_values(array_unique(array_filter(
            array_map('trim', $guard->blockers()),
            static fn (string $blocker): bool => $blocker !== ''
        )));
    }
}
