<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionReservation;

interface SubmissionEventStore
{
    /**
     * @param array<string, scalar|null> $metadata
     */
    public function reserve(
        string $requestKey,
        int $actorUserId,
        array $metadata
    ): SubmissionReservation;

    /**
     * @param array<string, scalar|null> $metadata
     */
    public function complete(
        int $eventId,
        SubmissionId $submissionId,
        array $metadata
    ): void;

    /**
     * @param array<string, scalar|null> $metadata
     */
    public function fail(
        int $eventId,
        string $eventType,
        string $message,
        array $metadata
    ): void;

    /**
     * @param array<string, scalar|null> $metadata
     */
    public function record(
        string $eventType,
        int $actorUserId,
        ?SubmissionId $submissionId,
        ?string $requestKey,
        string $message,
        array $metadata = []
    ): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 100): array;
}
