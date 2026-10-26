<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAcceptedSubmissionPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAcceptedSubmissionQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonProblemPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonProblemQueryV1;

/** @internal */
final class UnavailableAddonIntegrationGateway implements AddonIntegrationGatewayV1
{
    public function __construct(
        private readonly string $addonId,
        private readonly AddonCorrelationIdFactory $correlations
    ) {
    }

    public function searchActivities(AddonActivityQueryV1 $query): AddonActivityPageV1
    {
        unset($query);
        return new AddonActivityPageV1(false, [], $this->problem('activities'));
    }

    public function searchProblems(AddonProblemQueryV1 $query): AddonProblemPageV1
    {
        unset($query);
        return new AddonProblemPageV1(false, [], $this->problem('problems'));
    }

    public function acceptedSubmissions(AddonAcceptedSubmissionQueryV1 $query): AddonAcceptedSubmissionPageV1
    {
        unset($query);
        return new AddonAcceptedSubmissionPageV1(false, [], $this->problem('submissions'));
    }

    public function privacyManagementPath(): string
    {
        return '/oj-console/agreements';
    }

    private function problem(string $operation): AddonApiProblemV1
    {
        return new AddonApiProblemV1(
            'addon_runtime_unavailable',
            '플랫폼 통합 기능을 사용할 수 없습니다.',
            $this->correlations->next($this->addonId . ':integration-' . $operation),
            true
        );
    }
}
