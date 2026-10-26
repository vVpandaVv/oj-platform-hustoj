<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionAnswerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionAssemblyResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionTemplateResultV1;

/** @internal */
final class UnavailableAddonCodeCompletionGateway implements AddonCodeCompletionGatewayV1
{
    public function __construct(
        private readonly string $addonId,
        private readonly AddonCorrelationIdFactory $correlations
    ) {
    }

    public function template(AddonCodeCompletionQueryV1 $query): AddonCodeCompletionTemplateResultV1
    {
        return new AddonCodeCompletionTemplateResultV1(false, [], $this->problem());
    }

    public function assemble(AddonCodeCompletionAnswerV1 $answer): AddonCodeCompletionAssemblyResultV1
    {
        return new AddonCodeCompletionAssemblyResultV1(false, [], $this->problem());
    }

    private function problem(): AddonApiProblemV1
    {
        return new AddonApiProblemV1('addon_runtime_unavailable', '코드 빈칸 채우기 기능을 사용할 수 없습니다.',
            $this->correlations->next($this->addonId . ':code-completion'), true);
    }
}
