<?php
declare(strict_types=1);

namespace OjCodeArena\Rest;

use OjCodeArena\Rest\Controllers\Admin_Controller;
use OjCodeArena\Rest\Controllers\Bank_Controller;
use OjCodeArena\Rest\Controllers\Loadtest_Controller;
use OjCodeArena\Rest\Controllers\Play_Controller;
use OjCodeArena\Rest\Controllers\Record_Controller;
use OjCodeArena\Rest\Controllers\Server_Controller;
use OjCodeArena\Rest\Controllers\Session_Controller;

final class Api {
    public const NS = 'oj-code-arena/v1';

    public static function register_routes(): void {
        Play_Controller::register();
        Session_Controller::register();
        Bank_Controller::register();
        Record_Controller::register();
        Server_Controller::register();
        Admin_Controller::register();
        Loadtest_Controller::register();
    }

    public static function error(string $code, string $message, int $status): \WP_Error {
        return new \WP_Error('oj_code_arena_' . $code, $message, array('status' => $status));
    }
}
