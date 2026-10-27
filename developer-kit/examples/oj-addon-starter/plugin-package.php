<?php

declare(strict_types=1);

return [
    'id' => 'oj-addon-starter',
    'name' => 'OJ Addon Starter',
    'version' => '1.0.0',
    'main_file' => 'oj-addon-starter.php',
    'requires' => ['oj-platform:^2.5.1'],
    'soft_requires' => [],
    'provides' => ['addon_starter_personal_note'],
];
