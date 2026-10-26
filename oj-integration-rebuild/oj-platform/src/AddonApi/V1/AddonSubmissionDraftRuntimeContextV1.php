<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;

/** Optional draft-prefill extension since API 1.7. Preparing never submits code. */
interface AddonSubmissionDraftRuntimeContextV1 extends AddonRuntimeContextV1
{
    public function submissionDrafts(): AddonSubmissionDraftGatewayV1;
}
