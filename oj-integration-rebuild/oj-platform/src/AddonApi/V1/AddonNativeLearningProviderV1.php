<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;
interface AddonNativeLearningProviderV1
{
    public function query(AddonNativeLearningQueryV1 $query): AddonNativeLearningPageV1;
}
