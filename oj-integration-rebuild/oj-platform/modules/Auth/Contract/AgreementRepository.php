<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Domain\AgreementDocument;

interface AgreementRepository
{
    /**
     * @return list<AgreementDocument>
     */
    public function activeDocuments(): array;

    public function activeDocumentByType(string $typeSlug): ?AgreementDocument;

    /**
     * @param list<int> $documentIds
     */
    public function recordAgreement(
        int $userId,
        array $documentIds,
        ?string $ipAddress,
        ?string $userAgent
    ): void;

    /**
     * @return list<int>
     */
    public function agreedDocumentIds(int $userId): array;

    public function publish(
        string $typeSlug,
        string $typeLabel,
        string $version,
        string $title,
        string $content,
        bool $required,
        int $createdByUserId
    ): AgreementDocument;
}
