<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use LogicException;

/** @internal */
final class AcceptedAddonCatalog
{
    /** @var array<string, AddonCatalogEntry> */
    private array $entries = [];
    private string $revision = '';
    private bool $frozen = false;

    /** @param list<AddonCatalogEntry> $entries */
    public function commitAndFreeze(array $entries): void
    {
        if ($this->frozen) {
            throw new LogicException('The accepted addon catalog is already frozen.');
        }

        $next = [];
        foreach ($entries as $entry) {
            if (! $entry instanceof AddonCatalogEntry || isset($next[$entry->addonId()])) {
                throw new LogicException('The addon catalog result is not atomic.');
            }
            $next[$entry->addonId()] = $entry;
        }
        ksort($next, SORT_STRING);
        $revision = hash('sha256', self::canonicalJson($next));

        $this->entries = $next;
        $this->revision = $revision;
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function revision(): string
    {
        $this->assertFrozen();

        return $this->revision;
    }

    public function find(string $addonId): ?AddonCatalogEntry
    {
        $this->assertFrozen();

        return $this->entries[$addonId] ?? null;
    }

    /** @return array<string, AddonCatalogEntry> */
    public function all(): array
    {
        $this->assertFrozen();

        return $this->entries;
    }

    /** @return array<string, AddonCatalogEntry> */
    public function accepted(): array
    {
        $this->assertFrozen();

        return array_filter(
            $this->entries,
            static fn (AddonCatalogEntry $entry): bool => $entry->isAccepted()
        );
    }

    /** @param array<string, AddonCatalogEntry> $entries */
    private static function canonicalJson(array $entries): string
    {
        $data = [];
        foreach ($entries as $addonId => $entry) {
            $manifest = $entry->manifest();
            $keys = [];
            if ($manifest instanceof AddonManifest) {
                foreach (self::definitions($manifest) as $definition) {
                    $keys[] = $definition->key();
                }
                sort($keys, SORT_STRING);
            }
            $data[$addonId] = [
                'status' => $entry->status(),
                'code' => $entry->code(),
                'namespace' => $manifest?->namespace() ?? '',
                'version' => $manifest?->version() ?? '',
                'requires_platform' => $manifest?->requiresPlatform() ?? '',
                'requires_addon_api' => $manifest?->requiresAddonApi() ?? '',
                'keys' => $keys,
                'ai_tasks' => $manifest instanceof AddonManifest
                    ? self::aiTaskContracts($manifest)
                    : [],
            ];
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            throw new LogicException('The addon catalog revision could not be calculated.');
        }

        return $json;
    }

    /** @return list<array<string, string>> */
    private static function aiTaskContracts(AddonManifest $manifest): array
    {
        $contracts = [];
        foreach ($manifest->aiTasks() as $task) {
            $instruction = $task->instruction();
            $contracts[] = [
                'key' => $task->key(),
                'input_contract_version' => $task->inputContractVersion(),
                'source_kind' => $task->sourceKind(),
                'instruction_contract_version' => $instruction?->contractVersion() ?? '',
                'instruction_content_hash' => $instruction?->contentHash() ?? '',
            ];
        }
        usort(
            $contracts,
            static fn (array $left, array $right): int => $left['key'] <=> $right['key']
        );

        return $contracts;
    }

    /** @return list<object> */
    private static function definitions(AddonManifest $manifest): array
    {
        return array_merge(
            $manifest->capabilities(),
            $manifest->consolePages(),
            $manifest->publicPages(),
            $manifest->consoleMenus(),
            $manifest->publicMenus(),
            $manifest->assetBundles(),
            $manifest->aiTasks(),
            $manifest->activityProviders(),
            $manifest->siteAssets(),
            $manifest->privacyData()
        );
    }

    private function assertFrozen(): void
    {
        if (! $this->frozen) {
            throw new LogicException('The accepted addon catalog is not frozen.');
        }
    }
}
