<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRouteDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRoutingSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;

/** @internal */
final class UnavailableAddonAiTaskGateway implements AddonAiTaskGatewayV1
{
    public function __construct(
        private readonly string $addonId,
        private readonly AddonCorrelationIdFactory $correlations
    ) {
    }

    public function canRoute(string $taskKey): AddonAiTaskRouteDecisionV1
    {
        return new AddonAiTaskRouteDecisionV1(
            false,
            'addon_ai_task_unroutable',
            'unavailable',
            $this->correlations->next($this->addonId . ':ai-route')
        );
    }

    public function snapshot(string $taskKey): AddonAiTaskRoutingSnapshotV1
    {
        return new AddonAiTaskRoutingSnapshotV1(
            false,
            $taskKey,
            '',
            '',
            '',
            '',
            $this->problem()
        );
    }

    public function execute(AddonAiTaskRequestV1 $request): AddonAiTaskResultV1
    {
        $correlationId = $this->correlations->next($this->addonId . ':ai-execute');
        $problem = new AddonApiProblemV1(
            'addon_ai_task_unroutable',
            'AI 작업 경로를 사용할 수 없습니다.',
            $correlationId,
            true
        );
        $routing = new AddonAiTaskRoutingSnapshotV1(
            false,
            $request->taskKey(),
            '',
            '',
            '',
            '',
            $problem
        );

        return new AddonAiTaskResultV1(false, [], $routing, $correlationId, $problem);
    }

    private function problem(): AddonApiProblemV1
    {
        return new AddonApiProblemV1(
            'addon_ai_task_unroutable',
            'AI 작업 경로를 사용할 수 없습니다.',
            $this->correlations->next($this->addonId . ':ai'),
            true
        );
    }
}
