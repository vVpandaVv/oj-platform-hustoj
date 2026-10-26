<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianVerificationChallenge;
use CodingPanda\OjPlatform\Modules\Auth\Domain\AgreementDocument;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianConsentReview;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianRelationship;

interface GuardianVerificationRepository
{
    public function issue(
        string $childEmail,
        string $guardianName,
        string $guardianEmail,
        int $expiresHours,
        AgreementDocument $document
    ): GuardianVerificationChallenge;

    public function cancel(int $verificationId): void;

    public function pendingReview(string $token): ?GuardianConsentReview;

    public function confirm(string $token, int $documentId, GuardianRelationship $relationship): bool;

    public function isVerified(string $childEmail, string $code): bool;

    public function claimVerified(string $childEmail, string $code): ?int;

    public function bindClaim(int $verificationId, int $userId): void;

    public function releaseClaim(int $verificationId): void;

    public function hasConsumedVerification(int $userId): bool;
}
