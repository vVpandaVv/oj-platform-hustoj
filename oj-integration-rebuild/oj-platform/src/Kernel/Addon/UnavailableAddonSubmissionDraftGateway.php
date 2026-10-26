<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftResultV1;

final class UnavailableAddonSubmissionDraftGateway implements AddonSubmissionDraftGatewayV1
{
    public function __construct(private readonly string $addonId, private readonly AddonCorrelationIdFactory $correlations) {}
    public function prepare(AddonSubmissionDraftRequestV1 $request): AddonSubmissionDraftResultV1
    {
        return new AddonSubmissionDraftResultV1(false, [], new AddonApiProblemV1('addon_runtime_unavailable', '제출 초안 연결을 사용할 수 없습니다.', $this->correlations->next($this->addonId . ':submission-draft'), true));
    }
}
