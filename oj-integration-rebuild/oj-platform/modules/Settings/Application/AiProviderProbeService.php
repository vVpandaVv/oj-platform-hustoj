<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Application;

use CodingPanda\OjPlatform\Modules\Settings\Contract\AiEndpointPolicy;
use CodingPanda\OjPlatform\Modules\Settings\Contract\StructuredAiProviderClient;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiStructuredOutput;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest;
use DomainException;

final class AiProviderProbeService
{
    /**
     * Hard ceiling on the whole inspect() call, across the model-list
     * lookup and every requested task type's generation probe combined —
     * kept safely under a typical reverse-proxy read timeout (60s) so a
     * slow/misconfigured provider can't outlast the gateway itself. Each
     * network call gets whatever share of this budget remains when it
     * starts, so a single selected task type gets a generous allowance
     * for real inference latency while several selected task types share
     * the same fixed ceiling instead of multiplying it. The profile's
     * real `timeoutSeconds` (used once saved, for live task requests) is
     * left untouched — this budget only bounds the interactive test.
     */
    private const PROBE_BUDGET_SECONDS = 45;

    /**
     * Below this many remaining seconds, a live provider call is
     * unlikely to finish before the budget runs out (also the profile
     * value object's own minimum timeoutSeconds), so probing stops here
     * instead of starting a call doomed to be cut short.
     */
    private const PROBE_MIN_CALL_SECONDS = 10;

    /**
     * Generous enough that a real task output (e.g. sample testcase file
     * contents, a full problem draft) doesn't get cut off mid-JSON before
     * the model finishes — a probe that's too tight here fails with an
     * opaque "response isn't a JSON object" error instead of a length one.
     */
    private const PROBE_MAX_OUTPUT_TOKENS = 4096;

    public function __construct(
        private readonly AiProviderCatalog $catalog,
        private readonly AiProviderClientRegistry $clients,
        private readonly AiEndpointPolicy $endpoints,
        private readonly AiTaskPromptFactory $prompts,
        private readonly ?AiUsageRecorder $usage = null
    ) {
    }

    /**
     * @param list<string> $requestedTaskTypes
     */
    public function inspect(
        string $profileKey,
        string $name,
        string $providerKey,
        string $baseUrl,
        string $authenticationMethod,
        string $secret,
        string $model,
        array $requestedTaskTypes,
        bool $allowSubmissionSource,
        int $timeoutSeconds
    ): AiProviderProfile {
        $definition = $this->catalog->get($providerKey);
        if (! in_array($authenticationMethod, $definition->authenticationMethods(), true)) {
            throw new DomainException('선택한 Provider에서 지원하지 않는 인증 방식입니다.');
        }
        $requestedTaskTypes = array_values(array_unique($requestedTaskTypes));
        sort($requestedTaskTypes);
        if ($requestedTaskTypes === []) {
            throw new DomainException('후보 검사할 AI 작업을 하나 이상 선택해 주세요.');
        }
        foreach ($requestedTaskTypes as $taskType) {
            if (! $this->catalog->supportsTask($taskType)) {
                throw new DomainException('등록되지 않은 AI 작업입니다.');
            }
        }
        if (
            $allowSubmissionSource
            && array_filter($requestedTaskTypes, $this->catalog->requiresSubmissionSource(...)) === []
        ) {
            throw new DomainException(
                '제출 코드 처리는 제출 AI 피드백 또는 제출 코드를 사용하는 애드온 작업을 선택한 프로필에서 승인할 수 있습니다.'
            );
        }
        $endpoint = $this->endpoints->validate($definition, $baseUrl);
        $deadline = microtime(true) + self::PROBE_BUDGET_SECONDS;
        $client = $this->clients->get($definition->adapterKey());
        $candidate = AiProviderProfile::tested(
            $profileKey,
            $name,
            $providerKey,
            $endpoint->normalizedBaseUrl(),
            $authenticationMethod,
            $secret,
            $model,
            'candidate',
            $requestedTaskTypes,
            $allowSubmissionSource,
            $this->remainingCallBudget($deadline),
            $endpoint->resolvedIps()
        );
        $models = $client->models($candidate);
        if (! isset($models[$model])) {
            throw new DomainException('선택한 모델을 Provider 모델 목록에서 찾을 수 없습니다.');
        }
        foreach ($requestedTaskTypes as $taskType) {
            if ($deadline - microtime(true) < self::PROBE_MIN_CALL_SECONDS) {
                throw new DomainException(
                    '선택한 작업이 많아 제한 시간 안에 모두 검사하지 못했습니다.'
                        . ' 작업 종류를 줄여 다시 검사해 주세요.'
                );
            }
            $probeTested = AiProviderProfile::tested(
                $profileKey,
                $name,
                $providerKey,
                $endpoint->normalizedBaseUrl(),
                $authenticationMethod,
                $secret,
                $model,
                $models[$model],
                $requestedTaskTypes,
                $allowSubmissionSource,
                $this->remainingCallBudget($deadline),
                $endpoint->resolvedIps()
            );
            $request = new AiTaskRequest(
                $taskType,
                'provider-probe-v1',
                $this->prompts->probeInput($taskType)
            );
            $messages = $this->prompts->messages($request);
            $schema = AiStructuredOutput::fromInput($request->input());
            $generate = fn (): array => $client instanceof StructuredAiProviderClient
                ? $client->generateStructured($probeTested, $messages, self::PROBE_MAX_OUTPUT_TOKENS, $schema, [])
                : $client->generate($probeTested, $messages, self::PROBE_MAX_OUTPUT_TOKENS);
            $output = $this->usage !== null ? $this->usage->withTask($taskType, true, $generate) : $generate();
            $this->prompts->assertProbeOutput($taskType, $output);
        }

        return AiProviderProfile::tested(
            $profileKey,
            $name,
            $providerKey,
            $endpoint->normalizedBaseUrl(),
            $authenticationMethod,
            $secret,
            $model,
            $models[$model],
            $requestedTaskTypes,
            $allowSubmissionSource,
            $timeoutSeconds,
            $endpoint->resolvedIps()
        );
    }

    private function remainingCallBudget(float $deadline): int
    {
        return max(
            self::PROBE_MIN_CALL_SECONDS,
            (int) floor($deadline - microtime(true))
        );
    }
}
