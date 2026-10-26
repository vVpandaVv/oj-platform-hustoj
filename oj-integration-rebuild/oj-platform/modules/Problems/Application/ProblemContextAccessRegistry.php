<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemContextAccessProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use LogicException;
use Throwable;

final class ProblemContextAccessRegistry
{
    /** @var array<string, ProblemContextAccessProvider> */
    private array $providers = [];

    public function register(ProblemContextAccessProvider $provider): void
    {
        $scope = trim($provider->scope());
        if ($scope === '' || $scope === 'general') {
            throw new LogicException('문제 접근 문맥 범위가 올바르지 않습니다.');
        }
        if (isset($this->providers[$scope])) {
            throw new LogicException(
                '문제 접근 문맥 Provider가 중복 등록되었습니다: ' . $scope
            );
        }

        $this->providers[$scope] = $provider;
    }

    public function canView(
        int $userId,
        int $problemId,
        ProblemAccessContext $context
    ): bool {
        if ($context->isGeneral()) {
            return false;
        }
        $provider = $this->providers[$context->scope()] ?? null;
        if ($provider === null) {
            return false;
        }

        try {
            return $provider->canView($userId, $problemId, $context);
        } catch (Throwable) {
            return false;
        }
    }
}
