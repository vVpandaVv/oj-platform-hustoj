<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingAnswerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingAssemblyResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingPuzzleResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingQueryV1;

/** @internal */
final class UnavailableAddonCodeOrderingGateway implements AddonCodeOrderingGatewayV1
{
    public function __construct(
        private readonly string $addonId,
        private readonly AddonCorrelationIdFactory $correlations
    ) {
    }

    public function puzzle(AddonCodeOrderingQueryV1 $query): AddonCodeOrderingPuzzleResultV1
    {
        return new AddonCodeOrderingPuzzleResultV1(false, [], $this->problem());
    }

    public function assemble(AddonCodeOrderingAnswerV1 $answer): AddonCodeOrderingAssemblyResultV1
    {
        return new AddonCodeOrderingAssemblyResultV1(false, [], $this->problem());
    }

    private function problem(): AddonApiProblemV1
    {
        return new AddonApiProblemV1('addon_runtime_unavailable',
            '코드 순서 배열 기능을 사용할 수 없습니다.',
            $this->correlations->next($this->addonId . ':code-ordering'), true);
    }
}
