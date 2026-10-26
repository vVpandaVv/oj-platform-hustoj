<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\RegistrationPolicyProvider;
use CodingPanda\OjPlatform\Modules\Auth\Domain\ApprovalMode;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationPolicy;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;

final class SettingsRegistrationPolicyProvider implements RegistrationPolicyProvider
{
    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function current(): RegistrationPolicy
    {
        $value = $this->settings->read('registration', [])->value();
        if (! is_array($value)) {
            return RegistrationPolicy::defaults();
        }

        $requiredTypes = $value['required_agreement_types'] ?? ['terms', 'privacy'];
        if (! is_array($requiredTypes)) {
            $requiredTypes = ['terms', 'privacy'];
        }

        $requiredTypes = array_values(array_filter(
            array_map('strval', $requiredTypes),
            static fn (string $type): bool => preg_match('/^[a-z][a-z0-9_-]{1,99}$/', $type) === 1
        ));

        return new RegistrationPolicy(
            (bool) ($value['enabled'] ?? false),
            ApprovalMode::tryFrom((string) ($value['approval_mode'] ?? 'manual'))
                ?? ApprovalMode::Manual,
            (string) ($value['default_role'] ?? 'subscriber'),
            $requiredTypes,
            (bool) ($value['guardian_consent_enabled'] ?? false),
            (int) ($value['guardian_consent_age'] ?? 14),
            (int) ($value['guardian_consent_expires_hours'] ?? 24)
        );
    }
}
