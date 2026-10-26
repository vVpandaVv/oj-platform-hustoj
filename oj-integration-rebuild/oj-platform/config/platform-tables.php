<?php

declare(strict_types=1);

$groups = [
    'settings' => ['ai_usage_logs', 'addon_ai_requests'],
    'auth' => [
        'user_profiles',
        'user_preferences',
        'agreement_document_types',
        'agreement_documents',
        'guardian_verifications',
        'user_agreement_log',
        'user_login_log',
        'deleted_users',
    ],
    'submissions' => [
        'submission_contexts',
        'submission_language_settings_log',
        'submission_events',
    ],
    'groups' => [
        'learning_groups',
        'group_managers',
        'group_memberships',
    ],
    'classes' => [
        'classes',
        'class_instructors',
        'class_enrollments',
        'class_group_links',
        'class_problem_links',
    ],
    'contests' => [
        'contest_settings',
        'contest_problem_settings',
        'contest_managers',
        'contest_participants',
        'contest_ip_logs',
        'deleted_contests',
    ],
    'ai_feedback' => [
        'submission_ai_feedback',
        'learner_level_profiles',
    ],
    'problems' => [
        'problem_ownerships',
        'problem_collaborators',
        'problem_metadata',
        'problem_topic_links',
        'quiz_question_bank',
        'quiz_question_bank_choices',
        'quiz_revisions',
        'quiz_questions',
        'quiz_choices',
        'quiz_attempt_summaries',
        'quiz_attempt_items',
        'quiz_attempt_item_choices',
        'problem_book_categories',
        'problem_book_category_items',
        'personal_problem_book_items',
    ],
    'jobs' => [
        'background_jobs',
        'background_job_items',
        'background_job_events',
        'background_job_steps',
    ],
    'messaging' => [
        'messages',
        'message_reports',
    ],
];

$waves = [
    'settings' => 10,
    'jobs' => 0,
    'auth' => 1,
    'problems' => 2,
    'submissions' => 3,
    'classes' => 4,
    'contests' => 5,
    'groups' => 6,
    'ai_feedback' => 7,
    'messaging' => 8,
];

$tables = [];
foreach ($groups as $owner => $names) {
    foreach ($names as $name) {
        $tables[] = [
            'name' => $name,
            'owner_module' => $owner,
            'wave' => $waves[$owner],
            'engine' => 'InnoDB',
            'charset' => 'utf8mb4',
        ];
    }
}

return $tables;
