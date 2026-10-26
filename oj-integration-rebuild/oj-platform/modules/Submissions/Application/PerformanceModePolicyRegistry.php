<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\PerformanceModePolicy;
use InvalidArgumentException;

final class PerformanceModePolicyRegistry implements PerformanceModePolicy
{
    /**
     * @var array<string, PerformanceModePolicy>
     */
    private array $policies = [];

    public function register(
        string $ownerModule,
        PerformanceModePolicy $policy
    ): void {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $ownerModule) !== 1) {
            throw new InvalidArgumentException(
                '수행 모드 정책 소유 모듈 키가 올바르지 않습니다.'
            );
        }
        if (isset($this->policies[$ownerModule])) {
            throw new InvalidArgumentException(
                '수행 모드 정책이 중복되었습니다: ' . $ownerModule
            );
        }
        $this->policies[$ownerModule] = $policy;
    }

    public function restrictsOwnSubmissionDetails(int $userId): bool
    {
        foreach ($this->policies as $policy) {
            if ($policy->restrictsOwnSubmissionDetails($userId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function owners(): array
    {
        $owners = array_keys($this->policies);
        sort($owners);

        return $owners;
    }
}
