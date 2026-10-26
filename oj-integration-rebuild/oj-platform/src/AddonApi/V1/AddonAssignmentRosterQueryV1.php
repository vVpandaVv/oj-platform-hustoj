<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonAssignmentRosterQueryV1
{
    public function __construct(
        private readonly int $actorUserId,
        private readonly string $contextType,
        private readonly string $contextId,
        private readonly string $expectedContextRevision,
        private readonly ?string $expectedRosterRevision = null,
        private readonly string $cursor = '',
        private readonly int $limit = 100
    ) {
        ValueValidator::positiveUserId($this->actorUserId, 'actorUserId');
        ValueValidator::oneOf($this->contextType, ['class', 'group'], 'contextType');
        ValueValidator::safeToken($this->contextId, 'contextId', 160);
        ValueValidator::safeToken(
            $this->expectedContextRevision,
            'expectedContextRevision',
            160
        );
        if ($this->expectedRosterRevision !== null) {
            ValueValidator::safeToken(
                $this->expectedRosterRevision,
                'expectedRosterRevision',
                160
            );
        }
        ValueValidator::text($this->cursor, 'cursor', 512, true, false);
        if (($this->cursor === '') !== ($this->expectedRosterRevision === null)) {
            throw new InvalidArgumentException('Addon API 명단 페이지 상태가 올바르지 않습니다.');
        }
        if ($this->limit < 1 || $this->limit > 100) {
            throw new InvalidArgumentException('Addon API 명단 조회 수량이 올바르지 않습니다.');
        }
    }

    public function actorUserId(): int { return $this->actorUserId; }
    public function contextType(): string { return $this->contextType; }
    public function contextId(): string { return $this->contextId; }
    public function expectedContextRevision(): string { return $this->expectedContextRevision; }
    public function expectedRosterRevision(): ?string { return $this->expectedRosterRevision; }
    public function cursor(): string { return $this->cursor; }
    public function limit(): int { return $this->limit; }
}
