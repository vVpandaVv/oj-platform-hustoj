<?php
/**
 * Plugin Name: OJ Code Lab
 * Description: A Colab-style code practice notebook, isolated per student, with teacher review and a unified Kaggle/공공데이터포털/GitHub/upload dataset import interface, as an oj-platform addon.
 * Version: 1.4.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: CodingPanda
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: oj-code-lab
 * Domain Path: /languages
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */

/*
 * OJ Code Lab is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 2 of the License, or any later version.
 *
 * OJ Code Lab is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See LICENSE.txt for the full license.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('OJ_CODE_LAB_VERSION', '1.4.0');
define('OJ_CODE_LAB_FILE', __FILE__);
define('OJ_CODE_LAB_PATH', plugin_dir_path(__FILE__));
define('OJ_CODE_LAB_URL', plugin_dir_url(__FILE__));

require_once OJ_CODE_LAB_PATH . 'src/Core/Autoloader.php';

\OjCodeLab\Core\Autoloader::register();
\OjCodeLab\Core\Plugin::boot();

if (! function_exists('oj_code_lab_activate')) {
    function oj_code_lab_activate(bool $network_wide = false): void {
        if ($network_wide) {
            wp_die(esc_html__('OJ Code Lab은 사이트별로 활성화해 주세요. 네트워크 전체 활성화는 지원하지 않습니다.', 'oj-code-lab'));
        }
        if (
            ! defined('OJ_PLATFORM_VERSION')
            || version_compare(OJ_PLATFORM_VERSION, '2.0.0-dev.30', '<')
            || version_compare(OJ_PLATFORM_VERSION, '3.0.0', '>=')
            || ! defined('OJ_PLATFORM_ADDON_API_VERSION')
            || version_compare(OJ_PLATFORM_ADDON_API_VERSION, '1.0.0', '<')
            || version_compare(OJ_PLATFORM_ADDON_API_VERSION, '2.0.0', '>=')
            || ! class_exists(\CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::class)
        ) {
            wp_die(
                esc_html__('OJ Code Lab requires OJ Platform 2.0.0-dev.30 or newer.', 'oj-code-lab'),
                esc_html__('Plugin dependency check failed', 'oj-code-lab'),
                array('response' => 500)
            );
        }

        \OjCodeLab\Database\Installer::install();
        if (get_option('oj_code_lab_install_error')) {
            wp_die(esc_html((string) get_option('oj_code_lab_install_error')));
        }
    }
}

if (! function_exists('oj_code_lab_deactivate')) {
    function oj_code_lab_deactivate(): void {
        \OjCodeLab\Jobs\Dataset_Cache_Cleanup::unschedule();
    }
}

register_activation_hook(OJ_CODE_LAB_FILE, 'oj_code_lab_activate');
register_deactivation_hook(OJ_CODE_LAB_FILE, 'oj_code_lab_deactivate');
