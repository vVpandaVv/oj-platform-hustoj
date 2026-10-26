<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;
interface AddonPracticeGatewayV1
{
    public function availability(AddonPracticeMaterialV1 $material): AddonCodeExecutionAvailabilityV1;
    public function submit(AddonPracticeRequestV1 $request): AddonCodeExecutionResultV1;
    public function poll(AddonPracticeQueryV1 $query): AddonCodeExecutionResultV1;
}
