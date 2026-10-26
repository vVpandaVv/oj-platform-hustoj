<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;
/** Optional learner practice extension. It does not grant staff execution permission. */
interface AddonPracticeRuntimeContextV1 extends AddonRuntimeContextV1
{
    public function practice(): AddonPracticeGatewayV1;
}
