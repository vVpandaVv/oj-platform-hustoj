<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRouteDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRoutingSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderException;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use DomainException;
use Throwable;

/**
 * Real AddonAiTaskGatewayV1 — routes/executes only task keys this specific
 * addon declared and got accepted for (C28-R06/D11). Reuses the exact same
 * shared AiTaskRouter (and therefore the same primary/fallback failover,
 * revision/fingerprint drift protection, and SSRF endpoint re-check) that
 * core tasks get — never a parallel implementation. Never exposes a secret,
 * base URL, or raw HTTP client to the addon: only providerKey()/model() are
 * ever read off an AiProviderProfile here.
 *
 * @internal
 */
final class AddonAiTaskRuntimeGateway implements AddonAiTaskGatewayV1
{
    private const UNASSIGNED = 'unassigned';

    /**
     * AddonAiTaskRoutingSnapshotV1's own constructor requires a
     * contributionKey-shaped taskKey unconditionally, even when reporting
     * unavailable — so a malformed/core-style key (e.g. a hijack probe like
     * 'submission_feedback') can never be echoed back. This fixed,
     * validly-shaped placeholder is substituted in that case instead of
     * letting the DTO constructor throw uncaught.
     */
    private const MALFORMED_TASK_KEY_PLACEHOLDER = 'addon.invalid_request.unknown';

    public function __construct(
        private readonly string $addonId,
        private readonly ?AddonCatalogEntry $entry,
        private readonly AiTaskRouter $aiTaskRouter,
        private readonly AiProviderSettingsService $providerSettings,
        private readonly AddonCorrelationIdFactory $correlations,
        private readonly ?\CodingPanda\OjPlatform\Shared\Ai\ActorAiAccessPolicy $actorAiAccess = null,
        private readonly ?\CodingPanda\OjPlatform\Modules\Settings\Contract\AddonAiRequestLedger $requestLedger = null
    ) {
    }

    public function canRoute(string $taskKey): AddonAiTaskRouteDecisionV1
    {
        $correlationId = $this->correlations->next($this->addonId . ':ai-route');
        $definition = $this->resolveDefinition($taskKey);
        if ($definition === null) {
            return new AddonAiTaskRouteDecisionV1(false, 'addon_ai_task_unroutable', self::UNASSIGNED, $correlationId);
        }
        $requiresSubmissionSource = $definition->sourceKind() === 'platform_submission';
        if (! $this->aiTaskRouter->canRoute($taskKey, $requiresSubmissionSource)) {
            return new AddonAiTaskRouteDecisionV1(false, 'addon_ai_task_unroutable', self::UNASSIGNED, $correlationId);
        }
        try {
            $revision = (string) $this->aiTaskRouter->snapshot($taskKey, $requiresSubmissionSource)->routingRevision();
        } catch (Throwable) {
            return new AddonAiTaskRouteDecisionV1(false, 'addon_ai_task_unroutable', self::UNASSIGNED, $correlationId);
        }

        return new AddonAiTaskRouteDecisionV1(true, '', $revision, $correlationId);
    }

    public function snapshot(string $taskKey): AddonAiTaskRoutingSnapshotV1
    {
        $correlationId = $this->correlations->next($this->addonId . ':ai-snapshot');
        if (! self::isWellFormedTaskKey($taskKey)) {
            return $this->unavailableSnapshot(
                self::MALFORMED_TASK_KEY_PLACEHOLDER,
                'addon_ai_task_unroutable',
                '요청한 AI 작업 키 형식이 올바르지 않습니다.',
                $correlationId,
                false
            );
        }
        $definition = $this->resolveDefinition($taskKey);
        if ($definition === null) {
            return $this->unavailableSnapshot(
                $taskKey,
                'addon_ai_task_unroutable',
                '해당 애드온에 배정되지 않은 AI 작업입니다.',
                $correlationId,
                false
            );
        }
        $requiresSubmissionSource = $definition->sourceKind() === 'platform_submission';
        try {
            $assignment = $this->aiTaskRouter->snapshot($taskKey, $requiresSubmissionSource);
            $primary = $this->providerSettings->find($assignment->primaryProfileKey());
            if ($primary === null) {
                throw new DomainException('AI 연결 프로필을 찾을 수 없습니다.');
            }
        } catch (DomainException $error) {
            return $this->unavailableSnapshot(
                $taskKey,
                'addon_ai_task_unroutable',
                self::safeMessage($error->getMessage()),
                $correlationId,
                false
            );
        } catch (Throwable) {
            return $this->unavailableSnapshot(
                $taskKey,
                'addon_ai_task_unroutable',
                'AI 작업 경로를 확인할 수 없습니다.',
                $correlationId,
                true
            );
        }

        return new AddonAiTaskRoutingSnapshotV1(
            true,
            $taskKey,
            $primary->providerKey(),
            $primary->model(),
            $assignment->profileFingerprint(),
            (string) $assignment->routingRevision(),
            null
        );
    }

