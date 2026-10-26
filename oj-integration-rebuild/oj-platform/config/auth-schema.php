<?php

declare(strict_types=1);

$primaryId = [
    ['name' => null, 'columns' => ['id'], 'unique' => true, 'primary' => true],
];

$timestamps = [
    ['name' => 'created_at', 'type' => 'DATETIME', 'default_expression' => 'CURRENT_TIMESTAMP'],
    ['name' => 'updated_at', 'type' => 'DATETIME', 'default_expression' => 'CURRENT_TIMESTAMP'],
];

return [
    [
        'name' => 'user_profiles',
        'owner_module' => 'auth',
        'schema_version' => '2',
        'columns' => array_merge([
            ['name' => 'user_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'affiliation', 'type' => 'VARCHAR(191)', 'nullable' => true, 'default' => null],
            ['name' => 'bio', 'type' => 'TEXT', 'nullable' => true, 'default' => null],
            ['name' => 'birth_date', 'type' => 'DATE', 'nullable' => true, 'default' => null],
            ['name' => 'access_status', 'type' => 'VARCHAR(20)', 'default' => 'active'],
            ['name' => 'must_change_password', 'type' => 'TINYINT UNSIGNED', 'default' => 0],
            ['name' => 'access_status_changed_by_user_id', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
            ['name' => 'access_status_changed_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'last_login_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'registration_requirements', 'type' => 'LONGTEXT', 'nullable' => true, 'default' => null],
        ], $timestamps),
        'indexes' => [
            ['name' => null, 'columns' => ['user_id'], 'unique' => true, 'primary' => true],
            ['name' => 'ix_user_profiles_status', 'columns' => ['access_status']],
            ['name' => 'ix_user_profiles_status_actor', 'columns' => ['access_status_changed_by_user_id']],
        ],
    ],
    [
        'name' => 'user_preferences',
        'owner_module' => 'auth',
        'schema_version' => '4',
        'columns' => array_merge([
            ['name' => 'user_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'profile_visibility', 'type' => 'VARCHAR(20)', 'default' => 'members'],
            ['name' => 'ranking_visibility', 'type' => 'VARCHAR(20)', 'default' => 'members'],
            ['name' => 'activity_visibility', 'type' => 'VARCHAR(20)', 'default' => 'members'],
            ['name' => 'default_language', 'type' => 'INT UNSIGNED', 'nullable' => true, 'default' => null],
            ['name' => 'editor_theme', 'type' => 'VARCHAR(100)', 'default' => 'chrome'],
            ['name' => 'code_template', 'type' => 'LONGTEXT', 'nullable' => true, 'default' => null],
            ['name' => 'default_editor', 'type' => 'VARCHAR(20)', 'default' => 'code'],
            ['name' => 'editor_font_size', 'type' => 'TINYINT UNSIGNED', 'default' => 16],
            ['name' => 'inline_submission_panel', 'type' => 'TINYINT UNSIGNED', 'default' => 0],
            ['name' => 'first_login_agreed_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
        ], $timestamps),
        'indexes' => [
            ['name' => null, 'columns' => ['user_id'], 'unique' => true, 'primary' => true],
        ],
    ],
    [
        'name' => 'agreement_document_types',
        'owner_module' => 'auth',
        'schema_version' => '1',
        'columns' => array_merge([
            ['name' => 'id', 'type' => 'BIGINT UNSIGNED', 'auto_increment' => true],
            ['name' => 'slug', 'type' => 'VARCHAR(100)'],
            ['name' => 'label', 'type' => 'VARCHAR(191)'],
            ['name' => 'required', 'type' => 'TINYINT UNSIGNED', 'default' => 1],
            ['name' => 'enabled', 'type' => 'TINYINT UNSIGNED', 'default' => 1],
            ['name' => 'sort_order', 'type' => 'INT UNSIGNED', 'default' => 0],
        ], $timestamps),
        'indexes' => array_merge($primaryId, [
            ['name' => 'uq_agreement_types_slug', 'columns' => ['slug'], 'unique' => true],
            ['name' => 'ix_agreement_types_enabled', 'columns' => ['enabled', 'sort_order']],
        ]),
    ],
    [
        'name' => 'agreement_documents',
        'owner_module' => 'auth',
        'schema_version' => '1',
        'columns' => array_merge([
            ['name' => 'id', 'type' => 'BIGINT UNSIGNED', 'auto_increment' => true],
            ['name' => 'type_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'version', 'type' => 'VARCHAR(50)'],
            ['name' => 'title', 'type' => 'VARCHAR(191)'],
            ['name' => 'content', 'type' => 'LONGTEXT'],
            ['name' => 'required', 'type' => 'TINYINT UNSIGNED', 'default' => 1],
            ['name' => 'active', 'type' => 'TINYINT UNSIGNED', 'default' => 0],
            ['name' => 'published_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'created_by_user_id', 'type' => 'BIGINT UNSIGNED'],
        ], $timestamps),
        'indexes' => array_merge($primaryId, [
            ['name' => 'uq_agreement_documents_version', 'columns' => ['type_id', 'version'], 'unique' => true],
            ['name' => 'ix_agreement_documents_active', 'columns' => ['active', 'published_at']],
        ]),
        'foreign_keys' => [
            [
                'name' => 'fk_agreement_documents_type',
                'column' => 'type_id',
                'referenced_table' => 'agreement_document_types',
                'referenced_column' => 'id',
                'on_delete' => 'RESTRICT',
                'on_update' => 'RESTRICT',
            ],
        ],
    ],
    [
        'name' => 'guardian_verifications',
        'owner_module' => 'auth',
        'schema_version' => '2',
        'columns' => [
            ['name' => 'id', 'type' => 'BIGINT UNSIGNED', 'auto_increment' => true],
            ['name' => 'user_id', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
            ['name' => 'child_email', 'type' => 'VARCHAR(191)'],
            ['name' => 'guardian_name', 'type' => 'VARCHAR(191)'],
            ['name' => 'guardian_email', 'type' => 'VARCHAR(191)'],
            ['name' => 'document_id', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
            ['name' => 'document_version', 'type' => 'VARCHAR(50)', 'nullable' => true, 'default' => null],
            ['name' => 'document_sha256', 'type' => 'CHAR(64)', 'nullable' => true, 'default' => null],
            ['name' => 'guardian_relationship', 'type' => 'VARCHAR(30)', 'nullable' => true, 'default' => null],
            ['name' => 'verification_method', 'type' => 'VARCHAR(30)', 'nullable' => true, 'default' => null],
            ['name' => 'token_hash', 'type' => 'CHAR(64)'],
            ['name' => 'code_hash', 'type' => 'CHAR(64)'],
            ['name' => 'status', 'type' => 'VARCHAR(30)', 'default' => 'pending'],
            ['name' => 'expires_at', 'type' => 'DATETIME'],
            ['name' => 'verified_at', 'type' => 'DATETIME', 'nullable' => true, 'default' => null],
            ['name' => 'created_at', 'type' => 'DATETIME', 'default_expression' => 'CURRENT_TIMESTAMP'],
        ],
        'indexes' => array_merge($primaryId, [
            ['name' => 'uq_guardian_verifications_token', 'columns' => ['token_hash'], 'unique' => true],
            ['name' => 'ix_guardian_verifications_user', 'columns' => ['user_id']],
            ['name' => 'ix_guardian_verifications_child', 'columns' => ['child_email']],
            ['name' => 'ix_guardian_verifications_status', 'columns' => ['status', 'expires_at']],
        ]),
    ],
    [
        'name' => 'user_agreement_log',
        'owner_module' => 'auth',
        'schema_version' => '1',
        'columns' => [
            ['name' => 'id', 'type' => 'BIGINT UNSIGNED', 'auto_increment' => true],
            ['name' => 'user_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'document_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'document_version', 'type' => 'VARCHAR(50)'],
            ['name' => 'ip_address', 'type' => 'VARCHAR(45)', 'nullable' => true, 'default' => null],
            ['name' => 'user_agent', 'type' => 'TEXT', 'nullable' => true, 'default' => null],
            ['name' => 'agreed_at', 'type' => 'DATETIME', 'default_expression' => 'CURRENT_TIMESTAMP'],
        ],
        'indexes' => array_merge($primaryId, [
            ['name' => 'uq_user_agreement_version', 'columns' => ['user_id', 'document_id', 'document_version'], 'unique' => true],
            ['name' => 'ix_user_agreement_document', 'columns' => ['document_id', 'agreed_at']],
        ]),
        'foreign_keys' => [
            [
                'name' => 'fk_user_agreement_document',
                'column' => 'document_id',
                'referenced_table' => 'agreement_documents',
                'referenced_column' => 'id',
                'on_delete' => 'RESTRICT',
                'on_update' => 'RESTRICT',
            ],
        ],
    ],
    [
        'name' => 'user_login_log',
        'owner_module' => 'auth',
        'schema_version' => '1',
        'columns' => [
            ['name' => 'id', 'type' => 'BIGINT UNSIGNED', 'auto_increment' => true],
            ['name' => 'user_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'ip_address', 'type' => 'VARCHAR(45)', 'nullable' => true, 'default' => null],
            ['name' => 'user_agent', 'type' => 'TEXT', 'nullable' => true, 'default' => null],
            ['name' => 'logged_in_at', 'type' => 'DATETIME', 'default_expression' => 'CURRENT_TIMESTAMP'],
        ],
        'indexes' => array_merge($primaryId, [
            ['name' => 'ix_user_login_log_user', 'columns' => ['user_id', 'logged_in_at']],
        ]),
    ],
    [
        'name' => 'deleted_users',
        'owner_module' => 'auth',
        'schema_version' => '1',
        'columns' => [
            ['name' => 'id', 'type' => 'BIGINT UNSIGNED', 'auto_increment' => true],
            ['name' => 'user_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'snapshot', 'type' => 'LONGTEXT'],
            ['name' => 'deleted_by_user_id', 'type' => 'BIGINT UNSIGNED'],
            ['name' => 'deleted_at', 'type' => 'DATETIME', 'default_expression' => 'CURRENT_TIMESTAMP'],
            ['name' => 'reason', 'type' => 'TEXT', 'nullable' => true, 'default' => null],
        ],
        'indexes' => array_merge($primaryId, [
            ['name' => 'ix_deleted_users_user', 'columns' => ['user_id', 'deleted_at']],
        ]),
    ],
];
