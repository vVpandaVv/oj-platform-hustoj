<?php

return array(
	'id'            => 'oj-ml-playgrounds',
	'name'          => 'OJ ML Playgrounds',
    'version'       => '1.4.2',
	'main_file'     => 'oj-ml-playgrounds.php',
	'requires'      => array(
		'oj-platform:^2.0.0-dev.33',
	),
	'soft_requires' => array(),
	'provides'      => array(
		'ml_playgrounds',
		'ml_assignment_provider',
		'ml_result_provider',
	),
);
