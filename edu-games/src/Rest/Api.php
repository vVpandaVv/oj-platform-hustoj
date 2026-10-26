<?php
declare(strict_types=1);

namespace EduGames\Rest;

use EduGames\Rest\Controllers\AI_Model_Lab_Controller;
use EduGames\Rest\Controllers\AI_Model_Lab_Report_Controller;
use EduGames\Rest\Controllers\Games_Admin_Controller;
use EduGames\Rest\Controllers\Games_Controller;
use EduGames\Rest\Controllers\Leaderboard_Controller;
use EduGames\Rest\Controllers\Player_Stats_Controller;
use EduGames\Rest\Controllers\Teams_Controller;

final class Api {
    public static function register_routes(): void {
        Games_Controller::register_routes();
        Controllers\Learning_Controller::register_routes();
        Controllers\Warehouse_Controller::register_routes();
        Controllers\Strategy_Controller::register_routes();
        Controllers\Play_Controller::register_routes();
        Controllers\Progress_Controller::register_routes();
        Controllers\Report_Controller::register_routes();
        Leaderboard_Controller::register_routes();
        Controllers\Ranking_Records_Controller::register_routes();
        Player_Stats_Controller::register_routes();
        Teams_Controller::register_routes();
        Games_Admin_Controller::register_routes();
        AI_Model_Lab_Controller::register_routes();
        AI_Model_Lab_Report_Controller::register_routes();
    }
}
