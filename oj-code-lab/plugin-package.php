<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

return array(
    'id' => 'oj-code-lab',
    'name' => 'OJ Code Lab',
    'version' => '1.4.0',
    'main_file' => 'oj-code-lab.php',
    'requires' => array('oj-platform:^2.0.0-dev.30'),
    'soft_requires' => array(),
    'provides' => array('code_lab_notebook', 'code_lab_dataset_import'),
);
