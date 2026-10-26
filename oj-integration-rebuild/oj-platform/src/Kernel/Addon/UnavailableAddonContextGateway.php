<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;

/** @internal */
final class UnavailableAddonContextGateway implements AddonContextGatewayV1
{
    public function __construct(
        private readonly string $addonId,
        private readonly AddonCorrelationIdFactory $correlations
    ) {
    }

    public function listTeachingContexts(AddonTeachingContextQueryV1 $query): AddonContextPageV1
    {
        return new AddonContextPageV1(false, [], '', $this->problem());
    }

    public function authorize(AddonContextAccessRequestV1 $request): AddonContextAccessDecisionV1
    {
        return new AddonContextAccessDecisionV1(
            false,
            'addon_context_unavailable',
            null,
            $this->correlations->next($this->addonId . ':context-authorize')
        );
    }

    public function snapshot(AddonContextSnapshotRequestV1 $request): AddonContextSnapshotResultV1
    {
        return new AddonContextSnapshotResultV1(false, null, $this->problem());
    }

    private function problem(): AddonApiProblemV1
    {
        return new AddonApiProblemV1(
            'addon_context_unavailable',
            '학습 컨텍스트를 사용할 수 없습니다.',
            $this->correlations->next($this->addonId . ':context'),
            false
        );
    }
}
