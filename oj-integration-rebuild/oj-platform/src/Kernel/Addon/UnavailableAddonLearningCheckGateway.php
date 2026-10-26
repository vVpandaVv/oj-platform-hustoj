<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckGatewayV1;

/** @internal */
final class UnavailableAddonLearningCheckGateway implements AddonLearningCheckGatewayV1
{
    public function __construct(
        private readonly string $addonId,
        private readonly AddonCorrelationIdFactory $correlations
    ) {
    }

    public function context(AddonLearningCheckContextQueryV1 $query): AddonLearningCheckContextResultV1
    {
        return new AddonLearningCheckContextResultV1(false, [], new AddonApiProblemV1(
            'addon_runtime_unavailable',
            '학습 확인에 필요한 자료를 사용할 수 없습니다.',
            $this->correlations->next($this->addonId . ':learning-check'),
            true
        ));
    }
}
