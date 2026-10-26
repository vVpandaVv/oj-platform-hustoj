<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

interface AddonManifestBuilderV1
{
    /**
     * Supplies the callback the platform invokes once this addon's manifest
     * has been validated and accepted, handing it an AddonRuntimeContextV1
     * scoped to THIS addon only. Called at most once per registration.
     *
     * Invoked as a plain PHP function call, never via a WordPress action —
     * no other addon can intercept, spoof, or receive this callback, since
     * the platform only ever holds the exact callable this addon supplied.
     * If the manifest is rejected, this callback is never invoked. If
     * omitted, the addon gets no runtime context (no Context/AI Task
     * gateway access) — this is the safe default.
     *
     * @param callable(AddonRuntimeContextV1): void $callback
     */
    public function onReady(callable $callback): void;

    public function manifest(
        string $namespace,
        string $version,
        string $pluginFile,
        string $requiresPlatform,
        string $requiresAddonApi,
        string $diagnosticLabel,
        string $supportReference
    ): void;

    public function capability(AddonCapabilityDefinitionV1 $definition): void;

    public function consolePage(AddonConsolePageDefinitionV1 $definition): void;

    public function publicPage(AddonPublicPageDefinitionV1 $definition): void;

    public function consoleMenu(AddonConsoleMenuDefinitionV1 $definition): void;

    public function publicMenu(AddonPublicMenuDefinitionV1 $definition): void;

    public function assetBundle(AddonAssetBundleDefinitionV1 $definition): void;

    public function aiTask(AddonAiTaskDefinitionV1 $definition): void;
}
