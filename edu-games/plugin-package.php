<?php
return array(
    'id' => 'edu-games',
    'name' => 'Edu Games',
    'version' => '1.13.1',
    'main_file' => 'edu-games.php',
    'requires' => array(
        'oj-platform:^2.0.0-dev.30',
    ),
    'provides' => array(
        'game_module_registry',
        'game_stats_provider',
        'leaderboard_provider',
    ),
);