    public function execute(AddonAiTaskRequestV1 $request): AddonAiTaskResultV1
    {
        $correlationId = $this->correlations->next($this->addonId . ':ai-execute');
        $taskKey = $request->taskKey();

        $definition = $this->resolveDefinition($taskKey);
        if ($definition === null) {
            return $this->unroutableResult(
                $taskKey,
                $correlationId,
                '해당 애드온에 배정되지 않은 AI 작업입니다.'
            );
        }
        if ($request->contractVersion() !== $definition->inputContractVersion()) {
            return $this->unroutableResult(
                $taskKey,
                $correlationId,
                'AI 작업 입력 계약 버전이 일치하지 않습니다.'
            );
        }

        $requiresSubmissionSource = $definition->sourceKind() === 'platform_submission';
        try {
            $this->actorAiAccess?->assertAiAccess($request->actorUserId());
            $assignment = $this->aiTaskRouter->snapshot($taskKey, $requiresSubmissionSource);
        } catch (DomainException $error) {
            return $this->unroutableResult($taskKey, $correlationId, self::safeMessage($error->getMessage()));
        } catch (Throwable) {
            return $this->unroutableResult($taskKey, $correlationId, 'AI 작업 경로를 확인할 수 없습니다.');
        }

        $internalRequest = new AiTaskRequest($taskKey, $request->contractVersion(), $request->input());

        try {
            $invoke = function () use ($assignment, $internalRequest): array {
                $response=$this->aiTaskRouter->execute($assignment, $internalRequest);
                return ['output'=>$response->output(),'provider'=>$response->providerKey(),'model'=>$response->modelKey(),
                    'fingerprint'=>$response->profileFingerprint()];
            };
            $wire=$this->requestLedger!==null ? $this->requestLedger->execute($this->addonId,$request->actorUserId(),$request->idempotencyKey(),
                hash('sha256',json_encode([$taskKey,$request->contractVersion(),$request->input(),$assignment->profileFingerprint(),$assignment->routingRevision()],JSON_THROW_ON_ERROR)),$invoke) : $invoke();
            $this->actorAiAccess?->assertAiAccess($request->actorUserId());
        } catch (\CodingPanda\OjPlatform\Modules\Settings\Contract\AiRequestPending $error) {
            return $this->failedResult($taskKey,$correlationId,self::safeMessage($error->getMessage()),true,'addon_ai_request_pending');
        } catch (\CodingPanda\OjPlatform\Modules\Settings\Contract\AiRequestOutcomeUncertain $error) {
            return $this->failedResult($taskKey,$correlationId,self::safeMessage($error->getMessage()),false,'addon_ai_outcome_uncertain');
        } catch (AiProviderException $error) {
            if($this->requestLedger!==null){
                return $this->failedResult($taskKey,$correlationId,'AI 호출 결과를 확정하지 못해 같은 요청의 자동 재호출을 차단했습니다. 사용량을 확인한 뒤 새 생성을 시작해 주세요.',false,'addon_ai_outcome_uncertain');
            }
            return $this->failedResult(
                $taskKey,
                $correlationId,
                self::safeMessage($error->getMessage()),
                $error->isTransient()
            );
        } catch (DomainException $error) {
            return $this->failedResult($taskKey, $correlationId, self::safeMessage($error->getMessage()), false);
        } catch (Throwable) {
            return $this->failedResult($taskKey, $correlationId, 'AI 작업을 완료하지 못했습니다.', false);
        }

        $routing = new AddonAiTaskRoutingSnapshotV1(
            true,
            $taskKey,
            $wire['provider'],
            $wire['model'],
            $wire['fingerprint'],
            (string) $assignment->routingRevision(),
            null
        );

        return new AddonAiTaskResultV1(true, $wire['output'], $routing, $correlationId, null);
    }

    private static function isWellFormedTaskKey(string $taskKey): bool
    {
        return preg_match('/^addon\.[a-z][a-z0-9_]{2,63}\.[a-z][a-z0-9_.-]*$/D', $taskKey) === 1;
    }

    /**
     * AddonApiProblemV1's message field must be <=500 chars with no
     * newlines/control characters. Every message reaching this today is a
     * short static Korean string, but this guards against a future
     * exception message that isn't, so a problem response never itself
     * throws while being constructed.
     */
    private static function safeMessage(string $message): string
    {
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/', ' ', trim($message)) ?? '';
        $sanitized = function_exists('mb_substr') ? mb_substr($sanitized, 0, 400) : substr($sanitized, 0, 400);

        return $sanitized !== '' ? $sanitized : 'AI 작업을 완료하지 못했습니다.';
    }

    private function resolveDefinition(string $taskKey): ?AddonAiTaskDefinitionV1
    {
        if ($this->entry === null || ! $this->entry->isAccepted()) {
            return null;
        }
        foreach ($this->entry->manifest()?->aiTasks() ?? [] as $definition) {
            if ($definition->key() === $taskKey) {
                return $definition;
            }
        }

        return null;
    }

    private function unavailableSnapshot(
        string $taskKey,
        string $code,
        string $message,
        string $correlationId,
        bool $retryable
    ): AddonAiTaskRoutingSnapshotV1 {
        return new AddonAiTaskRoutingSnapshotV1(
            false,
            $taskKey,
            '',
            '',
            '',
            '',
            new AddonApiProblemV1($code, $message, $correlationId, $retryable)
        );
    }

    private function unroutableResult(string $taskKey, string $correlationId, string $message): AddonAiTaskResultV1
    {
        $problem = new AddonApiProblemV1('addon_ai_task_unroutable', $message, $correlationId, false);
        $routing = new AddonAiTaskRoutingSnapshotV1(false, $taskKey, '', '', '', '', $problem);

        return new AddonAiTaskResultV1(false, [], $routing, $correlationId, $problem);
    }

    private function failedResult(
        string $taskKey,
        string $correlationId,
        string $message,
        bool $retryable,
        string $code = 'addon_ai_task_failed'
    ): AddonAiTaskResultV1 {
        $problem = new AddonApiProblemV1($code, $message, $correlationId, $retryable);
        $routing = new AddonAiTaskRoutingSnapshotV1(false, $taskKey, '', '', '', '', $problem);

        return new AddonAiTaskResultV1(false, [], $routing, $correlationId, $problem);
    }
}
