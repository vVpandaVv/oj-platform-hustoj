<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\Modules\PublicUi\Domain\PublicMenuEntry;

/**
 * Mutable sink populated by AddonMenuContributor during boot and read by
 * PublicMenuSettingsService::defaults() on every request thereafter.
 *
 * @internal
 */
final class AddonPublicMenuContributions
{
    /** @var list<PublicMenuEntry> */
    private array $entries = [];

    /** @var array<string, string|null> */
    private array $capabilities = [];

    public function add(PublicMenuEntry $entry, ?string $capability): void
    {
        $this->entries[] = $entry;
        $this->capabilities[$entry->key()] = $capability;
    }

    /** @return list<PublicMenuEntry> */
    public function all(): array
    {
        return $this->entries;
    }

    public function capability(string $entryKey): ?string
    {
        return $this->capabilities[$entryKey] ?? null;
    }
}
