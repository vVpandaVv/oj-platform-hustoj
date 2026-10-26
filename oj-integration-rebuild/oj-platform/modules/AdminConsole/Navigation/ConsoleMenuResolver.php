<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Navigation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandlerCollection;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Application\MenuTree;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use Throwable;

final class ConsoleMenuResolver
{
    /**
     * @param list<array{key: string, label: string, members: list<array{0: string, 1: string, 2?: string}>}> $localNavGroups
     *        config/console-local-nav.php: menu items folded into one sidebar entry
     */
    public function __construct(
        private readonly MenuTree $menus,
        private readonly RouteHandlerCollection $handlers,
        private readonly RouteUrlProvider $urls,
        private readonly FeatureFlags $features,
        private readonly Authorization $permissions,
        private readonly ConsoleMenuAccessRuleRegistry $contextRules,
        private readonly array $localNavGroups = []
    ) {
    }

    /**
     * @return list<ConsoleMenuItem>
     */
    public function resolve(int $userId): array
    {
        $resolved = [];
        foreach ($this->menus->roots() as $root) {
            $children = [];
            foreach ($this->menus->childrenOf($root->key()) as $child) {
                if (! $this->isAccessible($child, $userId)) {
                    continue;
                }

                $children[] = new ConsoleMenuItem(
                    $child->key(),
                    $child->label(),
                    $this->urls->path($child->route()),
                    [],
                    (string) $child->section(),
                    icon: $child->icon()
                );
            }

            $children = $this->foldLocalNavGroups($children);

            if ($root->route() === 'first_accessible_child') {
                if ($children === []) {
                    continue;
                }
                $url = $children[0]->url();
            } elseif ($this->isAccessible($root, $userId)) {
                $url = $this->urls->path($root->route());
            } elseif ($children !== []) {
                $url = $children[0]->url();
            } else {
                continue;
            }

            $resolved[] = new ConsoleMenuItem(
                $root->key(),
                $root->label(),
                $url,
                $children,
                (string) $root->section(),
                icon: $root->icon()
            );
        }

        return $resolved;
    }

    /**
     * Replaces the accessible members of each local-nav group with one entry
     * placed where the first member stood. Each member keeps its own feature
     * and permission check; the entry links to the first view member.
     *
     * @param list<ConsoleMenuItem> $children
     * @return list<ConsoleMenuItem>
     */
    private function foldLocalNavGroups(array $children): array
    {
        if ($this->localNavGroups === [] || $children === []) {
            return $children;
        }

        $positions = [];
        foreach ($children as $index => $child) {
            $positions[$child->key()] = $index;
        }

        $replacements = [];
        $removed = [];
        foreach ($this->localNavGroups as $group) {
            $members = [];
            $first = null;
            $section = '';
            foreach ($group['members'] as $member) {
                $index = $positions[$member[0]] ?? null;
                if ($index === null || isset($removed[$member[0]])) {
                    continue;
                }
                $child = $children[$index];
                $isAction = ($member[2] ?? '') === 'action';
                $members[] = new ConsoleMenuItem($child->key(), $member[1], $child->url(), [], '', [], $isAction);
                $removed[$member[0]] = true;
                $first = $first === null ? $index : min($first, $index);
                if ($child->key() === $group['key'] || $section === '') {
                    $section = $child->section();
                }
            }
            if ($members === []) {
                continue;
            }

            $landing = null;
            foreach ($members as $member) {
                if (! $member->isAction()) {
                    $landing = $member;
                    break;
                }
            }
            $landing ??= $members[0];
            $replacements[$first] = new ConsoleMenuItem(
                $group['key'],
                $group['label'],
                $landing->url(),
                [],
                $section,
                $members
            );
        }

        $folded = [];
        foreach ($children as $index => $child) {
            if (isset($replacements[$index])) {
                $folded[] = $replacements[$index];
            }
            if (! isset($removed[$child->key()])) {
                $folded[] = $child;
            }
        }

        return $folded;
    }

    private function isAccessible(MenuDefinition $menu, int $userId): bool
    {
        if (
            $menu->policy() === 'super_admin_only'
            && ! $this->permissions->isSuperAdmin($userId)
        ) {
            return false;
        }

        if (
            $menu->route() === 'first_accessible_child'
            || $this->handlers->find($menu->route()) === null
        ) {
            return false;
        }

        if ($menu->featureKey() !== null) {
            try {
                if (! $this->features->isEnabled($menu->featureKey())) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }

        if ($menu->anyFeatureKeys() !== []) {
            $anyEnabled = false;
            foreach ($menu->anyFeatureKeys() as $featureKey) {
                try {
                    if ($this->features->isEnabled($featureKey)) {
                        $anyEnabled = true;
                        break;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
            if (! $anyEnabled) {
                return false;
            }
        }

        if ($menu->policy() === 'all_capabilities') {
            if ($menu->capabilities() === []) {
                return false;
            }
            foreach ($menu->capabilities() as $capability) {
                if (! $this->permissions->can($userId, $capability)) {
                    return false;
                }
            }

            return true;
        }

        return $menu->capabilities() === []
            || $this->permissions->canAny($userId, $menu->capabilities())
            || $this->contextRules->allows($userId, $menu);
    }
}
