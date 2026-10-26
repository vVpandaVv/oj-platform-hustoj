<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use DomainException;

final class ProblemTopicService
{
    public function __construct(
        private readonly ProblemTopicDictionary $topics,
        private readonly ProblemAccessPolicy $access
    ) {
    }

    /**
     * @param list<string> $aliases
     */
    public function createCategory(
        int $actorUserId,
        string $key,
        string $label,
        array $aliases
    ): void {
        if (! $this->access->canManageTopics($actorUserId)) {
            throw new DomainException('문제 분야는 최고 관리자만 추가할 수 있습니다.');
        }
        $this->topics->createCategory($key, $label, $aliases, $actorUserId);
    }

    /**
     * @param list<string> $aliases
     */
    public function createDetail(
        int $actorUserId,
        string $categoryKey,
        string $key,
        string $label,
        array $aliases
    ): void {
        if (! $this->access->canManageTopics($actorUserId)) {
            throw new DomainException('세부 주제는 최고 관리자만 추가할 수 있습니다.');
        }
        $this->topics->createDetail(
            $categoryKey,
            $key,
            $label,
            $aliases,
            $actorUserId
        );
    }
}
