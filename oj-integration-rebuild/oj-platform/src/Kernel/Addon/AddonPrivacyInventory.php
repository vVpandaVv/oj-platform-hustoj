<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPrivacyDataDefinitionV1;

/** @internal */
final class AddonPrivacyInventory
{
    /** @var list<array{addon:string,definition:AddonPrivacyDataDefinitionV1}> */
    private array $items = [];

    public function replace(AcceptedAddonCatalog $catalog): void
    {
        $items = [];
        foreach ($catalog->accepted() as $entry) {
            $manifest = $entry->manifest();
            if (! $manifest instanceof AddonManifest) {
                continue;
            }
            foreach ($manifest->privacyData() as $definition) {
                $items[] = [
                    'addon' => $manifest->diagnosticLabel(),
                    'definition' => $definition,
                ];
            }
        }
        $this->items = $items;
    }

    /** @return list<array{addon:string,definition:AddonPrivacyDataDefinitionV1}> */
    public function all(): array
    {
        return $this->items;
    }
}
