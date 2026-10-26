<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Shared\Ai;

use DomainException;
use LogicException;

final class AiTaskRouterRegistry implements AiTaskRouter
{
    private ?AiTaskRouter $router = null;

    public function register(AiTaskRouter $router): void
    {
        if ($router === $this || $this->router !== null) {
            throw new LogicException('공통 AI 작업 라우터는 하나만 등록할 수 있습니다.');
        }
        $this->router = $router;
    }

    public function canRoute(
        string $taskType,
        bool $requiresSubmissionSource = false
    ): bool {
        return $this->router?->canRoute(
            $taskType,
            $requiresSubmissionSource
        ) ?? false;
    }

    public function snapshot(
        string $taskType,
        bool $requiresSubmissionSource = false
    ): AiTaskAssignmentSnapshot {
        return $this->requiredRouter()->snapshot(
            $taskType,
            $requiresSubmissionSource
        );
    }

    public function execute(
        AiTaskAssignmentSnapshot $assignment,
        AiTaskRequest $request
    ): AiTaskResponse {
        return $this->requiredRouter()->execute($assignment, $request);
    }

    public function isConfigured(): bool
    {
        return $this->router !== null;
    }

    private function requiredRouter(): AiTaskRouter
    {
        if ($this->router === null) {
            throw new DomainException(
                'AI 연결 프로필과 작업별 배정을 먼저 설정해 주세요.'
            );
        }

        return $this->router;
    }
}
