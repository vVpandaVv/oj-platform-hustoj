<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Rest;

use OjCodeLab\Rest\Controllers\Contexts_Controller;
use OjCodeLab\Rest\Controllers\Dataset_Controller;
use OjCodeLab\Rest\Controllers\Notebook_Controller;
use OjCodeLab\Rest\Controllers\Roster_Controller;
use OjCodeLab\Rest\Controllers\Settings_Controller;

final class Api {
    public static function register_routes(): void {
        Notebook_Controller::register_routes();
        Contexts_Controller::register_routes();
        Roster_Controller::register_routes();
        Dataset_Controller::register_routes();
        Settings_Controller::register_routes();
    }
}
