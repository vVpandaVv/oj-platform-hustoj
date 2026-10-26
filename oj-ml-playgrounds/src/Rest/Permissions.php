<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Rest;

use OJML\Playgrounds\Security\Addon_Capabilities;
use WP_Error;
use WP_REST_Request;

final class Permissions
{
    public static function learnerRead(WP_REST_Request $request): true|WP_Error
    {
        return self::any($request, false, [Addon_Capabilities::USE, Addon_Capabilities::SUBMIT]);
    }

    public static function learnerMutation(WP_REST_Request $request): true|WP_Error
    {
        return self::all($request, true, [Addon_Capabilities::SUBMIT]);
    }

    public static function manageRead(WP_REST_Request $request): true|WP_Error
    {
        return self::all($request, false, [Addon_Capabilities::MANAGE]);
    }

    public static function manageMutation(WP_REST_Request $request): true|WP_Error
    {
        return self::all($request, true, [Addon_Capabilities::MANAGE]);
    }

    public static function reviewRead(WP_REST_Request $request): true|WP_Error
    {
        return self::any($request, false, [
            Addon_Capabilities::REVIEW,
            Addon_Capabilities::REPORT,
            Addon_Capabilities::MANAGE,
        ]);
    }

    public static function reviewMutation(WP_REST_Request $request): true|WP_Error
    {
        return self::any($request, true, [Addon_Capabilities::REVIEW, Addon_Capabilities::MANAGE]);
    }

    public static function artifactPreview(WP_REST_Request $request): true|WP_Error
    {
        return self::any($request, true, [
            Addon_Capabilities::REVIEW,
            Addon_Capabilities::MANAGE,
        ]);
    }

    public static function reportRead(WP_REST_Request $request): true|WP_Error
    {
        return self::all($request, false, [Addon_Capabilities::REPORT]);
    }

    /** Site administrators or holders of the SETTINGS capability (is_super_admin() || current_user_can()). */
    public static function settingsRead(WP_REST_Request $request): true|WP_Error
    {
        $guard = self::requestGuard($request, false);
        if ($guard instanceof WP_Error) {
            return $guard;
        }
        return Addon_Capabilities::current_user_can_manage_settings() ? true : self::forbidden();
    }

    /** @param list<string> $declaredCapabilities */
    private static function all(WP_REST_Request $request, bool $mutation, array $declaredCapabilities): true|WP_Error
    {
        $guard = self::requestGuard($request, $mutation);
        if ($guard instanceof WP_Error) {
            return $guard;
        }
        foreach ($declaredCapabilities as $declaredCapability) {
            $capability = Addon_Capabilities::wordpress($declaredCapability);
            if ($capability === '' || !current_user_can($capability)) {
                return self::forbidden();
            }
        }
        return true;
    }

    /** @param list<string> $declaredCapabilities */
    private static function any(WP_REST_Request $request, bool $mutation, array $declaredCapabilities): true|WP_Error
    {
        $guard = self::requestGuard($request, $mutation);
        if ($guard instanceof WP_Error) {
            return $guard;
        }
        foreach ($declaredCapabilities as $declaredCapability) {
            $capability = Addon_Capabilities::wordpress($declaredCapability);
            if ($capability !== '' && current_user_can($capability)) {
                return true;
            }
        }
        return self::forbidden();
    }

    private static function requestGuard(WP_REST_Request $request, bool $mutation): true|WP_Error
    {
        if (get_current_user_id() < 1) {
            return new WP_Error('OJ_ML_AUTHENTICATION_REQUIRED', '로그인이 필요합니다.', ['status' => 401]);
        }
        if (!$mutation) {
            return true;
        }
        if (\OJML\Playgrounds\Ops\Options::all()['maintenanceMode']) {
            return new WP_Error('OJ_ML_MAINTENANCE', 'ML 실습 점검 중입니다. 입력 내용을 보관하고 잠시 후 다시 시도하세요.', ['status' => 503]);
        }
        $nonce = $request->get_header('X-WP-Nonce');
        if ($nonce === '') {
            $candidate = $request->get_param('_wpnonce');
            $nonce = is_string($candidate) ? $candidate : '';
        }
        if ($nonce === '' || wp_verify_nonce($nonce, 'wp_rest') === false) {
            return new WP_Error(
                'OJ_ML_REST_NONCE_INVALID',
                '요청 보안 토큰이 만료되었거나 올바르지 않습니다.',
                ['status' => 403]
            );
        }
        return true;
    }

    private static function forbidden(): WP_Error
    {
        return new WP_Error('OJ_ML_CAPABILITY_REQUIRED', '이 작업을 수행할 권한이 없습니다.', ['status' => 403]);
    }
}


