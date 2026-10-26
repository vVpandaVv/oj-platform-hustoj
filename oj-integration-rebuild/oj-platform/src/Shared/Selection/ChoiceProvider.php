<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Shared\Selection;

interface ChoiceProvider
{
    public function isAvailable(): bool;

    /**
     * @param list<int> $includedIds
     * @return array<int, string>
     */
    public function choicesFor(int $userId, array $includedIds = []): array;
}
