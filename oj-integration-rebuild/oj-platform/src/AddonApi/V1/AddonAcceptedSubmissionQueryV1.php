<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use DateTimeImmutable;
use InvalidArgumentException;

final class AddonAcceptedSubmissionQueryV1
{
    /** @param list<int> $learnerUserIds @param list<int> $problemIds */
    public function __construct(
        private readonly int $actorUserId,
        private readonly string $contextType,
        private readonly string $contextId,
        private readonly string $contextRevision,
        private readonly array $learnerUserIds,
        private readonly array $problemIds,
        private readonly ?string $submittedFrom = null,
        private readonly ?string $submittedUntil = null
    ) {
        ValueValidator::positiveUserId($this->actorUserId, 'actorUserId');
        ValueValidator::oneOf($this->contextType, ['class', 'group'], 'contextType');
        ValueValidator::safeToken($this->contextId, 'contextId');
        ValueValidator::safeToken($this->contextRevision, 'contextRevision', 160);
        if (! array_is_list($this->learnerUserIds) || count($this->learnerUserIds) > 500) {
            throw new InvalidArgumentException('Addon API 값이 올바르지 않습니다: learnerUserIds');
        }
        foreach ($this->learnerUserIds as $userId) {
            if (! is_int($userId) || $userId < 1) {
                throw new InvalidArgumentException('Addon API 값이 올바르지 않습니다: learnerUserIds');
            }
        }
        if (! array_is_list($this->problemIds) || count($this->problemIds) > 100) {
            throw new InvalidArgumentException('Addon API 값이 올바르지 않습니다: problemIds');
        }
        foreach ($this->problemIds as $problemId) {
            if (! is_int($problemId) || $problemId < 1) {
                throw new InvalidArgumentException('Addon API 값이 올바르지 않습니다: problemIds');
            }
        }
        if (! self::validDate($this->submittedFrom) || ! self::validDate($this->submittedUntil)
            || ($this->submittedFrom !== null && $this->submittedUntil !== null && $this->submittedFrom > $this->submittedUntil)) {
            throw new InvalidArgumentException('Addon API 값이 올바르지 않습니다: submitted range');
        }
    }

    public function actorUserId(): int { return $this->actorUserId; }
    public function contextType(): string { return $this->contextType; }
    public function contextId(): string { return $this->contextId; }
    public function contextRevision(): string { return $this->contextRevision; }
    /** @return list<int> */
    public function learnerUserIds(): array { return array_values(array_unique($this->learnerUserIds)); }
    /** @return list<int> */
    public function problemIds(): array { return array_values(array_unique($this->problemIds)); }
    public function submittedFrom(): ?string { return $this->submittedFrom; }
    public function submittedUntil(): ?string { return $this->submittedUntil; }

    private static function validDate(?string $value): bool
    {
        if ($value === null) {
            return true;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }
}
