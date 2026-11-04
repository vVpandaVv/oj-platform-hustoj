<?php
declare(strict_types=1);
return [
    'id' => 'oj-microbit', 'name' => 'OJ Microbit', 'version' => '1.0.0-rc.9',
    'main_file' => 'oj-microbit.php', 'requires' => ['oj-platform:^2.5.9'],
    'soft_requires' => [], 'provides' => ['microbit_practice', 'microbit_pin_grading'],
];
