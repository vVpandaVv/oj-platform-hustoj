<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountProtectionDiagnostic;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LearnerAddonCapabilities;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use Throwable;

final class WordPressPrivilegedAccountDetector
{
    public function __construct(private readonly ?LearnerAddonCapabilities $learnerCapabilities = null)
    {
    }

    public function isPrivileged(UserIdentity $identity): bool
    {
        return $this->inspect($identity)->isProtected();
    }

    public function inspect(UserIdentity $identity): AccountProtectionDiagnostic
    {
        if (in_array('administrator', $identity->roles(), true)) {
            return new AccountProtectionDiagnostic(true, 'administrator_role');
        }

        $stage = 'super_admin_check';
        try {
            if (function_exists('is_super_admin') && is_super_admin($identity->userId())) {
                return new AccountProtectionDiagnostic(true, 'super_admin');
            }
            $stage = 'user_lookup';
            if (! function_exists('get_user_by')) {
                return new AccountProtectionDiagnostic(true, 'lookup_unavailable');
            }
            $user = get_user_by('id', $identity->userId());
            if (! is_object($user)) {
                return new AccountProtectionDiagnostic(true, 'account_missing');
            }
            if ((int) ($user->ID ?? 0) !== $identity->userId()) {
                return new AccountProtectionDiagnostic(true, 'identity_mismatch');
            }
            $stage = 'capability_data';
            if (! is_array($user->allcaps ?? null)) {
                return new AccountProtectionDiagnostic(true, 'capability_data_invalid');
            }

            // WordPress merges role capabilities and individual grants/denials here.
            // Role-name markers are not capabilities; read-only learner grants are safe.
            $roleMarkers = is_array($user->roles ?? null) ? $user->roles : $identity->roles();
            $stage = 'addon_filter';
            $filtered = function_exists('apply_filters')
                ? apply_filters('oj_platform_unprivileged_addon_capabilities', []) : [];
            $stage = 'addon_catalog';
            $declared = $this->learnerCapabilities?->capabilityKeys() ?? [];
            $fault = ! $this->validAddonCapabilities($filtered) ? 'addon_filter_invalid'
                : (! $this->validAddonCapabilities($declared) ? 'addon_catalog_invalid' : null);
            // Invalid extension lists retain the existing all-or-nothing fallback.
            $addonCapabilities = $fault === null ? array_merge($filtered, $declared) : [];
            $unprivilegedCapabilities = ['read', 'level_0', ...$roleMarkers, ...$addonCapabilities];
            $stage = 'capability_scan';
            $blocking = [];
            foreach ($user->allcaps as $capability => $granted) {
                if ($granted && ! in_array((string) $capability, $unprivilegedCapabilities, true)) {
                    $blocking[] = (string) $capability;
                }
            }

            return new AccountProtectionDiagnostic(
                $blocking !== [], $blocking === [] ? 'not_protected' : 'capability_blocked',
                $blocking, $fault, null, count(array_unique($addonCapabilities))
            );
        } catch (Throwable $error) {
            $type = match (true) {
                $error instanceof \ArgumentCountError => 'ArgumentCountError',
                $error instanceof \TypeError => 'TypeError',
                $error instanceof \Error => 'Error',
                $error instanceof \RuntimeException => 'RuntimeException',
                $error instanceof \LogicException => 'LogicException',
                default => 'Throwable',
            };

            return new AccountProtectionDiagnostic(true, 'check_failed', [], $stage . '_failed', $type, 0, $this->sourceLocation($error));
        }
    }

    private function validAddonCapabilities(mixed $capabilities): bool
    {
        if (! is_array($capabilities) || ! array_is_list($capabilities)) {
            return false;
        }
        foreach ($capabilities as $capability) {
            if (! is_string($capability)
                || preg_match('/^oj_addon_[a-z][a-z0-9_]{2,159}$/D', $capability) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function sourceLocation(Throwable $error): ?string
    {
        $file = str_replace('\\', '/', $error->getFile());
        $name = basename($file);
        if (preg_match('/^[A-Za-z0-9_.-]{1,100}\.php$/D', $name) !== 1 || $error->getLine() < 1) {
            return null;
        }
        $plugins = defined('WP_PLUGIN_DIR') ? rtrim(str_replace('\\', '/', (string) WP_PLUGIN_DIR), '/') . '/' : '';
        if ($plugins !== '' && str_starts_with($file, $plugins)) {
            $slug = explode('/', substr($file, strlen($plugins)))[0];
            if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $slug) === 1) {
                $name = $slug . '/' . $name;
            }
        }

        return $name . ':' . $error->getLine();
    }
}
