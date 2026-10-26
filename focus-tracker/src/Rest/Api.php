<?php
declare(strict_types=1);

namespace FocusTracker\Rest;

use FocusTracker\Rest\Controllers\Activities_Controller;
use FocusTracker\Rest\Controllers\Contexts_Controller;
use FocusTracker\Rest\Controllers\Events_Controller;
use FocusTracker\Rest\Controllers\Me_Controller;
use FocusTracker\Rest\Controllers\Scores_Controller;
use FocusTracker\Rest\Controllers\Checks_Controller;
use FocusTracker\Rest\Controllers\Statistics_Controller;
use FocusTracker\Rest\Controllers\Tracking_Controller;

final class Api {
    public static function register_routes(): void {
        Events_Controller::register_routes();
        Activities_Controller::register_routes();
        Scores_Controller::register_routes();
        Contexts_Controller::register_routes();
        Me_Controller::register_routes();
        Checks_Controller::register_routes();
        Statistics_Controller::register_routes();
        Tracking_Controller::register_routes();
    }
}
