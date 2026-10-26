<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Application;

use CodingPanda\OjPlatform\Modules\Settings\Contract\AiEndpointPolicy;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderException;
use CodingPanda\OjPlatform\Modules\Settings\Contract\StructuredAiProviderClient;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiStructuredOutput;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiEndpointAddress;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiTaskAssignment;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskAssignmentSnapshot;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskResponse;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use DomainException;
use Throwable;

final class StoredAiTaskRouter implements AiTaskRouter
{
    public function __construct(
        private readonly AiProviderCatalog $catalog,
        private readonly AiProviderSettingsService $providers,
        private readonly AiTaskRoutingService $routing,
        private readonly AiProviderClientRegistry $clients,
        private readonly AiEndpointPolicy $endpoints,
        private readonly AiTaskPromptFactory $prompts,
        private readonly ?AiUsageRecorder $usage = null
    ) {
    }

    public function canRoute(
        string $taskType,
        bool $requiresSubmissionSource = false
    ): bool {
        try {
            $requiresSubmissionSource = $requiresSubmissionSource
                || $this->catalog->requiresSubmissionSource($taskType);
            $assignment = $this->routing->find($taskType);
            if (! $assignment instanceof AiTaskAssignment) {
                return false;
            }
            $profile = $this->providers->find($assignment->primaryProfileKey());
            if (
                ! $profile instanceof AiProviderProfile
                || ! $profile->allowsTask($taskType)
                || ($requiresSubmissionSource && ! $profile->allowsSubmissionSource())
            ) {
                return false;
            }
            $fallbackKey = $assignment->fallbackProfileKey();
            if ($fallbackKey === null) {
                return true;
            }
            $fallback = $this->providers->find($fallbackKey);

            return $fallback instanceof AiProviderProfile
                && $fallback->allowsTask($taskType)
                && (! $requiresSubmissionSource || $fallback->allowsSubmissionSource());
        } catch (Throwable) {
            return false;
        }
    }

    public function snapshot(
        string $taskType,
        bool $requiresSubmissionSource = false
    ): AiTaskAssignmentSnapshot {
        $requiresSubmissionSource = $requiresSubmissionSource
            || $this->catalog->requiresSubmissionSource($taskType);
        $assignment = $this->routing->find($taskType);
        if (! $assignment instanceof AiTaskAssignment) {
            throw new DomainException('AI 작업에 연결 프로필이 배정되지 않았습니다.');
        }
        $primary = $this->requiredProfile(
            $assignment->primaryProfileKey(),
            $taskType,
            $requiresSubmissionSource
        );
        $fallback = $assignment->fallbackProfileKey() !== null
            ? $this->requiredProfile(
                $assignment->fallbackProfileKey(),
                $taskType,
                $requiresSubmissionSource
            )
            : null;

        return new AiTaskAssignmentSnapshot(
            $taskType,
            $this->routing->snapshot()->revision(),
            $primary->key(),
            $fallback?->key(),
            $this->assignmentFingerprint($assignment, $primary, $fallback)
        );
    }

