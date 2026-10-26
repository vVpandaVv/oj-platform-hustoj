<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;

/**
 * Single source of truth for this addon's manifest capability keys, mirroring
 * focus-tracker's Support\Capabilities. OJ Platform maps each declared key to
 * a real WordPress capability once the manifest is accepted.
 *
 * Settings and 추천 데이터 management also admit site administrators
 * (is_super_admin()), matching the platform's own console page check, so
 * they never need the addon capability granted explicitly.
 */
final class Capabilities {
    public const USE = 'oj_code_lab_use';
    public const REVIEW = 'oj_code_lab_review';
    public const MANAGE_DATASETS = 'oj_code_lab_manage_datasets';
    public const SETTINGS = 'oj_code_lab_settings';

    public static function can_use(): bool {
        return self::current_user_can(self::USE);
    }

    public static function can_review(): bool {
        return self::current_user_can(self::REVIEW);
    }

    public static function can_manage_datasets(): bool {
        return self::is_site_admin() || self::current_user_can(self::MANAGE_DATASETS);
    }

    /**
     * Gate for every handler the settings page uses (read, save, clear):
     * is_super_admin() || current_user_can(oj_addon_oj_code_lab_settings).
     */
    public static function can_manage_settings(): bool {
        return self::is_site_admin() || self::current_user_can(self::SETTINGS);
    }

    private static function is_site_admin(): bool {
        return is_user_logged_in() && function_exists('is_super_admin') && is_super_admin();
    }

    private static function current_user_can(string $declared_key): bool {
        return is_user_logged_in()
            && class_exists(AddonCapabilityKeyV1::class)
            && current_user_can(AddonCapabilityKeyV1::wordpress($declared_key));
    }
}
