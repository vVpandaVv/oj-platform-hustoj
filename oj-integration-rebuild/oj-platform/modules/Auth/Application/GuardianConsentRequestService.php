<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\GuardianConsentNotifier;
use CodingPanda\OjPlatform\Modules\Auth\Contract\GuardianVerificationRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\RegistrationPolicyProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementDocumentType;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianConsentReview;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianRelationship;
use DomainException;
use Throwable;

final class GuardianConsentRequestService
{
    public function __construct(
        private readonly GuardianVerificationRepository $verifications,
        private readonly GuardianConsentNotifier $notifier,
        private readonly RegistrationPolicyProvider $policies,
        private readonly AgreementRepository $agreements
    ) {
    }

    public function request(
        string $childEmail,
        string $guardianName,
        string $guardianEmail
    ): void {
        $policy = $this->policies->current();
        if (! $policy->isEnabled() || ! $policy->guardianConsentEnabled()) {
            throw new DomainException('현재 법정대리인 동의 요청을 받지 않습니다.');
        }

        $document = $this->agreements->activeDocumentByType(AgreementDocumentType::GuardianConsent->value);
        if ($document === null || trim(strip_tags($document->content())) === '') {
            throw new DomainException('법정대리인 동의서가 준비되지 않았습니다. 운영자에게 문의해 주세요.');
        }

        $challenge = $this->verifications->issue(
            $childEmail,
            $guardianName,
            $guardianEmail,
            $policy->guardianConsentExpiresHours(),
            $document
        );

        try {
            $this->notifier->send(
                $childEmail,
                $guardianName,
                $guardianEmail,
                $challenge
            );
        } catch (Throwable $error) {
            $this->verifications->cancel($challenge->verificationId());

            throw $error;
        }
    }

    public function isVerified(string $childEmail, string $code): bool
    {
        return $this->verifications->isVerified($childEmail, $code);
    }

    public function pendingReview(string $token): ?GuardianConsentReview
    {
        $policy = $this->policies->current();
        return $policy->isEnabled() && $policy->guardianConsentEnabled()
            ? $this->verifications->pendingReview($token)
            : null;
    }

    public function confirm(string $token, int $documentId, GuardianRelationship $relationship): bool
    {
        $review = $this->pendingReview($token);
        return $review !== null && $review->document->documentId() === $documentId
            && $this->verifications->confirm($token, $documentId, $relationship);
    }
}
