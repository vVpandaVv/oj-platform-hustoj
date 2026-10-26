<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonEditorGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Contests\Contract\ContestSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Classes\Contract\ClassContextAccess;
use CodingPanda\OjPlatform\Modules\Groups\Contract\GroupContextAccess;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderSettingsService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionLanguageCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmissionProvider;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use CodingPanda\OjPlatform\Shared\Ai\ActorAiAccessPolicy;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionGatewayV1;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionEngine;

/**
 * Real AddonRuntimeServiceFactory — the single construction site for both
 * halves of C28-I05. Context authorization has no per-addon manifest
 * declaration to check, so `contexts()` ignores $entry and shares one set of
 * platform-service collaborators across every addon; `aiTasks()` captures
 * $entry because AddonAiTaskRuntimeGateway::resolveDefinition() must only
 * ever match keys this specific addon declared. Both gateways are
 * constructed fresh (cheap) on every forAddon() call.
 *
 * @internal
 */
final class RealAddonRuntimeServiceFactory implements AddonEditorRuntimeServiceFactory, AddonIntegrationRuntimeServiceFactory, AddonLearningCheckRuntimeServiceFactory, AddonCodeOrderingRuntimeServiceFactory, AddonCodeCompletionRuntimeServiceFactory, AddonCodeExecutionRuntimeServiceFactory, AddonPracticeRuntimeServiceFactory, AddonSubmissionDraftRuntimeServiceFactory
{
    public function __construct(
        private readonly AddonCorrelationIdFactory $correlations,
        private readonly ClassContextAccess $classContextAccess,
        private readonly GroupContextAccess $groupContextAccess,
        private readonly WordPressUserGateway $userGateway,
        private readonly AcceptedAddonCatalog $addonCatalog,
        private readonly RouteUrlProvider $routeUrls,
        private readonly ProblemCatalogService $problemCatalog,
        private readonly SubmissionQueryService $submissionQueries,
        private readonly Authorization $permissions,
        private readonly AiTaskRouter $aiTaskRouter,
        private readonly AiProviderSettingsService $providerSettings,
        private readonly ?ActorAiAccessPolicy $actorAiAccess = null,
        private readonly ?AddonEditorGatewayV1 $editorGateway = null,
        private readonly ?AiFeedbackSubmissionProvider $feedbackSubmissions = null,
        private readonly ?ContestSubmissionAccess $contests = null,
        private readonly ?SubmissionProblemProvider $submissionProblems = null,
        private readonly ?SubmissionLanguageCatalog $submissionLanguages = null,
        private readonly ?CodeExecutionEngine $codeExecutionEngine = null,
        private readonly ?\CodingPanda\OjPlatform\Modules\Settings\Contract\AddonAiRequestLedger $requestLedger = null,
        private readonly ?\Closure $executionLanguages = null,
        private readonly ?\Closure $executionIdentityKey = null,
        private readonly ?\CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionDraftService $submissionDraftService = null
    ) {
    }

    public function editor(): AddonEditorGatewayV1
    {
        return $this->editorGateway ?? new UnavailableAddonEditorGateway();
    }
    public function submissionDrafts(string $addonId, ?AddonCatalogEntry $entry): \CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftGatewayV1
    {
        return $this->submissionDraftService === null || $entry?->status() !== 'accepted'
            ? new UnavailableAddonSubmissionDraftGateway($addonId, $this->correlations)
            : new RealAddonSubmissionDraftGateway($addonId, $this->submissionDraftService, $this->correlations);
    }

    public function contexts(string $addonId, ?AddonCatalogEntry $entry): AddonContextGatewayV1
    {
        return new RealAddonContextGateway(
            $addonId,
            $this->classContextAccess,
            $this->groupContextAccess,
            $this->userGateway,
            $this->correlations
        );
    }

