<?php

declare(strict_types=1);

return [
    [
        'name' => 'learning_groups',
        'owner_module' => 'groups',
        'schema_version' => '1',
        'columns' => [
            ['name' => 'id', 'type' => 'BIGINT UNSIGNED', 'auto_increment' => true],
            ['name' => 'name', 'type' => 'VARCHAR(200)'],
            ['name' => 'slug', 'type' => 'VARCHAR(200)'],
            ['name' => 'description', 'type' => 'LONGTEXT'],
            ['name' => 'visibility', 'type' => 'VARCHAR(30)', 'default' => 'private'],
            ['name' => 'approval_mode', 'type' => 'VARCHAR(20)', 'default' => 'manual'],
            ['name' => 'enabled', 'type' => 'TINYINT(1)', 'default' => 1],
            ['name' => 'blockly_profile_key', 'type' => 'VARCHAR(50)', 'nullable' => true, 'default' => null],
            ['name' => 'evaluation_mode', 'type' => 'VARCHAR(20)', 'default' => 'normal'],
            ['name' => 'evaluation_mode_changed_by_user_id', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
            ['name' => 'evaluation_mode_changed_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'join_code_hash', 'type' => 'VARCHAR(255)', 'nullable' => true, 'default' => null],
            ['name' => 'owner_user_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'created_at', 'type' => 'DATETIME', 'default_expression' => 'CURRENT_TIMESTAMP'],
            ['name' => 'updated_at', 'type' => 'DATETIME', 'default_expression' => 'CURRENT_TIMESTAMP'],
            ['name' => 'deleted_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'deleted_by_user_id', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
        ],
        'indexes' => [
            ['name' => null, 'columns' => ['id'], 'unique' => true, 'primary' => true],
            ['name' => 'uq_learning_groups_slug', 'columns' => ['slug'], 'unique' => true],
            ['name' => 'ix_learning_groups_owner_state', 'columns' => ['owner_user_id', 'enabled', 'deleted_at']],
            ['name' => 'ix_learning_groups_evaluation', 'columns' => ['evaluation_mode', 'enabled']],
        ],
    ],
    [
        'name' => 'group_managers',
        'owner_module' => 'groups',
        'schema_version' => '1',
        'columns' => [
            ['name' => 'id', 'type' => 'BIGINT UNSIGNED', 'auto_increment' => true],
            ['name' => 'group_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'user_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'granted_by_user_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'granted_at', 'type' => 'DATETIME', 'default_expression' => 'CURRENT_TIMESTAMP'],
            ['name' => 'revoked_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
        ],
        'indexes' => [
            ['name' => null, 'columns' => ['id'], 'unique' => true, 'primary' => true],
            ['name' => 'uq_group_managers_user', 'columns' => ['group_id', 'user_id'], 'unique' => true],
            ['name' => 'ix_group_managers_scope', 'columns' => ['user_id', 'revoked_at']],
        ],
    ],
    [
        'name' => 'group_memberships',
        'owner_module' => 'groups',
        'schema_version' => '1',
        'columns' => [
            ['name' => 'id', 'type' => 'BIGINT UNSIGNED', 'auto_increment' => true],
            ['name' => 'group_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'user_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'status', 'type' => 'VARCHAR(20)', 'default' => 'pending'],
            ['name' => 'requested_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'approved_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'approved_by_user_id', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
            ['name' => 'rejected_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'rejected_by_user_id', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
            ['name' => 'left_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'removed_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'removed_by_user_id', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
        ],
        'indexes' => [
            ['name' => null, 'columns' => ['id'], 'unique' => true, 'primary' => true],
            ['name' => 'uq_group_memberships_user', 'columns' => ['group_id', 'user_id'], 'unique' => true],
            ['name' => 'ix_group_memberships_user_state', 'columns' => ['user_id', 'status']],
            ['name' => 'ix_group_memberships_group_state', 'columns' => ['group_id', 'status']],
        ],
    ],
];
