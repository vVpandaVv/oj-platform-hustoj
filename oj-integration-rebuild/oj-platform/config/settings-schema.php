<?php

declare(strict_types=1);

return [[
    'name' => 'ai_usage_logs', 'owner_module' => 'settings', 'schema_version' => '1',
    'columns' => [
        ['name' => 'id', 'type' => 'BIGINT UNSIGNED', 'auto_increment' => true],
        ['name' => 'request_id', 'type' => 'CHAR(32)'],
        ['name' => 'occurred_at', 'type' => 'DATETIME'],
        ['name' => 'profile_key', 'type' => 'VARCHAR(100)'],
        ['name' => 'provider_key', 'type' => 'VARCHAR(100)'],
        ['name' => 'model_key', 'type' => 'VARCHAR(191)'],
        ['name' => 'task_type', 'type' => 'VARCHAR(191)'],
        ['name' => 'addon_id', 'type' => 'VARCHAR(100)'],
        ['name' => 'purpose', 'type' => 'VARCHAR(20)'],
        ['name' => 'outcome', 'type' => 'VARCHAR(20)'],
        ['name' => 'error_code', 'type' => 'VARCHAR(100)'],
        ['name' => 'http_status', 'type' => 'SMALLINT UNSIGNED', 'nullable' => true, 'default' => null],
        ['name' => 'duration_ms', 'type' => 'BIGINT UNSIGNED'],
        ['name' => 'input_tokens', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
        ['name' => 'output_tokens', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
        ['name' => 'total_tokens', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
        ['name' => 'cached_input_tokens', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
        ['name' => 'reasoning_tokens', 'type' => 'BIGINT UNSIGNED', 'nullable' => true, 'default' => null],
        ['name' => 'measurement', 'type' => 'VARCHAR(20)'],
        ['name' => 'total_source', 'type' => 'VARCHAR(20)'],
    ],
    'indexes' => [
        ['name' => null, 'columns' => ['id'], 'unique' => true, 'primary' => true],
        ['name' => 'uq_ai_usage_request', 'columns' => ['request_id'], 'unique' => true],
        ['name' => 'ix_ai_usage_time', 'columns' => ['occurred_at', 'id']],
        ['name' => 'ix_ai_usage_profile', 'columns' => ['profile_key', 'occurred_at']],
        ['name' => 'ix_ai_usage_task', 'columns' => ['task_type', 'occurred_at']],
        ['name' => 'ix_ai_usage_purpose', 'columns' => ['purpose', 'occurred_at']],
        ['name' => 'ix_ai_usage_addon', 'columns' => ['addon_id', 'occurred_at']],
    ],
], [
    'name'=>'addon_ai_requests','owner_module'=>'settings','schema_version'=>'1',
    'columns'=>[
        ['name'=>'id','type'=>'BIGINT UNSIGNED','auto_increment'=>true],
        ['name'=>'request_hash','type'=>'CHAR(64)'],['name'=>'addon_id','type'=>'VARCHAR(100)'],
        ['name'=>'actor_user_id','type'=>'BIGINT UNSIGNED'],['name'=>'input_hash','type'=>'CHAR(64)'],
        ['name'=>'status','type'=>'VARCHAR(20)'],['name'=>'lease_token','type'=>'VARCHAR(48)'],
        ['name'=>'lease_expires_at','type'=>'DATETIME'],['name'=>'result_json','type'=>'LONGTEXT'],
        ['name'=>'created_at','type'=>'DATETIME'],['name'=>'updated_at','type'=>'DATETIME'],
    ],
    'indexes'=>[
        ['name'=>null,'columns'=>['id'],'unique'=>true,'primary'=>true],
        ['name'=>'uq_addon_ai_request','columns'=>['request_hash'],'unique'=>true],
        ['name'=>'ix_addon_ai_actor','columns'=>['actor_user_id','created_at']],
        ['name'=>'ix_addon_ai_status','columns'=>['status','updated_at']],
    ],
]];
