<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use Throwable;

final class WordPressPrivilegedAccountDetector
{
    public function isPrivileged(UserIdentity $identity): bool
    {
        if (in_array('administrator', $identity->roles(), true)) {
            return true;
        }

        try {
            if (function_exists('is_super_admin') && is_super_admin($identity->userId())) {
                return true;
            }
            $user = function_exists('get_user_by') ? get_user_by('id', $identity->userId()) : false;
            if (! is_object($user) || (int) ($user->ID ?? 0) !== $identity->userId()
                || ! is_array($user->allcaps ?? null)) {
                return true;
            }

            // WordPress merges role capabilities and individual grants/denials here.
            // Role-name markers are not capabilities; read-only learner grants are safe.
            $roleMarkers = is_array($user->roles ?? null) ? $user->roles : $identity->roles();
            $unprivilegedCapabilities = ['read', 'level_0', ...$roleMarkers, ...$this->unprivilegedAddonCapabilities()];
            foreach ($user->allcaps as $capability => $granted) {
                if ($granted && ! in_array((string) $capability, $unprivilegedCapabilities, true)) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Trusted addons may opt their own low-risk learner capabilities into this list.
     * This changes target protection only; it does not grant capabilities or bypass
     * the acting user's management permissions. Core and platform capabilities may
     * never be listed. A malformed result disables the entire extension list.
     *
     * @return list<string>
     */
    private function unprivilegedAddonCapabilities(): array
    {
        $capabilities = function_exists('apply_filters')
            ? apply_filters('oj_platform_unprivileged_addon_capabilities', [])
            : [];
        if (! is_array($capabilities) || ! array_is_list($capabilities)) {
            return [];
        }
        foreach ($capabilities as $capability) {
            // Match the mapped public Addon API key without depending on its module.
            if (! is_string($capability)
                || preg_match('/^oj_addon_[a-z][a-z0-9_]{2,159}$/D', $capability) !== 1) {
                return [];
            }
        }

        return $capabilities;
    }
}
