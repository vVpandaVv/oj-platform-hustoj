<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Rest\Controllers;

use OjCodeLab\Datasets\Kaggle_Settings;
use OjCodeLab\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Store the site-wide Kaggle credentials (Addon\Console_Settings_Page_Handler).
 * Read, save and clear all require is_super_admin() or the WordPress
 * capability for "코드 실습실 설정" (Capabilities::can_manage_settings());
 * the wp_rest nonce is checked by WordPress REST cookie auth as before.
 * GET never returns the raw key — only whether one is currently configured.
 */
final class Settings_Controller {
    public static function register_routes(): void {
        register_rest_route('oj-code-lab/v1', '/settings/kaggle', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'get'),
                'permission_callback' => array(__CLASS__, 'can_manage_settings'),
            ),
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array(__CLASS__, 'save'),
                'permission_callback' => array(__CLASS__, 'can_manage_settings'),
            ),
        ));
    }

    public static function can_manage_settings(): bool|WP_Error {
        return Capabilities::can_manage_settings() ?: new WP_Error('oj_code_lab_forbidden', __('설정 권한이 없습니다.', 'oj-code-lab'), array('status' => 403));
    }

    public static function get(WP_REST_Request $request): WP_REST_Response {
        return rest_ensure_response(array(
            'configured' => Kaggle_Settings::is_configured(),
            'username' => Kaggle_Settings::username(),
        ));
    }

    public static function save(WP_REST_Request $request): WP_REST_Response|WP_Error {
        if (true === $request->get_param('clear')) {
            $result = Kaggle_Settings::clear();
        } else {
            $username = $request->get_param('username');
            $key = $request->get_param('key') ?? '';
            if (! is_string($username) || ! is_string($key)) {
                return new WP_Error('oj_code_lab_invalid_settings', '사용자 이름과 API 키는 문자열이어야 합니다.', array('status' => 400));
            }
            $result = Kaggle_Settings::save($username, $key);
        }
        if (is_wp_error($result)) { return $result; }

        return rest_ensure_response(array(
            'configured' => Kaggle_Settings::is_configured(),
            'username' => Kaggle_Settings::username(),
        ));
    }
}
