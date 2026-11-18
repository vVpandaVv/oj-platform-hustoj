<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !str_starts_with((string) getenv('LOGIC_TEST_DB_NAME'), 'release_logic_')) exit(1);
require getenv('LOGIC_TEST_WP_PATH') . '/wp-load.php';
if (!defined('OJ_LOGIC_LAB_VERSION') || OJ_LOGIC_LAB_VERSION !== '1.0.2') throw new RuntimeException('Upgraded plugin was not loaded in a new WordPress request');
$student = (int) get_user_by('login', 'logic_student')->ID; $other = (int) get_user_by('login', 'logic_other')->ID; $routeCases = 0;
function checkLogic(bool $ok, string $label): void { global $routeCases; if (!$ok) throw new RuntimeException($label); $routeCases++; }
require getenv('LOGIC_TEST_NEW_DIR') . '/tests/wordpress-route.php';
wp_set_current_user(1); $handler = new \OjLogicLab\Addon\PageHandler(); $handler->handle(new \CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1('addon.oj_logic_lab.learn', 1, [], [], 'ko_KR', 'warm'));
$before = $wpdb->num_queries; for ($i = 0; $i < 1000; $i++) $handler->handle(new \CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1('addon.oj_logic_lab.learn', 1, [], [], 'ko_KR', 'steady-state'));
$added = $wpdb->num_queries - $before; if ($added !== 0) throw new RuntimeException('Steady-state handler query budget exceeded: ' . $added);
echo 'Fresh upgraded WordPress request loaded ' . OJ_LOGIC_LAB_VERSION . ' routeCases=' . $routeCases . ' handlerRenders=1000 handlerQueries=' . $added . PHP_EOL;
