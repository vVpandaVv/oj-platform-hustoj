<?php

declare(strict_types=1);

namespace OjAddonStarter\Rest;

use OjAddonStarter\Core\Plugin;
use OjAddonStarter\Storage\NoteRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class NoteApi
{
    public const NAMESPACE = 'oj-addon-starter/v1';

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/note', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'read'],
                'permission_callback' => [self::class, 'permission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'save'],
                'permission_callback' => [self::class, 'permission'],
            ],
        ]);
    }

    public static function permission(): bool|WP_Error
    {
        if (Plugin::runtime() === null) {
            return new WP_Error('starter_unavailable', '애드온을 사용할 수 없습니다.', ['status' => 503]);
        }
        if (! is_user_logged_in()) {
            return new WP_Error('starter_authentication_required', '로그인이 필요합니다.', ['status' => 401]);
        }
        if (! Plugin::can_use()) {
            return new WP_Error('starter_forbidden', '시작 예제 사용 권한이 필요합니다.', ['status' => 403]);
        }
        return true;
    }

    public static function read(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $permission = self::permission();
        if ($permission instanceof WP_Error) {
            return $permission;
        }
        return self::response(['note' => NoteRepository::read_for_current_user()]);
    }

    public static function save(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $permission = self::permission();
        if ($permission instanceof WP_Error) {
            return $permission;
        }
        $input = $request->get_json_params();
        if (! is_array($input) || count($input) !== 1 || ! array_key_exists('note', $input)) {
            return new WP_Error('starter_invalid_body', 'JSON 본문에 note 문자열 하나를 보내 주세요.', ['status' => 400]);
        }
        $result = NoteRepository::save_for_current_user($input['note']);
        return $result instanceof WP_Error ? $result : self::response($result);
    }

    /** @param array{note: string} $payload */
    private static function response(array $payload): WP_REST_Response
    {
        $response = new WP_REST_Response($payload, 200);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }
}
