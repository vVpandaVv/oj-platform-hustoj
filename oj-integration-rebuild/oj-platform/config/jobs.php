<?php

declare(strict_types=1);

return [
    ['key' => 'platform_install', 'owner_module' => 'platform_db', 'definition_version' => '1', 'max_attempts' => 1, 'uses_steps' => true],
    ['key' => 'problem_metadata_generate', 'owner_module' => 'problems', 'definition_version' => '1', 'max_attempts' => 3, 'uses_steps' => false],
    ['key' => 'problem_authoring_generate', 'owner_module' => 'problems', 'definition_version' => '1', 'max_attempts' => 3, 'uses_steps' => false],
    ['key' => 'problem_testcase_generate', 'owner_module' => 'problems', 'definition_version' => '1', 'max_attempts' => 3, 'uses_steps' => false],
    ['key' => 'submission_ai_feedback_generate', 'owner_module' => 'ai_feedback', 'definition_version' => '1', 'max_attempts' => 3, 'uses_steps' => false],
    ['key' => 'user_csv_import', 'owner_module' => 'auth', 'definition_version' => '1', 'max_attempts' => 3, 'uses_steps' => false],
    ['key' => 'group_member_csv_import', 'owner_module' => 'groups', 'definition_version' => '1', 'max_attempts' => 3, 'uses_steps' => false],
    ['key' => 'user_account_erasure', 'owner_module' => 'auth', 'definition_version' => '1', 'max_attempts' => 1, 'uses_steps' => true],
    ['key' => 'platform_migration', 'owner_module' => 'migration', 'definition_version' => '1', 'max_attempts' => 1, 'uses_steps' => true],
    ['key' => 'problem_number_change', 'owner_module' => 'problems', 'definition_version' => '1', 'max_attempts' => 1, 'uses_steps' => true],
    ['key' => 'problem_testcase_apply', 'owner_module' => 'problems', 'definition_version' => '1', 'max_attempts' => 1, 'uses_steps' => true],
    ['key' => 'problem_spj_compile', 'owner_module' => 'problems', 'definition_version' => '1', 'max_attempts' => 1, 'uses_steps' => true],
    ['key' => 'quiz_revision_reapply', 'owner_module' => 'problems', 'definition_version' => '1', 'max_attempts' => 1, 'uses_steps' => true],
];
