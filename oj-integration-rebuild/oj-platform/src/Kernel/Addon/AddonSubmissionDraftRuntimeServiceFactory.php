<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Kernel\Addon;

interface AddonSubmissionDraftRuntimeServiceFactory extends AddonRuntimeServiceFactory
{
    public function submissionDrafts(string $addonId, ?AddonCatalogEntry $entry): \CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftGatewayV1;
}
