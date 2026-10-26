<?php
declare(strict_types=1);

namespace OjCodeArena\Rest\Controllers;

use OjCodeArena\Database\Round_Repository;
use OjCodeArena\Database\Tables;
use OjCodeArena\Rest\Api;
use OjCodeArena\Support\Capabilities;

/** Round records for teachers (their own class games; operators see all). */
final class Record_Controller {
    public static function register(): void {
        register_rest_route(Api::NS, '/records', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'index'),
            'permission_callback' => array(Capabilities::class, 'can_teach'),
        ));
    }

    public static function index(): \WP_REST_Response {
        global $wpdb;
        $repo = new Round_Repository();
        if (Capabilities::is_operator()) {
            return new \WP_REST_Response(array('rounds' => $repo->for_sessions(null)));
        }
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Tables::sessions() . ' WHERE owner_id = %d', get_current_user_id()));
        return new \WP_REST_Response(array('rounds' => $repo->for_sessions(array_map('intval', (array) $ids))));
    }
}
