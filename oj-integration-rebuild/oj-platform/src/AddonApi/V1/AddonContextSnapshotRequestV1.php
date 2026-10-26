<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

final class AddonContextSnapshotRequestV1
{
    public function __construct(
        private readonly int $actorUserId,
        private readonly string $contextType,
        private readonly string $contextId,
        private readonly string $expectedRevision
    ) {
        ValueValidator::positiveUserId($this->actorUserId, 'actorUserId');
        ValueValidator::oneOf($this->contextType, ['site', 'class', 'group', 'user'], 'contextType');
        ValueValidator::safeToken($this->contextId, 'contextId', 160);
        ValueValidator::safeToken($this->expectedRevision, 'expectedRevision', 160);
    }

    public function actorUserId(): int { return $this->actorUserId; }
    public function contextType(): string { return $this->contextType; }
    public function contextId(): string { return $this->contextId; }
    public function expectedRevision(): string { return $this->expectedRevision; }
}
