<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionLanguageCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionAiFeedbackPolicyRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\PerformanceModePolicyRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionContextProviderRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionDetailExtensionRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionEditorExtensionRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSourceModeRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionStaffScopeRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionStoredSourceDecoderRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionCodeEditorAssets;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\PerformanceModePolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmissionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\UserSubmissionProfileProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionRejudgeControlRenderer;
use CodingPanda\OjPlatform\Modules\Submissions\Module;

final class SubmissionsBundle
{
    /**
     * @param array<string, object> $services
     */
    public function __construct(
        private readonly Module $module,
        private readonly array $services
    ) {
    }

    public function rejudgeControls(): SubmissionRejudgeControlRenderer
    {
        return $this->typedService(SubmissionRejudgeControlRenderer::class);
    }

    public function module(): Module
    {
        return $this->module;
    }

    public function codeEditorAssets(): SubmissionCodeEditorAssets
    {
        return $this->typedService(SubmissionCodeEditorAssets::class);
    }

    public function sourceModes(): SubmissionSourceModeRegistry
    {
        return $this->typedService(SubmissionSourceModeRegistry::class);
    }

    public function editorExtensions(): SubmissionEditorExtensionRegistry
    {
        return $this->typedService(
            SubmissionEditorExtensionRegistry::class
        );
    }

    public function performanceModes(): PerformanceModePolicyRegistry
    {
        return $this->typedService(PerformanceModePolicyRegistry::class);
    }

    public function performanceModePolicy(): PerformanceModePolicy
    {
        return $this->typedService(PerformanceModePolicy::class);
    }

    public function queries(): SubmissionQueryService
    {
        return $this->typedService(SubmissionQueryService::class);
    }
    public function drafts(): \CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionDraftService
    {
        return $this->typedService(\CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionDraftService::class);
    }

    public function submitter(): \CodingPanda\OjPlatform\Modules\Submissions\Application\SubmitSubmissionService
    {
        return $this->typedService(\CodingPanda\OjPlatform\Modules\Submissions\Application\SubmitSubmissionService::class);
    }

    public function languages(): SubmissionLanguageCatalog
    {
        return $this->typedService(SubmissionLanguageCatalog::class);
    }

    public function codeExecution(): \CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionEngine
    {
        return $this->typedService(\CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionEngine::class);
    }
    public function executionSupport():\CodingPanda\OjPlatform\Modules\Submissions\Application\CodeExecutionSupport
    {
        return $this->typedService(\CodingPanda\OjPlatform\Modules\Submissions\Application\CodeExecutionSupport::class);
    }

    public function staffScopes(): SubmissionStaffScopeRegistry
    {
        return $this->typedService(SubmissionStaffScopeRegistry::class);
    }

    public function contexts(): SubmissionContextProviderRegistry
    {
        return $this->typedService(SubmissionContextProviderRegistry::class);
    }

    public function aiFeedbackPolicies(): SubmissionAiFeedbackPolicyRegistry
    {
        return $this->typedService(SubmissionAiFeedbackPolicyRegistry::class);
    }

    public function projections(): SubmissionProjectionProvider
    {
        return $this->typedService(SubmissionProjectionProvider::class);
    }

    public function aiFeedbackSubmissions(): AiFeedbackSubmissionProvider
    {
        return $this->typedService(AiFeedbackSubmissionProvider::class);
    }

    public function userProfiles(): UserSubmissionProfileProvider
    {
        return $this->typedService(UserSubmissionProfileProvider::class);
    }

    public function detailExtensions(): SubmissionDetailExtensionRegistry
    {
        return $this->typedService(SubmissionDetailExtensionRegistry::class);
    }

    public function storedSourceDecoders(): SubmissionStoredSourceDecoderRegistry
    {
        return $this->typedService(
            SubmissionStoredSourceDecoderRegistry::class
        );
    }

    public function bindInto(BuildContainer $container): void
    {
        foreach ($this->services as $serviceId => $service) {
            $container->bind($serviceId, $service);
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $serviceId
     * @return T
     */
    private function typedService(string $serviceId): object
    {
        $service = $this->services[$serviceId] ?? null;
        if (! is_object($service) || ! $service instanceof $serviceId) {
            throw new \LogicException(
                'Submissions 조립 서비스가 없습니다: ' . $serviceId
            );
        }

        return $service;
    }
}