    public function execute(
        AiTaskAssignmentSnapshot $assignment,
        AiTaskRequest $request
    ): AiTaskResponse {
        if ($request->taskType() !== $assignment->taskType()) {
            throw new DomainException('AI 작업 요청과 배정이 일치하지 않습니다.');
        }
        $current = $this->routing->findAtRevision(
            $assignment->taskType(),
            $assignment->routingRevision()
        );
        if (! $current instanceof AiTaskAssignment) {
            throw new DomainException('AI 작업 배정이 제거되었습니다.');
        }
        if (
            $current->primaryProfileKey() !== $assignment->primaryProfileKey()
            || $current->fallbackProfileKey() !== $assignment->fallbackProfileKey()
        ) {
            throw new DomainException(
                'AI 작업 배정이 작업 등록 시점과 달라졌습니다. '
                . '현재 설정으로 새 작업을 등록해 주세요.'
            );
        }
        $requiresSubmissionSource = $this->catalog->requiresSubmissionSource($assignment->taskType());
        $primary = $this->requiredProfile(
            $assignment->primaryProfileKey(),
            $assignment->taskType(),
            $requiresSubmissionSource
        );
        $fallback = $assignment->fallbackProfileKey() !== null
            ? $this->requiredProfile(
                $assignment->fallbackProfileKey(),
                $assignment->taskType(),
                $requiresSubmissionSource
            )
            : null;
        $fingerprint = $this->assignmentFingerprint($current, $primary, $fallback);
        if (! hash_equals($assignment->profileFingerprint(), $fingerprint)) {
            throw new DomainException(
                'AI 연결 프로필이 작업 등록 시점과 달라졌습니다. '
                . '현재 설정으로 새 작업을 등록해 주세요.'
            );
        }
        try {
            return $this->executeProfile(
                $primary,
                $request,
                $current->maxOutputTokens(),
                $fingerprint,
                $current->ollamaOptions()
            );
        } catch (AiProviderException $error) {
            if (
                ! $error->isTransient()
                || $fallback === null
                || $current->fallbackPolicy() !== 'transient_only'
            ) {
                throw $error;
            }

            return $this->executeProfile(
                $fallback,
                $request,
                $current->maxOutputTokens(),
                $fingerprint,
                $current->ollamaOptions()
            );
        }
    }

    private function executeProfile(
        AiProviderProfile $profile,
        AiTaskRequest $request,
        int $maxOutputTokens,
        string $assignmentFingerprint,
        array $ollamaOptions
    ): AiTaskResponse {
        $definition = $this->catalog->get($profile->providerKey());
        $endpoint = $this->endpoints->validate($definition, $profile->baseUrl());
        $currentIps = AiEndpointAddress::canonicalList($endpoint->resolvedIps());
        $storedIps = AiEndpointAddress::canonicalList($profile->resolvedIps());
        sort($currentIps);
        sort($storedIps);
        if ($currentIps !== $storedIps) {
            throw new DomainException('AI 서버 주소가 후보 검사 시점과 달라졌습니다.');
        }
        $client = $this->clients->get($definition->adapterKey());
        $messages = $this->prompts->messages($request);
        $schema = AiStructuredOutput::fromInput($request->input());
        $options = $definition->adapterKey() === 'ollama' ? $ollamaOptions : [];
        $generate = fn (): array => $client instanceof StructuredAiProviderClient
            ? $client->generateStructured($profile, $messages, $maxOutputTokens, $schema, $options)
            : $client->generate($profile, $messages, $maxOutputTokens);
        $output = $this->usage !== null
            ? $this->usage->withTask($request->taskType(), false, $generate)
            : $generate();

        return new AiTaskResponse(
            $output,
            $profile->key(),
            $profile->providerKey(),
            $profile->model(),
            $assignmentFingerprint
        );
    }

    private function requiredProfile(
        string $profileKey,
        string $taskType,
        bool $requiresSubmissionSource
    ): AiProviderProfile {
        $profile = $this->providers->find($profileKey);
        if (
            ! $profile instanceof AiProviderProfile
            || ! $profile->allowsTask($taskType)
            || ($requiresSubmissionSource && ! $profile->allowsSubmissionSource())
        ) {
            throw new DomainException('AI 연결 프로필이 현재 작업을 허용하지 않습니다.');
        }

        return $profile;
    }

    private function assignmentFingerprint(
        AiTaskAssignment $assignment,
        AiProviderProfile $primary,
        ?AiProviderProfile $fallback
    ): string {
        $parts = [
            $assignment->taskType(),
            $primary->fingerprint(),
            $fallback?->fingerprint() ?? '',
            (string) $assignment->maxOutputTokens(),
            $assignment->fallbackPolicy(),
            $this->catalog->taskContractFingerprint($assignment->taskType()),
        ];
        if ($assignment->ollamaOptions() !== []) {
            $parts[] = json_encode($assignment->ollamaOptions(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }
        return hash('sha256', implode('|', $parts));
    }
}
