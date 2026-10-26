<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonSiteAssetDefinitionV1;
use Throwable;

/** @internal */
final class AddonDocumentExtensions
{
    /** @var list<array{manifest:AddonManifest,definition:AddonSiteAssetDefinitionV1}> */
    private array $entries = [];

    public function __construct(private readonly AddonAssetRenderer $assets)
    {
    }

    public function replace(AcceptedAddonCatalog $catalog): void
    {
        $entries = [];
        foreach ($catalog->accepted() as $entry) {
            $manifest = $entry->manifest();
            if (! $manifest instanceof AddonManifest) {
                continue;
            }
            foreach ($manifest->siteAssets() as $definition) {
                $entries[] = ['manifest' => $manifest, 'definition' => $definition];
            }
        }
        $this->entries = $entries;
    }

    public function head(int $actorUserId): string
    {
        $markup = '';
        foreach ($this->active($actorUserId) as $entry) {
            $manifest = $entry['manifest'];
            $definition = $entry['definition'];
            $markup .= $this->assets->renderStyles(
                $manifest->pluginFile(),
                $manifest->assetBundles(),
                [$definition->assetBundle()]
            );
        }

        return $markup;
    }

    public function footer(int $actorUserId): string
    {
        $markup = '';
        foreach ($this->active($actorUserId) as $entry) {
            $manifest = $entry['manifest'];
            $definition = $entry['definition'];
            $json = json_encode(
                $entry['config'],
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
            );
            if (! is_string($json) || strlen($json) > 65536) {
                continue;
            }
            $markup .= '<script type="application/json" id="'
                . htmlspecialchars($definition->configElementId(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '">' . $json . '</script>';
            $markup .= $this->assets->renderScripts(
                $manifest->pluginFile(),
                $manifest->assetBundles(),
                [$definition->assetBundle()]
            );
        }

        return $markup;
    }

    /** @return list<array{manifest:AddonManifest,definition:AddonSiteAssetDefinitionV1,config:array<string,mixed>}> */
    private function active(int $actorUserId): array
    {
        if ($actorUserId < 1) {
            return [];
        }
        $active = [];
        foreach ($this->entries as $entry) {
            try {
                $config = $entry['definition']->configProvider()->config($actorUserId);
                if ($config === [] || ! self::jsonObject($config)) {
                    continue;
                }
                $active[] = $entry + ['config' => $config];
            } catch (Throwable) {
                continue;
            }
        }

        return $active;
    }

    /** @param array<mixed> $value */
    private static function jsonObject(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                return false;
            }
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);

        return is_string($json) && strlen($json) <= 65536;
    }
}
