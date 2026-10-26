<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\WordPress;

use CodingPanda\OjPlatform\Kernel\Addon\AddonNamespaceLedger;

/** @internal */
final class WordPressAddonNamespaceLedger implements AddonNamespaceLedger
{
    private const OPTION = 'oj_platform_addon_namespace_ledger';

    public function ownerOf(string $namespace): string
    {
        $ledger = $this->read();

        return (string) ($ledger[$namespace] ?? '');
    }

    public function bind(string $namespace, string $addonId): void
    {
        $ledger = $this->read();
        if (($ledger[$namespace] ?? null) === $addonId) {
            return;
        }
        $ledger[$namespace] = $addonId;
        if (function_exists('update_option')) {
            update_option(self::OPTION, $ledger, false);
        }
    }

    /** @return array<string, string> */
    private function read(): array
    {
        if (! function_exists('get_option')) {
            return [];
        }
        $value = get_option(self::OPTION, []);

        return is_array($value) ? array_filter($value, 'is_string') : [];
    }
}
