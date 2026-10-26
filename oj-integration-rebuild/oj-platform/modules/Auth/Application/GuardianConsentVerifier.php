<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\GuardianVerificationRepository;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianConsentClaim;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationPolicy;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Clock\SystemClock;
use DateTimeImmutable;
use DomainException;

final class GuardianConsentVerifier
{
    public function __construct(
        private readonly GuardianVerificationRepository $verifications,
        private readonly Clock $clock = new SystemClock()
    ) {
    }

    public function claim(
        RegistrationPolicy $policy,
        string $email,
        ?string $birthDate,
        ?string $guardianCode,
        bool $alreadyVerified = false
    ): GuardianConsentClaim {
        if (! $policy->guardianConsentEnabled()) {
            return new GuardianConsentClaim(false);
        }
        if ($birthDate === null) {
            throw new DomainException('법정대리인 동의 사용 시 생년을 입력해야 합니다.');
        }

        $now = $this->clock->now();
        $birth = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $birthDate,
            $now->getTimezone()
        );
        $errors = DateTimeImmutable::getLastErrors();
        $today = $now->setTime(0, 0);
        if (
            ! $birth instanceof DateTimeImmutable
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $birth > $today
        ) {
            throw new DomainException('생년을 실제 날짜로 입력해야 합니다.');
        }
        if ($birth->diff($today)->y >= $policy->guardianConsentAge()) {
            return new GuardianConsentClaim(false);
        }
        if ($alreadyVerified) {
            return new GuardianConsentClaim(true);
        }

        $guardianCode = $this->nullableTrimmed($guardianCode);
        if ($guardianCode === null) {
            throw new DomainException('법정대리인 동의 확인 코드가 필요합니다.');
        }
        $verificationId = $this->verifications->claimVerified(
            $email,
            $guardianCode
        );
        if ($verificationId === null) {
            throw new DomainException('법정대리인 동의가 확인되지 않았습니다.');
        }

        return new GuardianConsentClaim(true, $verificationId);
    }

    public function bind(GuardianConsentClaim $claim, int $userId): void
    {
        if ($claim->verificationId() !== null) {
            $this->verifications->bindClaim($claim->verificationId(), $userId);
        }
    }

    public function release(GuardianConsentClaim $claim): void
    {
        if ($claim->verificationId() !== null) {
            $this->verifications->releaseClaim($claim->verificationId());
        }
    }

    public function hasVerifiedUser(int $userId): bool
    {
        return $this->verifications->hasConsumedVerification($userId);
    }

    private function nullableTrimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
