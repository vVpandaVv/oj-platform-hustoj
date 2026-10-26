<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;

interface ProblemTopicLinkRepository
{
    /**
     * @return list<string>
     */
    public function keysFor(ProblemId $problemId): array;

    /**
     * @param list<ProblemId> $problemIds
     * @return array<int, list<string>>
     */
    public function keysForMany(array $problemIds): array;

    /**
     * @param list<string> $topicKeys
     */
    public function replace(
        ProblemId $problemId,
        array $topicKeys,
        string $dictionaryVersion
    ): void;

    public function move(ProblemId $from, ProblemId $to): void;
}
