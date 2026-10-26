<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;

/** @internal */
final class WordPressAddonAssetRenderer implements AddonAssetRenderer
{
    public function renderStyles(string $pluginFile, array $bundles, array $bundleKeys): string
    {
        if ($bundleKeys === [] || ! function_exists('wp_register_style')) {
            return '';
        }

        $styleHandles = [];
        foreach ($this->orderedBundles($bundles, $bundleKeys) as $bundle) {
            $previousStyleHandle = null;
            foreach ($bundle->styles() as $index => $relativePath) {
                $handle = $bundle->handle() . '-style-' . $index;
                $dependencies = $previousStyleHandle === null
                    ? $bundle->dependencies()
                    : array_merge($bundle->dependencies(), [$previousStyleHandle]);
                wp_register_style($handle, $this->url($pluginFile, $relativePath), $dependencies, $bundle->version());
                $styleHandles[] = $handle;
                $previousStyleHandle = $handle;
            }
        }

        if ($styleHandles === [] || ! function_exists('wp_styles')) {
            return '';
        }

        ob_start();
        wp_styles()->do_items($styleHandles);

        return (string) ob_get_clean();
    }

    public function renderScripts(string $pluginFile, array $bundles, array $bundleKeys): string
    {
        if ($bundleKeys === [] || ! function_exists('wp_register_script')) {
            return '';
        }

        $scriptHandles = [];
        foreach ($this->orderedBundles($bundles, $bundleKeys) as $bundle) {
            $previousScriptHandle = null;
            foreach ($bundle->scripts() as $index => $relativePath) {
                $handle = $bundle->handle() . '-script-' . $index;
                $dependencies = $previousScriptHandle === null
                    ? $bundle->dependencies()
                    : array_merge($bundle->dependencies(), [$previousScriptHandle]);
                wp_register_script($handle, $this->url($pluginFile, $relativePath), $dependencies, $bundle->version(), true);
                $scriptHandles[] = $handle;
                $previousScriptHandle = $handle;
            }
        }

        if ($scriptHandles === [] || ! function_exists('wp_scripts')) {
            return '';
        }

        ob_start();
        wp_scripts()->do_items($scriptHandles);

        return (string) ob_get_clean();
    }

    /**
     * @param list<AddonAssetBundleDefinitionV1> $bundles
     * @param list<string> $bundleKeys
     * @return list<AddonAssetBundleDefinitionV1>
     */
    private function orderedBundles(array $bundles, array $bundleKeys): array
    {
        $lookup = [];
        foreach ($bundles as $bundle) {
            if ($bundle instanceof AddonAssetBundleDefinitionV1) {
                $lookup[$bundle->key()] = $bundle;
            }
        }

        $ordered = [];
        foreach ($bundleKeys as $bundleKey) {
            if (isset($lookup[$bundleKey])) {
                $ordered[] = $lookup[$bundleKey];
            }
        }

        return $ordered;
    }

    private function url(string $pluginFile, string $relativePath): string
    {
        return (string) plugins_url($relativePath, $pluginFile);
    }
}
