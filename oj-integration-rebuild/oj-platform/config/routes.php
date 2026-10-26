<?php

declare(strict_types=1);

$paths = [
    'home' => '/',
    'learning_spaces.index' => '/learning-spaces',
    'auth.login' => '/login',
    'auth.register' => '/register',
    'auth.profile' => '/users/{user_id}',
    'auth.settings' => '/user-settings',
    'auth.agreements' => '/user-agreements',
    'auth.guardian_consent' => '/guardian-consent/{token}',
    'legal.terms' => '/terms',
    'legal.privacy' => '/privacy',
    'legal.copyright' => '/copyright',
    'legal.email_collection_refusal' => '/email-collection-refusal',
    'legal.guardian_policy' => '/guardian-consent-policy',
    'legal.open_source' => '/open-source-licenses',
    'problems.index' => '/problems',
    'problems.show' => '/problems/{problem_id}',
    'problems.statistics' => '/problems/{problem_id}/statistics',
    'problems.ranking' => '/problems/{problem_id}/ranking',
    'quizzes.index' => '/quizzes',
    'quizzes.show' => '/quizzes/{problem_id}',
    'quizzes.attempt' => '/quizzes/{problem_id}/attempt',
    'quizzes.result' => '/quizzes/{problem_id}/attempts/{attempt_id}',
    'quizzes.statistics' => '/quizzes/{problem_id}/statistics',
    'messaging.inbox' => '/messages',
    'messaging.sent' => '/messages/sent',
    'messaging.thread' => '/messages/thread/{thread_key}',
    'messaging.compose' => '/messages/new/{user_id}',
    'messaging.find' => '/messages/find',
    'problem_books.index' => '/problem-books',
    'submissions.form' => '/submit',
    'submissions.form.problem' => '/submit/{problem_id}',
    'submissions.index' => '/submissions',
    'submissions.detail' => '/submissions/{submission_id}',
    'submissions.problem' => '/problems/{problem_id}/submissions',
    'ranking.index' => '/ranking',
    'learning_dashboard.index' => '/dashboard',
    'contests.index' => '/contests',
    'contests.show' => '/contests/{contest_id}',
    'contests.submissions' => '/contests/{contest_id}/submissions',
    'contests.ranking' => '/contests/{contest_id}/ranking',
    'contests.statistics' => '/contests/{contest_id}/statistics',
    'contests.ip_check' => '/contests/{contest_id}/ip-check',
    'contests.ip_change' => '/contests/{contest_id}/ip-change',
    'classes.index' => '/classes',
    'classes.show' => '/classes/{class_id}',
    'classes.submissions' => '/classes/{class_id}/submissions',
    'classes.progress' => '/classes/{class_id}/progress',
    'groups.index' => '/groups',
    'groups.show' => '/groups/{group_id}',
    'groups.members' => '/groups/{group_id}/members',
    'groups.submissions' => '/groups/{group_id}/submissions',
    'groups.ranking' => '/groups/{group_id}/ranking',
    'board.index' => '/board',
    'board.new' => '/board/new',
    'board.thread' => '/board/thread/{board_thread_id}',
    'board.edit' => '/board/thread/{board_thread_id}/edit',
    'notices.index' => '/notices',
    'notices.show' => '/notices/{notice_id}',
    'notices.new' => '/notices/new',
    'console.dashboard' => '/oj-console',
    'console.system_status' => '/oj-console/system-status',
    'console.users.index' => '/oj-console/users',
    'console.users.new' => '/oj-console/users/new',
    'console.users.detail' => '/oj-console/users/{user_id}',
    'console.users.edit' => '/oj-console/users/{user_id}/edit',
    'console.users.roles' => '/oj-console/users/{user_id}/roles',
    'console.users.login_history' => '/oj-console/users/{user_id}/login-history',
    'console.users.bulk_import' => '/oj-console/users/bulk-import',
    'console.users.deleted' => '/oj-console/users/deleted',
    'console.roles.index' => '/oj-console/roles',
    'console.agreements.index' => '/oj-console/agreements',
    'console.users.registration' => '/oj-console/users/registration',
    'console.problems.index' => '/oj-console/problems',
    'console.problems.topics' => '/oj-console/problems/topics',
    'console.problems.new' => '/oj-console/problems/new',
    'console.problems.edit' => '/oj-console/problems/{problem_id}/edit',
    'console.problems.delete' => '/oj-console/problems/{problem_id}/delete',
    'console.problems.test_data' => '/oj-console/problems/{problem_id}/test-data',
    'console.problems.test_data_settings' => '/oj-console/settings/test-data',
    'console.problems.change_id' => '/oj-console/problems/change-id',
    'console.problems.xml' => '/oj-console/problems/xml',
    'console.quizzes.index' => '/oj-console/quizzes',
    'console.quizzes.new' => '/oj-console/quizzes/new',
    'console.quizzes.edit' => '/oj-console/quizzes/{problem_id}/edit',
    'console.quizzes.statistics' => '/oj-console/quizzes/{problem_id}/statistics',
    'console.quiz-bank.index' => '/oj-console/quiz-bank',
    'console.quiz-bank.new' => '/oj-console/quiz-bank/new',
    'console.quiz-bank.edit' => '/oj-console/quiz-bank/{bank_id}/edit',
    'console.problem_books.index' => '/oj-console/problem-books',
    'console.submissions.index' => '/oj-console/submissions',
    'console.submissions.detail' => '/oj-console/submissions/{submission_id}',
    'console.submissions.deleted' => '/oj-console/submissions/deleted',
    'console.submissions.events' => '/oj-console/submissions/events',
    'console.submissions.learner_levels' => '/oj-console/submissions/learner-levels',
    'console.submissions.settings' => '/oj-console/submissions/settings',
    'console.submissions.block_coding' => '/oj-console/submissions/block-coding',
    'console.submissions.ai_feedback' => '/oj-console/submissions/ai-feedback',
    'console.submissions.ai_feedback.records' => '/oj-console/submissions/ai-feedback/records',
    'console.submissions.ai_guides' => '/oj-console/submissions/ai-guides',
    'console.submissions.ai_guides.new' => '/oj-console/submissions/ai-guides/new',
    'console.submissions.ai_guides.edit' => '/oj-console/submissions/ai-guides/{prompt_file}',
    'console.submissions.ai_guides.preview' => '/oj-console/submissions/ai-guides/{prompt_file}/preview',
    'console.submissions.ai_guides.revisions' => '/oj-console/submissions/ai-guides/{prompt_file}/revisions',
    'console.contests.index' => '/oj-console/contests',
    'console.contests.deleted' => '/oj-console/contests/deleted',
    'console.contests.new' => '/oj-console/contests/new',
    'console.contests.edit' => '/oj-console/contests/{contest_id}/edit',
    'console.contests.delete' => '/oj-console/contests/{contest_id}/delete',
    'console.contests.submissions' => '/oj-console/contests/{contest_id}/submissions',
    'console.contests.ranking' => '/oj-console/contests/{contest_id}/ranking',
    'console.contests.statistics' => '/oj-console/contests/{contest_id}/statistics',
    'console.contests.ip_check' => '/oj-console/contests/{contest_id}/ip-check',
    'console.contests.deleted_submissions' => '/oj-console/contests/deleted-submissions',
    'console.classes.index' => '/oj-console/classes',
    'console.classes.new' => '/oj-console/classes/new',
    'console.classes.edit' => '/oj-console/classes/{class_id}/edit',
    'console.classes.participants' => '/oj-console/classes/{class_id}/participants',
    'console.classes.submissions' => '/oj-console/classes/{class_id}/submissions',
    'console.classes.progress' => '/oj-console/classes/{class_id}/progress',
    'console.classes.delete' => '/oj-console/classes/{class_id}/delete',
    'console.classes.deleted' => '/oj-console/classes/deleted',
    'console.classes.deleted_submissions' => '/oj-console/classes/deleted/{class_id}/submissions',
    'console.classes.deleted_progress' => '/oj-console/classes/deleted/{class_id}/progress',
    'console.groups.index' => '/oj-console/groups',
    'console.groups.new' => '/oj-console/groups/new',
    'console.groups.edit' => '/oj-console/groups/{group_id}/edit',
    'console.groups.members' => '/oj-console/groups/{group_id}/members',
    'console.groups.approvals' => '/oj-console/groups/{group_id}/approvals',
    'console.groups.managers' => '/oj-console/groups/{group_id}/managers',
    'console.groups.bulk_members' => '/oj-console/groups/{group_id}/bulk-members',
    'console.groups.delete' => '/oj-console/groups/{group_id}/delete',
    'console.groups.submissions' => '/oj-console/groups/{group_id}/submissions',
    'console.groups.ranking' => '/oj-console/groups/{group_id}/ranking',
    'console.groups.deleted' => '/oj-console/groups/deleted',
    'console.groups.deleted_submissions' => '/oj-console/groups/deleted/{group_id}/submissions',
    'console.board.index' => '/oj-console/board',
    'console.board.new' => '/oj-console/board/new',
    'console.board.thread' => '/oj-console/board/thread/{board_thread_id}',
    'console.board.edit' => '/oj-console/board/thread/{board_thread_id}/edit',
    'console.board.comments' => '/oj-console/board/thread/{board_thread_id}/comments',
    'console.board.trash_thread' => '/oj-console/board/thread/{board_thread_id}/trash',
    'console.board.trash' => '/oj-console/board/trash',
    'console.notices.index' => '/oj-console/notices',
    'console.notices.new' => '/oj-console/notices/new',
    'console.notices.edit' => '/oj-console/notices/{notice_id}/edit',
    'console.notices.comments' => '/oj-console/notices/{notice_id}/comments',
    'console.notices.trash_notice' => '/oj-console/notices/{notice_id}/trash',
    'console.notices.trash' => '/oj-console/notices/trash',
    'console.community.settings' => '/oj-console/community/settings',
    'console.settings.index' => '/oj-console/settings',
    'console.settings.features' => '/oj-console/settings/features',
    'console.settings.site' => '/oj-console/settings/site',
    'console.settings.menu' => '/oj-console/settings/menu',
    'console.settings.home' => '/oj-console/settings/home',
    'console.settings.database' => '/oj-console/settings/database',
    'console.settings.smtp' => '/oj-console/settings/smtp',
    'console.settings.ai_provider' => '/oj-console/settings/ai-provider',
    'console.settings.ai_routing' => '/oj-console/settings/ai-routing',
    'console.settings.ai_usage' => '/oj-console/settings/ai-usage',
    'console.settings.system' => '/oj-console/settings/system',
    'console.settings.migration' => '/oj-console/settings/migration',
    'console.learning.index' => '/oj-console/learning',
    'console.community.index' => '/oj-console/community',
    'console.messaging.index' => '/oj-console/messages',
    'console.messaging.thread' => '/oj-console/messages/thread/{thread_key}',
    'console.messaging.reports' => '/oj-console/messages/reports',
    'console.messaging.settings' => '/oj-console/messages/settings',
    'console.jobs.show' => '/oj-console/jobs/{job_id}',
    'console.jobs.export' => '/oj-console/jobs/{job_id}/export',
    'community.attachments.download' => '/community/attachments/{attachment_id}',
];

