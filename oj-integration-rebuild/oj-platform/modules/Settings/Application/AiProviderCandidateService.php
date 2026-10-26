<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Application;

use CodingPanda\OjPlatform\Modules\Settings\Contract\CandidateProof;
use CodingPanda\OjPlatform\Modules\Settings\Contract\CandidateProofStore;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretVault;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use DomainException;
use Throwable;

final class AiProviderCandidateService
{
    private const PROOF_PREFIX = 'ai_provider.';

    public function __construct(
        private readonly AiProviderProbeService $probes,
        private readonly AiProviderSettingsService $profiles,
        private readonly CandidateProofStore $proofs,
        private readonly SecretVault $vault
    ) {
    }

    /**
     * @param list<string> $taskTypes
     */
    public function test(
        string $profileKey,
        string $name,
        string $providerKey,
        string $baseUrl,
        string $authenticationMethod,
        string $secret,
        string $model,
        array $taskTypes,
        bool $allowSubmissionSource,
        int $timeoutSeconds,
        int $actorUserId,
        ?string $copySourceKey = null
    ): CandidateProof {
        $existingProfile = $this->profiles->find($profileKey);
        $copySource = null;
        if ($copySourceKey !== null) {
            $copySourceKey = trim($copySourceKey);
            $this->scope($copySourceKey);
            if ($existingProfile instanceof AiProviderProfile) {
                throw new DomainException('복제할 프로필에는 사용하지 않는 새 프로필 키를 입력해 주세요. 기존 프로필은 덮어쓰지 않습니다.');
            }
            $copySource = $this->profiles->find($copySourceKey);
            if (! $copySource instanceof AiProviderProfile) {
                throw new DomainException('복제할 원본 AI 연결 프로필을 찾을 수 없습니다.');
            }
        }
        $credentialProfile = $copySource ?? $existingProfile;
        if ($secret === '' && $credentialProfile instanceof AiProviderProfile) {
            if (
                $providerKey !== $credentialProfile->providerKey()
                || rtrim($baseUrl, '/') !== rtrim($credentialProfile->baseUrl(), '/')
                || $authenticationMethod !== $credentialProfile->authenticationMethod()
            ) {
                throw new DomainException('연결 대상이나 인증 방식을 바꾸면 API 비밀값을 다시 입력해 주세요.');
            }
            $secret = $credentialProfile->secret();
        }
        $profile = $this->probes->inspect(
            $profileKey,
            $name,
            $providerKey,
            $baseUrl,
            $authenticationMethod,
            $secret,
            $model,
            $taskTypes,
            $allowSubmissionSource,
            $timeoutSeconds
        );
        $scope = $this->scope($profileKey);
        $existingProof = $this->proofs->find($scope, $actorUserId);
        if ($existingProof !== null) {
            $this->proofs->forget($scope, $actorUserId);
        }
        $reference = $this->vault->store($profile->toArray());
        $proof = new CandidateProof(
            $profile->fingerprint(),
            $reference,
            $actorUserId,
            time() + 900,
            [
                'result_code' => 'ai_provider_probe_succeeded',
                'provider_key' => $profile->providerKey(),
                'model' => $profile->model(),
                'allowed_task_types' => $profile->allowedTaskTypes(),
                'tested_at' => gmdate('c'),
                ...($copySource !== null ? ['copy_source_key' => $copySource->key()] : []),
            ]
        );
        try {
            $this->proofs->remember($scope, $proof);
        } catch (Throwable $error) {
            $this->vault->discard($reference);
            throw $error;
        }

        return $proof;
    }

    public function saveTested(
        string $profileKey,
        int $actorUserId,
        int $expectedRevision
    ): SettingsSnapshot {
        if ($this->profiles->snapshot()->revision() !== $expectedRevision) {
            throw new DomainException('AI 연결 프로필이 다른 요청에서 변경되었습니다.');
        }
        $scope = $this->scope($profileKey);
        $proof = $this->proofs->consume($scope, $actorUserId);
        if ($proof === null || $proof->isExpired(time())) {
            throw new DomainException('유효한 AI 연결 후보 검사 결과가 없습니다.');
        }
        try {
            if (isset($proof->safeResult()['copy_source_key']) && $this->profiles->find($profileKey) !== null) {
                throw new DomainException('복제 대상 프로필 키가 이미 사용 중입니다. 새 키로 다시 검사해 주세요. 기존 프로필은 덮어쓰지 않습니다.');
            }
            $payload = $this->vault->reveal($proof->payloadReference());
            if (! is_array($payload)) {
                throw new DomainException('AI 연결 후보를 해석할 수 없습니다.');
            }
            $profile = AiProviderProfile::fromArray($payload);
            if (
                $profile->key() !== $profileKey
                || ! hash_equals($proof->fingerprint(), $profile->fingerprint())
            ) {
                throw new DomainException('검사한 AI 연결 후보가 현재 요청과 일치하지 않습니다.');
            }

            return $this->profiles->saveProfile($profile, $expectedRevision);
        } finally {
            $this->vault->discard($proof->payloadReference());
        }
    }

    public function proofFor(
        string $profileKey,
        int $actorUserId
    ): ?CandidateProof {
        return $this->proofs->find($this->scope($profileKey), $actorUserId);
    }

    private function scope(string $profileKey): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $profileKey) !== 1) {
            throw new DomainException('AI 연결 프로필 키가 올바르지 않습니다.');
        }

        return self::PROOF_PREFIX . $profileKey;
    }
}
