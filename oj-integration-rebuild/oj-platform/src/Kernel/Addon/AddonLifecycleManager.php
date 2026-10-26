<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use LogicException;
use Throwable;

/** @internal */
final class AddonLifecycleManager implements AddonLifecycle
{
    private bool $collected = false;
    private bool $runtimePublished = false;

    public function __construct(
        private readonly AddonHookPublisher $hooks,
        private readonly AddonManifestValidator $validator,
        private readonly AcceptedAddonCatalog $catalog,
        private readonly AddonRuntimeServiceFactory $services,
        private readonly string $platformVersion,
        private readonly string $addonApiVersion,
        private readonly AddonCorrelationIdFactory $correlations,
        private readonly ErrorReporter $errors,
        private readonly AddonContributionComposer $composer,
        private readonly AddonRuntimeReadiness $readiness
    ) {
    }

    public function collectAndFreeze(): void
    {
        if ($this->collected) {
            throw new LogicException('The addon registration lifecycle already ran.');
        }
        $this->collected = true;
        $gateway = new AddonRegistrationGateway($this->addonApiVersion, $this->errors);
        try {
            $this->hooks->publishRegistration($gateway);
        } catch (Throwable $error) {
            $this->errors->report($error);
        }
        $this->catalog->commitAndFreeze($this->validator->validate($gateway->close()));
        foreach ($this->catalog->accepted() as $entry) {
            $this->readiness->register($entry->addonId(), $entry->onReadyCallback() !== null);
        }
        $this->composer->compose($this->catalog);
    }

    /**
     * Hands each accepted addon its own AddonRuntimeContextV1 by invoking
     * the onReady callback IT supplied at registration — a direct PHP
     * function call, never a WordPress action. No addon can intercept or
     * request another addon's context: the platform only ever holds the
     * exact callable each addon gave it, keyed to that addon's own accepted
     * catalog entry.
     */
    public function publishRuntimeReady(): void
    {
        if (! $this->collected || ! $this->catalog->isFrozen()) {
            throw new LogicException('The addon catalog must be frozen before RuntimeReady.');
        }
        if ($this->runtimePublished) {
            throw new LogicException('The addon RuntimeReady lifecycle already ran.');
        }
        $this->runtimePublished = true;
        $catalogRevision = $this->catalog->revision();
        foreach ($this->catalog->accepted() as $entry) {
            $onReady = $entry->onReadyCallback();
            if ($onReady === null) {
                continue;
            }
            try {
                $context = new AddonRuntimeContext(
                    $entry->addonId(),
                    $entry,
                    $this->platformVersion,
                    $this->addonApiVersion,
                    $catalogRevision,
                    $this->services->contexts($entry->addonId(), $entry),
                    $this->services->aiTasks($entry->addonId(), $entry),
                    $this->correlations,
                    $this->services instanceof AddonEditorRuntimeServiceFactory
                        ? $this->services->editor()
                        : null,
                    $this->services instanceof AddonIntegrationRuntimeServiceFactory
                        ? $this->services->integrations($entry->addonId(), $entry)
                        : null,
                    $this->services instanceof AddonLearningCheckRuntimeServiceFactory
                        ? $this->services->learningChecks($entry->addonId(), $entry)
                        : null,
                    $this->services instanceof AddonCodeOrderingRuntimeServiceFactory
                        ? $this->services->codeOrdering($entry->addonId(), $entry)
                        : null,
                    $this->services instanceof AddonCodeCompletionRuntimeServiceFactory
                        ? $this->services->codeCompletion($entry->addonId(), $entry)
                        : null,
                    $this->services instanceof AddonCodeExecutionRuntimeServiceFactory
                        ? $this->services->codeExecution($entry->addonId(), $entry)
                        : null,
                    $this->services instanceof AddonPracticeRuntimeServiceFactory
                        ? $this->services->practice($entry->addonId(), $entry)
                        : null,
                    $this->services instanceof AddonSubmissionDraftRuntimeServiceFactory
                        ? $this->services->submissionDrafts($entry->addonId(), $entry)
                        : null
                );
                $onReady($context);
                $this->readiness->markReady($entry->addonId());
            } catch (Throwable $error) {
                $this->readiness->markFailed($entry->addonId());
                $this->errors->report($error);
            }
        }
    }
}
