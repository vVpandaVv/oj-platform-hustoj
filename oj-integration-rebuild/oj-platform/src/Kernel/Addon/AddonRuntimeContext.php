<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonEditorGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonEditorRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionGatewayV1;

/** @internal */
final class AddonRuntimeContext implements AddonEditorRuntimeContextV1, AddonIntegrationRuntimeContextV1, AddonLearningCheckRuntimeContextV1, AddonCodeOrderingRuntimeContextV1, AddonCodeCompletionRuntimeContextV1, AddonCodeExecutionRuntimeContextV1, \CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeRuntimeContextV1, \CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftRuntimeContextV1
{
    public function __construct(
        private readonly string $addonId,
        private readonly ?AddonCatalogEntry $entry,
        private readonly string $platformVersion,
        private readonly string $addonApiVersion,
        private readonly string $catalogRevision,
        private readonly AddonContextGatewayV1 $contexts,
        private readonly AddonAiTaskGatewayV1 $aiTasks,
        private readonly AddonCorrelationIdFactory $correlations,
        private readonly ?AddonEditorGatewayV1 $editorGateway = null,
        private readonly ?AddonIntegrationGatewayV1 $integrationGateway = null,
        private readonly ?AddonLearningCheckGatewayV1 $learningCheckGateway = null,
        private readonly ?AddonCodeOrderingGatewayV1 $codeOrderingGateway = null,
        private readonly ?AddonCodeCompletionGatewayV1 $codeCompletionGateway = null,
        private readonly ?AddonCodeExecutionGatewayV1 $codeExecutionGateway = null,
        private readonly ?\CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeGatewayV1 $practiceGateway = null,
        private readonly ?\CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftGatewayV1 $submissionDraftGateway = null
    ) {
    }

    public function editor(): AddonEditorGatewayV1
    {
        return $this->editorGateway ?? new UnavailableAddonEditorGateway();
    }

    public function integrations(): AddonIntegrationGatewayV1
    {
        return $this->integrationGateway
            ?? new UnavailableAddonIntegrationGateway($this->addonId, $this->correlations);
    }

    public function learningChecks(): AddonLearningCheckGatewayV1
    {
        return $this->learningCheckGateway
            ?? new UnavailableAddonLearningCheckGateway($this->addonId, $this->correlations);
    }

    public function codeOrdering(): AddonCodeOrderingGatewayV1
    {
        return $this->codeOrderingGateway
            ?? new UnavailableAddonCodeOrderingGateway($this->addonId, $this->correlations);
    }

    public function codeCompletion(): AddonCodeCompletionGatewayV1
    {
        return $this->codeCompletionGateway
            ?? new UnavailableAddonCodeCompletionGateway($this->addonId, $this->correlations);
    }

    public function codeExecution(): AddonCodeExecutionGatewayV1
    {
        return $this->codeExecutionGateway
            ?? new UnavailableAddonCodeExecutionGateway($this->addonId, $this->correlations);
    }

    public function snapshot(): AddonRuntimeSnapshotV1
    {
        $manifest = $this->entry?->manifest();

        return new AddonRuntimeSnapshotV1(
            $this->addonId,
            $manifest?->version() ?? '',
            $manifest?->requiresPlatform() ?? '',
            $this->platformVersion,
            $manifest?->requiresAddonApi() ?? '',
            $this->addonApiVersion,
            $this->entry?->status() ?? 'unregistered',
            $this->entry?->code() ?? 'addon_runtime_unavailable',
            $this->catalogRevision,
            $manifest?->contributionCounts() ?? [],
            $this->correlations->next($this->addonId . ':runtime')
        );
    }

    public function practice():\CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeGatewayV1
    {
        return $this->practiceGateway??new UnavailableAddonPracticeGateway($this->addonId,$this->correlations);
    }

    public function contexts(): AddonContextGatewayV1
    {
        return $this->contexts;
    }
    public function submissionDrafts(): \CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftGatewayV1
    {
        return $this->submissionDraftGateway ?? new UnavailableAddonSubmissionDraftGateway($this->addonId, $this->correlations);
    }

    public function aiTasks(): AddonAiTaskGatewayV1
    {
        return $this->aiTasks;
    }
}
