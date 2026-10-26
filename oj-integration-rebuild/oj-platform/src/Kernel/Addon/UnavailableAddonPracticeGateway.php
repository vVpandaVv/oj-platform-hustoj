<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Kernel\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeMaterialV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionAvailabilityV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionResultV1;
final class UnavailableAddonPracticeGateway implements AddonPracticeGatewayV1
{
    public function __construct(private readonly string $addonId,private readonly AddonCorrelationIdFactory $correlations){}
    private function missing():\CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1{return new \CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1('addon_runtime_unavailable','학습자 실습 실행 연결을 사용할 수 없습니다.',$this->correlations->next($this->addonId.':practice'),true);}
    public function availability(AddonPracticeMaterialV1 $material):AddonCodeExecutionAvailabilityV1{return new AddonCodeExecutionAvailabilityV1(false,[],$this->missing());}
    public function submit(AddonPracticeRequestV1 $request):AddonCodeExecutionResultV1{return new AddonCodeExecutionResultV1(false,[],$this->missing());}
    public function poll(AddonPracticeQueryV1 $query):AddonCodeExecutionResultV1{return new AddonCodeExecutionResultV1(false,[],$this->missing());}
}
