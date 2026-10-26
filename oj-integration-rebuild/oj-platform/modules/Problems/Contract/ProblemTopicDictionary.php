<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

interface ProblemTopicDictionary
{
    /**
     * @return list<ProblemTopicRecord>
     */
    public function all(): array;

    public function find(string $key): ?ProblemTopicRecord;

    /**
     * @param list<string> $detailKeys
     */
    public function assertSelectableDetails(array $detailKeys): void;

    public function version(): string;

    public function createCategory(
        string $key,
        string $label,
        array $aliases,
        int $actorUserId
    ): void;

    public function createDetail(
        string $categoryKey,
        string $key,
        string $label,
        array $aliases,
        int $actorUserId
    ): void;
}
