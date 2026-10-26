<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonAcceptedSubmissionV1
{
    public function __construct(
        private readonly int $learnerUserId,
        private readonly int $problemId,
        private readonly string $acceptedAt
    ) {
        ValueValidator::positiveUserId($this->learnerUserId, 'learnerUserId');
        if ($this->problemId < 1) {
            throw new InvalidArgumentException('Addon API 값이 올바르지 않습니다: problemId');
        }
        ValueValidator::text($this->acceptedAt, 'acceptedAt', 32);
    }

    public function learnerUserId(): int { return $this->learnerUserId; }
    public function problemId(): int { return $this->problemId; }
    public function acceptedAt(): string { return $this->acceptedAt; }
}