    public function aiTasks(string $addonId, ?AddonCatalogEntry $entry): AddonAiTaskGatewayV1
    {
        return new AddonAiTaskRuntimeGateway(
            $addonId,
            $entry,
            $this->aiTaskRouter,
            $this->providerSettings,
            $this->correlations,
            $this->actorAiAccess,
            $this->requestLedger
        );
    }

    public function integrations(string $addonId, ?AddonCatalogEntry $entry): AddonIntegrationGatewayV1
    {
        unset($entry);
        $contexts = $this->contexts($addonId, null);
        if (! $contexts instanceof AddonAssignmentRosterGatewayV1) {
            return new UnavailableAddonIntegrationGateway($addonId, $this->correlations);
        }

        return new RealAddonIntegrationGateway(
            $addonId,
            $this->addonCatalog,
            $this->routeUrls,
            $this->problemCatalog,
            $this->submissionQueries,
            $this->userGateway,
            $this->permissions,
            $contexts,
            $this->correlations
        );
    }

    public function learningChecks(string $addonId, ?AddonCatalogEntry $entry): AddonLearningCheckGatewayV1
    {
        if ($this->feedbackSubmissions === null) {
            return new UnavailableAddonLearningCheckGateway($addonId, $this->correlations);
        }
        return new RealAddonLearningCheckGateway(
            $addonId,
            $this->problemCatalog,
            $this->submissionQueries,
            $this->feedbackSubmissions,
            $this->userGateway,
            $this->correlations,
            $this->contests,
            $this->groupContextAccess
        );
    }

    public function codeOrdering(string $addonId, ?AddonCatalogEntry $entry): AddonCodeOrderingGatewayV1
    {
        if ($this->submissionProblems === null) {
            return new UnavailableAddonCodeOrderingGateway($addonId, $this->correlations);
        }
        return new RealAddonCodeOrderingGateway(
            $addonId, $this->learningChecks($addonId, $entry), $this->submissionProblems, $this->correlations
        );
    }

    public function codeCompletion(string $addonId, ?AddonCatalogEntry $entry): AddonCodeCompletionGatewayV1
    {
        if ($this->submissionProblems === null || $this->submissionLanguages === null) {
            return new UnavailableAddonCodeCompletionGateway($addonId, $this->correlations);
        }
        return new RealAddonCodeCompletionGateway(
            $addonId, $this->learningChecks($addonId, $entry), $this->submissionProblems,
            $this->correlations, $this->submissionLanguages
        );
    }

    public function codeExecution(string $addonId, ?AddonCatalogEntry $entry): AddonCodeExecutionGatewayV1
    {
        if ($this->codeExecutionEngine === null || $entry?->manifest() === null) {
            return new UnavailableAddonCodeExecutionGateway($addonId, $this->correlations);
        }
        $capabilities = [];
        foreach ($entry->manifest()->capabilities() as $capability) {
            if (in_array($capability->audience(), ['educator', 'operator'], true)) {
                $capabilities[] = AddonCapabilityKeys::capabilityKey($capability->key());
            }
        }
        return new RealAddonCodeExecutionGateway($addonId, $this->codeExecutionEngine, $this->userGateway,
            $this->permissions, $this->correlations, static fn (): string => function_exists('wp_salt') ? wp_salt('auth') : '',
            array_values(array_unique($capabilities)),$this->executionLanguages,$this->executionIdentityKey);
    }

    public function practice(string $addonId,?AddonCatalogEntry $entry):\CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeGatewayV1
    {
        if($this->codeExecutionEngine===null||$entry?->manifest()===null){return new UnavailableAddonPracticeGateway($addonId,$this->correlations);}
        return new RealAddonPracticeGateway($addonId,$this->codeExecutionEngine,$this->userGateway,$this->contexts($addonId,$entry),
            $this->correlations,static fn():string=>function_exists('wp_salt')?wp_salt('auth'):'',$this->executionLanguages,$this->executionIdentityKey);
    }
}
