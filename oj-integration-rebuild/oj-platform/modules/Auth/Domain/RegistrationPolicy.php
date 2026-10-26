<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

use InvalidArgumentException;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementDocumentType;

final class RegistrationPolicy
{
    /**
     * @param list<string> $requiredAgreementTypes
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly ApprovalMode $approvalMode,
        private readonly string $defaultRole,
        private readonly array $requiredAgreementTypes,
        private readonly bool $guardianConsentEnabled,
        private readonly int $guardianConsentAge,
        private readonly int $guardianConsentExpiresHours
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $this->defaultRole) !== 1) {
            throw new InvalidArgumentException('가입 기본 역할 형식이 올바르지 않습니다.');
        }

        if ($this->guardianConsentAge < 1 || $this->guardianConsentAge > 18) {
            throw new InvalidArgumentException('법정대리인 동의 나이는 1~18이어야 합니다.');
        }

        if ($this->guardianConsentExpiresHours < 1 || $this->guardianConsentExpiresHours > 168) {
            throw new InvalidArgumentException('법정대리인 동의 만료 시간은 1~168시간이어야 합니다.');
        }
    }

    public static function defaults(): self
    {
        return new self(
            false,
            ApprovalMode::Manual,
            'subscriber',
            ['terms', 'privacy'],
            false,
            14,
            24
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function approvalMode(): ApprovalMode
    {
        return $this->approvalMode;
    }

    public function defaultRole(): string
    {
        return $this->defaultRole;
    }

    /**
     * @return list<string>
     */
    public function requiredAgreementTypes(): array
    {
        return array_values(array_filter(
            $this->requiredAgreementTypes,
            static fn (string $type): bool => ! (AgreementDocumentType::tryFrom($type)?->isGuardianOnly() ?? false)
        ));
    }

    public function guardianConsentEnabled(): bool
    {
        return $this->guardianConsentEnabled;
    }

    public function guardianConsentAge(): int
    {
        return $this->guardianConsentAge;
    }

    public function guardianConsentExpiresHours(): int
    {
        return $this->guardianConsentExpiresHours;
    }
}
