<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

final class AddonCodeCompletionQueryV1
{
    public function __construct(
        private readonly int $actorUserId,
        private readonly int $problemId,
        private readonly string $contextType = 'general',
        private readonly ?int $contextId = null
    ) {
        new AddonLearningCheckContextQueryV1($actorUserId, $problemId, null, $contextType, $contextId);
    }

    public function actorUserId(): int { return $this->actorUserId; }
    public function problemId(): int { return $this->problemId; }
    public function contextType(): string { return $this->contextType; }
    public function contextId(): ?int { return $this->contextId; }
}
