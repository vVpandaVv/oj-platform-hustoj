<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;

interface AddonSubmissionDraftGatewayV1
{
    public function prepare(AddonSubmissionDraftRequestV1 $request): AddonSubmissionDraftResultV1;
}
