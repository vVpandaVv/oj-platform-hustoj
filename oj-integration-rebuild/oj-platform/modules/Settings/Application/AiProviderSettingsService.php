<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Application;

use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretReference;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretVault;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use DomainException;
use Throwable;

final class AiProviderSettingsService
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly SecretVault $vault
    ) {
    }

    public function snapshot(): SettingsSnapshot
    {
        return $this->settings->read('ai_provider');
    }

    /**
     * @return list<AiProviderProfile>
     */
    public function profiles(): array
    {
        $reference = $this->snapshot()->value();
        if ($reference === null) {
            return [];
        }
        if (! $reference instanceof SecretReference) {
            throw new DomainException('AI 연결 프로필 비밀값 참조가 올바르지 않습니다.');
        }
        $payload = $this->vault->reveal($reference);
        if (! is_array($payload) || ! is_array($payload['profiles'] ?? null)) {
            throw new DomainException('AI 연결 프로필을 해석할 수 없습니다.');
        }
        $profiles = [];
        foreach ($payload['profiles'] as $value) {
            if (! is_array($value)) {
                throw new DomainException('AI 연결 프로필 저장 형식이 올바르지 않습니다.');
            }
            $profiles[] = AiProviderProfile::fromArray($value);
        }
        usort(
            $profiles,
            static fn (AiProviderProfile $left, AiProviderProfile $right): int =>
                strcmp($left->key(), $right->key())
        );

        return $profiles;
    }

    public function find(string $profileKey): ?AiProviderProfile
    {
        foreach ($this->profiles() as $profile) {
            if ($profile->key() === $profileKey) {
                return $profile;
            }
        }

        return null;
    }

    public function saveProfile(
        AiProviderProfile $profile,
        int $expectedRevision
    ): SettingsSnapshot {
        $currentSnapshot = $this->snapshot();
        if ($currentSnapshot->revision() !== $expectedRevision) {
            throw new DomainException('AI 연결 프로필이 다른 요청에서 변경되었습니다.');
        }
        $profiles = [];
        $replaced = false;
        foreach ($this->profiles() as $existing) {
            if ($existing->key() === $profile->key()) {
                $profiles[] = $profile;
                $replaced = true;
            } else {
                $profiles[] = $existing;
            }
        }
        if (! $replaced) {
            $profiles[] = $profile;
        }
        usort(
            $profiles,
            static fn (AiProviderProfile $left, AiProviderProfile $right): int =>
                strcmp($left->key(), $right->key())
        );
        $newReference = $this->vault->store([
            'profiles' => array_map(
                static fn (AiProviderProfile $item): array => $item->toArray(),
                $profiles
            ),
        ]);
        try {
            $saved = $this->settings->save(
                'ai_provider',
                $newReference,
                $expectedRevision
            );
        } catch (Throwable $error) {
            $this->vault->discard($newReference);
            throw $error;
        }
        $oldReference = $currentSnapshot->value();
        if ($oldReference instanceof SecretReference) {
            $this->vault->discard($oldReference);
        }

        return $saved;
    }
}
