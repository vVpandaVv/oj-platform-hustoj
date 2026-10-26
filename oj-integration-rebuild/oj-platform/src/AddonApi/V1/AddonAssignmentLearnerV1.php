<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

final class AddonAssignmentLearnerV1
{
    public function __construct(
        private readonly int $learnerUserId,
        private readonly string $membershipRevision,
        private readonly string $displayName = '',
        private readonly string $managementName = ''
    ) {
        ValueValidator::positiveUserId($this->learnerUserId, 'learnerUserId');
        ValueValidator::safeToken($this->membershipRevision, 'membershipRevision', 160);
        ValueValidator::text($this->displayName, 'displayName', 120, true);
        ValueValidator::text($this->managementName, 'managementName', 120, true);
    }

    public function learnerUserId(): int { return $this->learnerUserId; }
    public function membershipRevision(): string { return $this->membershipRevision; }
    public function displayName(): string { return $this->displayName; }
    public function managementName(): string
    {
        return trim($this->managementName) !== '' ? $this->managementName : $this->displayName;
    }
}
