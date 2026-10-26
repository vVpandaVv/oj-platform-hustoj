<?php

declare(strict_types=1);

return [
    ['key' => 'permission', 'type' => 'required', 'dependencies' => []],
    ['key' => 'settings', 'type' => 'required', 'dependencies' => []],
    ['key' => 'platform_db', 'type' => 'required', 'dependencies' => ['settings']],
    ['key' => 'judge_db', 'type' => 'required', 'dependencies' => ['settings']],
    ['key' => 'jobs', 'type' => 'required', 'dependencies' => ['platform_db', 'settings']],
    ['key' => 'access_router', 'type' => 'required', 'dependencies' => ['permission', 'settings', 'jobs']],
    ['key' => 'auth', 'type' => 'required', 'dependencies' => ['permission', 'settings', 'platform_db', 'judge_db', 'jobs']],
    ['key' => 'public_ui', 'type' => 'required', 'dependencies' => ['auth', 'access_router']],
    ['key' => 'admin_console', 'type' => 'required', 'dependencies' => ['auth', 'permission', 'settings', 'access_router']],
    ['key' => 'problems', 'type' => 'optional', 'dependencies' => ['auth', 'platform_db', 'judge_db', 'jobs']],
    ['key' => 'ranking', 'type' => 'optional', 'dependencies' => ['auth', 'problems', 'submissions']],
    ['key' => 'submissions', 'type' => 'optional', 'dependencies' => ['auth', 'problems', 'judge_db', 'jobs']],
    ['key' => 'blockly', 'type' => 'optional', 'dependencies' => ['problems', 'submissions']],
    ['key' => 'ai_feedback', 'type' => 'optional', 'dependencies' => ['auth', 'problems', 'submissions', 'platform_db', 'jobs']],
    ['key' => 'learning_dashboard', 'type' => 'optional', 'dependencies' => ['auth', 'problems', 'submissions']],
    ['key' => 'board', 'type' => 'optional', 'dependencies' => ['permission', 'settings', 'access_router', 'auth']],
    ['key' => 'classes', 'type' => 'optional', 'dependencies' => ['auth', 'problems', 'submissions', 'platform_db', 'judge_db']],
    ['key' => 'contests', 'type' => 'optional', 'dependencies' => ['auth', 'problems', 'submissions', 'platform_db', 'judge_db']],
    ['key' => 'groups', 'type' => 'optional', 'dependencies' => ['auth', 'platform_db', 'submissions', 'judge_db', 'jobs']],
    ['key' => 'messaging', 'type' => 'optional', 'dependencies' => ['permission', 'settings', 'access_router', 'auth', 'platform_db']],
    ['key' => 'migration', 'type' => 'locked', 'dependencies' => ['platform_db', 'judge_db', 'jobs']],
];
