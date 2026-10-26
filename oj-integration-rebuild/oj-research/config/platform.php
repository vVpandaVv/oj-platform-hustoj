<?php
declare(strict_types=1);

return array (
  'migration-extra-tables' => [
    ['name' => 'research_studies', 'wave' => 'research'],
    ['name' => 'research_participants', 'wave' => 'research'],
    ['name' => 'research_records', 'wave' => 'research'],
  ],
  'actions' =>
  array (
    0 =>
    array (
      'key' => 'oj_research_command',
      'owner_module' => 'research',
      'transport' => 'admin_post',
      'authentication' => 'authenticated',
    ),
  ),
  'features' =>
  array (
    0 =>
    array (
      'key' => 'research',
      'label' => '그룹 수업 연구',
      'required' => false,
      'default_enabled' => false,
      'dependencies' =>
      array (
        0 => 'groups',
        1 => 'ai_feedback',
      ),
    ),
  ),
  'menus' =>
  array (
    0 =>
    array (
      'key' => 'learning_support.research',
      'label' => '수업 연구',
      'parent' => 'learning_support',
      'route' => 'console.research.index',
      'feature_key' => 'research',
      'capabilities' =>
      array (
        0 => 'oj_access_console',
      ),
      'order' => 60,
      'policy' => NULL,
      'any_feature_keys' =>
      array (
      ),
    ),
  ),
  'modules' =>
  array (
    0 =>
    array (
      'key' => 'research',
      'type' => 'optional',
      'dependencies' =>
      array (
        0 => 'groups',
        1 => 'ai_feedback',
        2 => 'platform_db',
      ),
    ),
  ),
  'platform-tables' =>
  array (
    0 =>
    array (
      'name' => 'research_studies',
      'owner_module' => 'research',
      'wave' => 9,
      'engine' => 'InnoDB',
      'charset' => 'utf8mb4',
    ),
    1 =>
    array (
      'name' => 'research_participants',
      'owner_module' => 'research',
      'wave' => 9,
      'engine' => 'InnoDB',
      'charset' => 'utf8mb4',
    ),
    2 =>
    array (
      'name' => 'research_records',
      'owner_module' => 'research',
      'wave' => 9,
      'engine' => 'InnoDB',
      'charset' => 'utf8mb4',
    ),
  ),
  'routes' =>
  array (
    0 =>
    array (
      'name' => 'research.index',
      'owner_module' => 'research',
      'feature_key' => 'research',
      'path' => '/research',
      'area' => 'public',
      'methods' =>
      array (
        0 => 'GET',
        1 => 'HEAD',
      ),
      'authentication' => 'authenticated',
      'response_type' => 'html',
    ),
    1 =>
    array (
      'name' => 'console.research.index',
      'owner_module' => 'research',
      'feature_key' => 'research',
      'path' => '/oj-console/research',
      'area' => 'console',
      'methods' =>
      array (
        0 => 'GET',
        1 => 'HEAD',
      ),
      'authentication' => 'authenticated',
      'response_type' => 'html',
    ),
    2 =>
    array (
      'name' => 'console.research.rating',
      'owner_module' => 'research',
      'feature_key' => 'research',
      'path' => '/oj-console/research/rating',
      'area' => 'console',
      'methods' =>
      array (
        0 => 'GET',
        1 => 'HEAD',
      ),
      'authentication' => 'authenticated',
      'response_type' => 'html',
    ),
    3 =>
    array (
      'name' => 'console.research.new',
      'owner_module' => 'research',
      'feature_key' => 'research',
      'path' => '/oj-console/research/new',
      'area' => 'console',
      'methods' =>
      array (
        0 => 'GET',
        1 => 'HEAD',
      ),
      'authentication' => 'authenticated',
      'response_type' => 'html',
    ),
    4 =>
    array (
      'name' => 'console.research.report',
      'owner_module' => 'research',
      'feature_key' => 'research',
      'path' => '/oj-console/research/{study_id}/report',
      'area' => 'console',
      'methods' =>
      array (
        0 => 'GET',
        1 => 'HEAD',
      ),
      'authentication' => 'authenticated',
      'response_type' => 'html',
    ),
    5 =>
    array (
      'name' => 'console.research.show',
      'owner_module' => 'research',
      'feature_key' => 'research',
      'path' => '/oj-console/research/{study_id}',
      'area' => 'console',
      'methods' =>
      array (
        0 => 'GET',
        1 => 'HEAD',
      ),
      'authentication' => 'authenticated',
      'response_type' => 'html',
    ),
  ),
  'route-access' =>
  array (
    'console.research.index' =>
    array (
      0 => 'oj_access_console',
    ),
    'console.research.show' =>
    array (
      0 => 'oj_access_console',
    ),
    'console.research.rating' =>
    array (
      0 => 'oj_access_console',
    ),
    'console.research.new' =>
    array (
      0 => 'oj_access_console',
    ),
    'console.research.report' =>
    array (
      0 => 'oj_access_console',
    ),
  ),
  'additive-install-modules' =>
  array (
    0 => 'research',
  ),
);