$anonymousRoutes = [
    'auth.login',
    'auth.register',
    'auth.guardian_consent',
    'legal.terms',
    'legal.privacy',
    'legal.copyright',
    'legal.email_collection_refusal',
    'legal.guardian_policy',
    'legal.open_source',
];

$responseRoutes = [
    'console.jobs.export',
    'community.attachments.download',
];

$ownerFor = static function (string $name): string {
    if (in_array($name, ['home', 'learning_spaces.index'], true)) {
        return 'public_ui';
    }

    if (str_starts_with($name, 'auth.') || str_starts_with($name, 'legal.')) {
        return 'auth';
    }

    if (str_starts_with($name, 'quizzes.') || str_starts_with($name, 'problem_books.')) {
        return 'problems';
    }

    if (
        str_contains($name, '.ai_feedback')
        || str_contains($name, '.ai_guides')
        || str_contains($name, '.learner_levels')
    ) {
        return 'ai_feedback';
    }

    if (str_contains($name, '.block_coding')) {
        return 'blockly';
    }

    if (str_starts_with($name, 'learning_dashboard.')) {
        return 'learning_dashboard';
    }

    if (str_starts_with($name, 'ranking.')) {
        return 'ranking';
    }

    if (str_starts_with($name, 'notices.') || $name === 'community.attachments.download') {
        return 'board';
    }

    if (! str_starts_with($name, 'console.')) {
        return explode('.', $name, 2)[0];
    }

    if (in_array($name, ['console.dashboard', 'console.learning.index', 'console.community.index'], true)) {
        return 'admin_console';
    }

    if ($name === 'console.system_status' || str_starts_with($name, 'console.settings.')) {
        return $name === 'console.settings.migration' ? 'migration' : 'settings';
    }

    if (str_starts_with($name, 'console.roles.')) {
        return 'permission';
    }

    if (str_starts_with($name, 'console.agreements.') || str_starts_with($name, 'console.users.')) {
        return 'auth';
    }

    if (
        str_starts_with($name, 'console.quizzes.')
        || str_starts_with($name, 'console.quiz-bank.')
        || str_starts_with($name, 'console.problem_books.')
    ) {
        return 'problems';
    }

    if (str_starts_with($name, 'console.notices.') || str_starts_with($name, 'console.community.')) {
        return 'board';
    }

    return explode('.', $name, 3)[1];
};

