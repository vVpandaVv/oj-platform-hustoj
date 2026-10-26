<?php
declare(strict_types=1);

namespace TeachingTools\Rest;

use TeachingTools\Rest\Controllers\Classroom_Controller;
use TeachingTools\Rest\Controllers\Judge_Controller;
use TeachingTools\Rest\Controllers\Integration_Controller;
use TeachingTools\Rest\Controllers\Ladder_Controller;
use TeachingTools\Rest\Controllers\Lesson_Template_Controller;
use TeachingTools\Rest\Controllers\Quiz_Controller;
use TeachingTools\Rest\Controllers\Quickboard_Controller;
use TeachingTools\Rest\Controllers\Realtime_Controller;
use TeachingTools\Rest\Controllers\Roster_Controller;
use TeachingTools\Rest\Controllers\Seating_Layout_Controller;
use TeachingTools\Rest\Controllers\Sessions_Controller;
use TeachingTools\Rest\Controllers\Session_Host_Controller;
use TeachingTools\Rest\Controllers\Whiteboard_Controller;

final class Api {
    public static function register_routes(): void {
        Sessions_Controller::register_routes();
        Session_Host_Controller::register_routes();
        Roster_Controller::register_routes();
        Ladder_Controller::register_routes();
        Lesson_Template_Controller::register_routes();
        Seating_Layout_Controller::register_routes();
        Quiz_Controller::register_routes();
        Quickboard_Controller::register_routes();
        Integration_Controller::register_routes();
        Judge_Controller::register_routes();
        Whiteboard_Controller::register_routes();
        Realtime_Controller::register_routes();
        Classroom_Controller::register_routes();
    }
}
