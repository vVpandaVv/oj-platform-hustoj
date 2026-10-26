<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionAvailabilityV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionResultV1;

/** @internal */
final class UnavailableAddonCodeExecutionGateway implements AddonCodeExecutionGatewayV1
{
    public function __construct(private readonly string $addonId, private readonly AddonCorrelationIdFactory $correlations) {}
    public function availability(): AddonCodeExecutionAvailabilityV1
    {
        return new AddonCodeExecutionAvailabilityV1(false, [], $this->problem());
    }
    public function submit(AddonCodeExecutionRequestV1 $request): AddonCodeExecutionResultV1
    {
        return new AddonCodeExecutionResultV1(false, [], $this->problem());
    }
    public function poll(AddonCodeExecutionQueryV1 $query): AddonCodeExecutionResultV1
    {
        return new AddonCodeExecutionResultV1(false, [], $this->problem());
    }
    private function problem(): AddonApiProblemV1
    {
        return new AddonApiProblemV1('addon_runtime_unavailable', '기존 OJ의 사용자 입력 실행 기능을 사용할 수 없습니다.',
            $this->correlations->next($this->addonId . ':code-execution'), true);
    }
}