$featureFor = static function (string $name, string $owner): string {
    if (str_starts_with($name, 'legal.')) {
        return 'auth';
    }
    if ($name === 'community.attachments.download') {
        return 'auth';
    }

    if ($name === 'console.community.settings') {
        return 'admin_console';
    }

    if ($name === 'console.messaging.settings') {
        return 'admin_console';
    }

    if (str_starts_with($name, 'legal.')) {
        return 'legal_documents';
    }

    if (
        str_starts_with($name, 'quizzes.')
        || str_starts_with($name, 'console.quizzes.')
        || str_starts_with($name, 'console.quiz-bank.')
    ) {
        return 'quizzes';
    }

    if (str_starts_with($name, 'problem_books.') || str_starts_with($name, 'console.problem_books.')) {
        return 'problem_books';
    }

    if (str_contains($name, '.ai_feedback') || str_contains($name, '.ai_guides') || str_contains($name, '.learner_levels')) {
        return 'ai_feedback';
    }

    if (str_contains($name, '.block_coding')) {
        return 'blockly';
    }

    if ($owner === 'admin_console' || $owner === 'jobs') {
        return 'admin_console';
    }

    return match ($owner) {
        'auth' => str_contains($name, 'register') || str_contains($name, 'agreement') || str_contains($name, 'guardian')
            ? 'registration'
            : 'auth',
        'board' => str_contains($name, 'notice') ? 'notices' : 'board',
        'settings', 'migration' => $owner,
        default => $owner,
    };
};

$routes = [];
foreach ($paths as $name => $path) {
    $owner = $ownerFor($name);
    $isResponse = in_array($name, $responseRoutes, true);
    $routes[] = [
        'name' => $name,
        'owner_module' => $owner,
        'feature_key' => $featureFor($name, $owner),
        'path' => $path,
        'area' => $isResponse ? 'response' : (str_starts_with($name, 'console.') ? 'console' : 'public'),
        'methods' => ['GET', 'HEAD'],
        'authentication' => in_array($name, $anonymousRoutes, true) ? 'anonymous' : 'authenticated',
        'response_type' => $isResponse ? 'file' : 'html',
    ];
}

return $routes;
