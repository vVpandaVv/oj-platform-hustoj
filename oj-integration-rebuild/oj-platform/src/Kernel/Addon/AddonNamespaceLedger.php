<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

/**
 * Persists which addonId a manifest namespace was first accepted under,
 * across boots. Without this, a different addon reusing a departed addon's
 * namespace would silently reuse any AI task routing assignment an operator
 * previously approved for that namespace's task keys (routing assignments
 * are keyed only by task-type string, with no addon-identity binding).
 *
 * @internal
 */
interface AddonNamespaceLedger
{
    /** @return string the addonId bound to this namespace, or '' if unbound */
    public function ownerOf(string $namespace): string;

    public function bind(string $namespace, string $addonId): void;
}
