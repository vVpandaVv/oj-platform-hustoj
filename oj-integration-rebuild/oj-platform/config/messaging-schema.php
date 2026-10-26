<?php

declare(strict_types=1);

$primaryId = [
    ['name' => null, 'columns' => ['id'], 'unique' => true, 'primary' => true],
];

return [
    [
        'name' => 'messages',
        'owner_module' => 'messaging',
        'schema_version' => '2',
        'columns' => [
            ['name' => 'id', 'type' => 'BIGINT UNSIGNED', 'auto_increment' => true],
            ['name' => 'thread_key', 'type' => 'VARCHAR(40)'],
            ['name' => 'sender_user_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'recipient_user_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'body', 'type' => 'TEXT'],
            ['name' => 'sent_at', 'type' => 'DATETIME', 'default_expression' => 'CURRENT_TIMESTAMP'],
            ['name' => 'read_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'sender_deleted_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'recipient_deleted_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'hidden_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'hidden_by_user_id', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
            ['name' => 'hidden_reason', 'type' => 'VARCHAR(255)', 'nullable' => true, 'default' => null],
        ],
        'indexes' => array_merge($primaryId, [
            ['name' => 'ix_messages_thread_key', 'columns' => ['thread_key', 'sent_at']],
            ['name' => 'ix_messages_recipient', 'columns' => ['recipient_user_id', 'sent_at']],
            ['name' => 'ix_messages_unread', 'columns' => ['recipient_user_id', 'read_at', 'recipient_deleted_at', 'hidden_at', 'thread_key']],
            ['name' => 'ix_messages_sender', 'columns' => ['sender_user_id', 'sent_at']],
        ]),
    ],
    [
        'name' => 'message_reports',
        'owner_module' => 'messaging',
        'schema_version' => '1',
        'columns' => [
            ['name' => 'id', 'type' => 'BIGINT UNSIGNED', 'auto_increment' => true],
            ['name' => 'message_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'reporter_user_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'reason', 'type' => 'TEXT'],
            ['name' => 'status', 'type' => 'VARCHAR(20)', 'default' => 'pending'],
            ['name' => 'reported_at', 'type' => 'DATETIME', 'default_expression' => 'CURRENT_TIMESTAMP'],
            ['name' => 'resolved_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'resolved_by_user_id', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
            ['name' => 'resolution_action', 'type' => 'VARCHAR(30)', 'nullable' => true, 'default' => null],
            ['name' => 'resolution_note', 'type' => 'TEXT', 'nullable' => true, 'default' => null],
        ],
        'indexes' => array_merge($primaryId, [
            ['name' => 'uq_message_reports_reporter', 'columns' => ['message_id', 'reporter_user_id'], 'unique' => true],
            ['name' => 'ix_message_reports_status', 'columns' => ['status', 'reported_at']],
        ]),
    ],
];
