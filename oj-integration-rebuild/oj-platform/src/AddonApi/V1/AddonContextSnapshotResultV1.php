<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use InvalidArgumentException;

final class AddonContextSnapshotResultV1
{
    public function __construct(
        private readonly bool $successful,
        private readonly ?AddonContextSnapshotV1 $snapshot,
        private readonly ?AddonApiProblemV1 $problem
    ) {
        if (
            ($this->successful && ($this->snapshot === null || $this->problem !== null))
            || (! $this->successful && ($this->snapshot !== null || $this->problem === null))
        ) {
            throw new InvalidArgumentException('Addon API Context snapshot 결과가 올바르지 않습니다.');
        }
    }

    public function isSuccessful(): bool { return $this->successful; }
    public function snapshot(): ?AddonContextSnapshotV1 { return $this->snapshot; }
    public function problem(): ?AddonApiProblemV1 { return $this->problem; }
}
