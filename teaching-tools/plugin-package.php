<?php
return array(
    'id' => 'teaching-tools',
    'name' => 'Teaching Tools',
    'version' => '1.2.8',
    'main_file' => 'teaching-tools.php',
    'requires' => array(
        'oj-platform:^2.3.0',
    ),
    'provides' => array(
        'teaching_tool_sessions',
    ),
);
