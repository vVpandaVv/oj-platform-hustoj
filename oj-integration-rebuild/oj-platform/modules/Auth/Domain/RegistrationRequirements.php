<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

final class RegistrationRequirements
{
    /**
     * @var list<int>
     */
    private readonly array $agreementIds;

    /**
     * @param list<int> $agreementDocumentIds
     */
    public function __construct(
        array $agreementDocumentIds,
        private readonly bool $guardianConsentRequired
    ) {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $agreementDocumentIds),
            static fn (int $id): bool => $id > 0
        )));
        sort($ids);
        $this->agreementIds = $ids;
    }

    /**
     * @return list<int>
     */
    public function agreementDocumentIds(): array
    {
        return $this->agreementIds;
    }

    public function guardianConsentRequired(): bool
    {
        return $this->guardianConsentRequired;
    }
}
