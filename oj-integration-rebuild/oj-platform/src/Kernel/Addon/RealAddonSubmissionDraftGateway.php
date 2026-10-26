<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftResultV1;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionDraftService;
use DomainException;
use Throwable;

final class RealAddonSubmissionDraftGateway implements AddonSubmissionDraftGatewayV1
{
    public function __construct(private readonly string $addonId, private readonly SubmissionDraftService $drafts, private readonly AddonCorrelationIdFactory $correlations) {}
    public function prepare(AddonSubmissionDraftRequestV1 $request): AddonSubmissionDraftResultV1
    {
        try {
            return new AddonSubmissionDraftResultV1(true, $this->drafts->prepare($request->actorUserId(), $request->problemId(),
                $request->contextType(), $request->contextId(), $request->language(), $request->source()), null);
        } catch (Throwable $error) {
            $denied = $error instanceof DomainException;
            return new AddonSubmissionDraftResultV1(false, [], new AddonApiProblemV1($denied ? 'addon_access_denied' : 'addon_runtime_unavailable',
                $denied ? '현재 문제·수업·코드 정책으로 제출 초안을 준비할 수 없습니다.' : '제출 초안을 준비하지 못했습니다. 잠시 후 다시 시도해 주세요.',
                $this->correlations->next($this->addonId . ':submission-draft'), ! $denied));
        }
    }
}
