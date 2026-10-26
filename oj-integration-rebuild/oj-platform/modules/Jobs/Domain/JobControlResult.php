<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Domain;

use InvalidArgumentException;

final class JobControlResult
{
    /**
     * @param list<string> $discardedPayloadReferences
     */
    public function __construct(
        private readonly int $affectedCount,
        private readonly array $discardedPayloadReferences = []
    ) {
        if ($this->affectedCount < 0) {
            throw new InvalidArgumentException('Jobs 변경 항목 수가 올바르지 않습니다.');
        }
    }

    public function affectedCount(): int
    {
        return $this->affectedCount;
    }

    /**
     * @return list<string>
     */
    public function discardedPayloadReferences(): array
    {
        return $this->discardedPayloadReferences;
    }
}
