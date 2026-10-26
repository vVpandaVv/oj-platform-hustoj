<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Contract;

use CodingPanda\OjPlatform\Modules\AI\Domain\FeedbackGuide;

interface FeedbackGuideRepository
{
    /**
     * @return list<FeedbackGuide>
     */
    public function all(bool $includeArchived = false): array;

    public function find(string $key): ?FeedbackGuide;

    public function save(
        ?string $key,
        string $name,
        string $level,
        string $purpose,
        string $instructions,
        int $actorUserId
    ): FeedbackGuide;

    public function duplicate(
        string $key,
        int $actorUserId
    ): FeedbackGuide;

    public function setArchived(
        string $key,
        bool $archived,
        int $actorUserId
    ): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function revisions(string $key): array;
}
