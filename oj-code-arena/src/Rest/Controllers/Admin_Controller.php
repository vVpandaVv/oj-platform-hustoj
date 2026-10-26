<?php
declare(strict_types=1);

namespace OjCodeArena\Rest\Controllers;

use OjCodeArena\Rest\Api;
use OjCodeArena\Support\Capabilities;
use OjCodeArena\Support\Game_Server;

/** "상태 확인" button on the console settings page (site admins or the "코드 아레나 설정" capability). */
final class Admin_Controller {
    public static function register(): void {
        register_rest_route(Api::NS, '/admin/status', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'status'),
            'permission_callback' => static fn (): bool => Capabilities::can_manage_settings(),
        ));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function status() {
        $res = Game_Server::status();
        return is_wp_error($res) ? $res : new \WP_REST_Response($res);
    }
}
