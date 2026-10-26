<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

/** One authorized channel for addon-to-platform and addon-to-addon reads. */
interface AddonIntegrationGatewayV1
{
    public function searchActivities(AddonActivityQueryV1 $query): AddonActivityPageV1;

    public function searchProblems(AddonProblemQueryV1 $query): AddonProblemPageV1;

    public function acceptedSubmissions(AddonAcceptedSubmissionQueryV1 $query): AddonAcceptedSubmissionPageV1;

    public function privacyManagementPath(): string;
}
