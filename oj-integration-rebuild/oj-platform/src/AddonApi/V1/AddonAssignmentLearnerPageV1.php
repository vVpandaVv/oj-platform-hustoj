<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonAssignmentLearnerPageV1
{
    /** @param list<AddonAssignmentLearnerV1> $items */
    public function __construct(
        private readonly bool $successful,
        private readonly array $items,
        private readonly string $nextCursor,
        private readonly ?string $rosterRevision,
        private readonly ?AddonApiProblemV1 $problem
    ) {
        if (! array_is_list($this->items) || count($this->items) > 100) {
            throw new InvalidArgumentException('Addon API 배정 명단이 올바르지 않습니다.');
        }
        $learnerIds = [];
        foreach ($this->items as $item) {
            if (! $item instanceof AddonAssignmentLearnerV1) {
                throw new InvalidArgumentException('Addon API 배정 명단이 올바르지 않습니다.');
            }
            if (isset($learnerIds[$item->learnerUserId()])) {
                throw new InvalidArgumentException('Addon API 배정 명단에 중복 학습자가 있습니다.');
            }
            $learnerIds[$item->learnerUserId()] = true;
        }
        ValueValidator::text($this->nextCursor, 'nextCursor', 512, true, false);
        if ($this->rosterRevision !== null) {
            ValueValidator::safeToken($this->rosterRevision, 'rosterRevision', 160);
        }
        if (
            ($this->successful && ($this->problem !== null || $this->rosterRevision === null))
            || (
                ! $this->successful
                && (
                    $this->problem === null
                    || $this->items !== []
                    || $this->nextCursor !== ''
                    || $this->rosterRevision !== null
                )
            )
        ) {
            throw new InvalidArgumentException('Addon API 배정 명단 결과 상태가 올바르지 않습니다.');
        }
    }

    public function isSuccessful(): bool { return $this->successful; }
    /** @return list<AddonAssignmentLearnerV1> */
    public function items(): array { return $this->items; }
    public function nextCursor(): string { return $this->nextCursor; }
    public function rosterRevision(): ?string { return $this->rosterRevision; }
    public function problem(): ?AddonApiProblemV1 { return $this->problem; }
}
