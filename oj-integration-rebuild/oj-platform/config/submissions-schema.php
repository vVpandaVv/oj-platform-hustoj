<?php

declare(strict_types=1);

return [
    [
        'name' => 'submission_contexts',
        'owner_module' => 'submissions',
        'schema_version' => '1',
        'columns' => [
            ['name' => 'submission_id', 'type' => 'INT UNSIGNED'],
            ['name' => 'class_id', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
            ['name' => 'group_id', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
            ['name' => 'created_at', 'type' => 'DATETIME', 'default_expression' => 'CURRENT_TIMESTAMP'],
            ['name' => 'updated_at', 'type' => 'DATETIME', 'default_expression' => 'CURRENT_TIMESTAMP'],
        ],
        'indexes' => [
            ['name' => null, 'columns' => ['submission_id'], 'unique' => true, 'primary' => true],
            ['name' => 'ix_submission_contexts_class', 'columns' => ['class_id', 'submission_id']],
            ['name' => 'ix_submission_contexts_group', 'columns' => ['group_id', 'submission_id']],
        ],
    ],
    [
        'name' => 'submission_language_settings_log',
        'owner_module' => 'submissions',
        'schema_version' => '1',
        'columns' => [
            [
                'name' => 'id',
                'type' => 'BIGINT UNSIGNED',
                'auto_increment' => true,
            ],
            ['name' => 'language_mask', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'created_by_user_id', 'type' => 'BIGINT UNSIGNED'],
            [
                'name' => 'created_at',
                'type' => 'DATETIME',
                'default_expression' => 'CURRENT_TIMESTAMP',
            ],
        ],
        'indexes' => [
            [
                'name' => null,
                'columns' => ['id'],
                'unique' => true,
                'primary' => true,
            ],
            [
                'name' => 'ix_submission_language_settings_actor',
                'columns' => ['created_by_user_id', 'created_at'],
            ],
        ],
    ],
    [
        'name' => 'submission_events',
        'owner_module' => 'submissions',
        'schema_version' => '1',
        'columns' => [
            [
                'name' => 'id',
                'type' => 'BIGINT UNSIGNED',
                'auto_increment' => true,
            ],
            [
                'name' => 'submission_id',
                'type' => 'INT UNSIGNED',
                'nullable' => true,
                'default' => null,
            ],
            ['name' => 'event_type', 'type' => 'VARCHAR(100)'],
            ['name' => 'actor_user_id', 'type' => 'BIGINT UNSIGNED'],
            [
                'name' => 'request_key',
                'type' => 'CHAR(64)',
                'nullable' => true,
                'default' => null,
            ],
            ['name' => 'message', 'type' => 'VARCHAR(500)', 'default' => ''],
            ['name' => 'metadata', 'type' => 'LONGTEXT'],
            [
                'name' => 'created_at',
                'type' => 'DATETIME',
                'default_expression' => 'CURRENT_TIMESTAMP',
            ],
        ],
        'indexes' => [
            [
                'name' => null,
                'columns' => ['id'],
                'unique' => true,
                'primary' => true,
            ],
            [
                'name' => 'uq_submission_events_request',
                'columns' => ['request_key'],
                'unique' => true,
            ],
            [
                'name' => 'ix_submission_events_submission',
                'columns' => ['submission_id', 'created_at'],
            ],
            [
                'name' => 'ix_submission_events_type',
                'columns' => ['event_type', 'created_at'],
            ],
            [
                'name' => 'ix_submission_events_actor',
                'columns' => ['actor_user_id', 'created_at'],
            ],
        ],
    ],
];
