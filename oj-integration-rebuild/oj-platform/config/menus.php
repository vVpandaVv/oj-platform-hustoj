<?php

declare(strict_types=1);

$menus = [
    // D-010: 교사가 자주 쓰는 순서로 위에 두고, 관리자 영역(section 'admin')은 구분선 아래에 둔다.
    // 같은 일을 하는 화면은 config/console-local-nav.php에서 사이드바 한 항목으로 묶는다.
    ['dashboard', '홈', null, 'console.dashboard', 'admin_console', ['oj_access_console'], 10],
    ['classes', '수업', null, 'first_accessible_child', null, [], 20],
    ['problems', '문제', null, 'first_accessible_child', null, [], 30],
    ['submissions', '제출', null, 'first_accessible_child', null, [], 40],
    ['learning_support', '학습 활동', null, 'first_accessible_child', null, [], 50],
    ['community', '커뮤니티', null, 'console.community.index', null, [], 60],
    ['users', '사용자', null, 'first_accessible_child', null, [], 70, null, [], 'admin'],
    ['operations', '운영', null, 'console.settings.index', null, [], 80, null, [], 'admin'],

    ['classes.index', '수업', 'classes', 'console.classes.index', 'classes', ['oj_manage_classes'], 10, null, [], '수업 운영'],
    ['classes.deleted', '보관된 수업', 'classes', 'console.classes.deleted', 'classes', ['oj_manage_classes'], 12, null, [], '수업 운영'],
    ['classes.new', '수업 추가', 'classes', 'console.classes.new', 'classes', ['oj_create_classes'], 14, null, [], '수업 운영'],
    ['classes.groups', '그룹', 'classes', 'console.groups.index', 'groups', ['oj_manage_groups', 'oj_create_groups', 'oj_manage_own_groups'], 20, null, [], '수업 운영'],
    ['classes.groups_deleted', '보관된 그룹', 'classes', 'console.groups.deleted', 'groups', ['oj_manage_groups'], 22, null, [], '수업 운영'],
    ['classes.groups_new', '그룹 추가', 'classes', 'console.groups.new', 'groups', ['oj_create_groups'], 24, null, [], '수업 운영'],
    ['contests.index', '대회·평가', 'classes', 'console.contests.index', 'contests', ['oj_manage_contests'], 30, null, [], '수업 운영'],
    ['contests.deleted', '보관된 대회', 'classes', 'console.contests.deleted', 'contests', ['oj_manage_contests'], 32, null, [], '수업 운영'],
    ['contests.deleted_submissions', '보관 대회 제출', 'classes', 'console.contests.deleted_submissions', 'contests', ['oj_manage_contests'], 33, null, [], '수업 운영'],
    ['contests.new', '대회 추가', 'classes', 'console.contests.new', 'contests', ['oj_create_contests'], 34, null, [], '수업 운영'],

    ['problems.index', '문제', 'problems', 'console.problems.index', 'problems', ['oj_manage_problems'], 10],
    ['problems.new', '문제 추가', 'problems', 'console.problems.new', 'problems', ['oj_create_problems'], 12],
    ['problems.quiz', '퀴즈', 'problems', 'console.quizzes.index', 'quizzes', ['oj_manage_quizzes'], 20],
    ['problems.quiz_bank', '문항 은행', 'problems', 'console.quiz-bank.index', 'quizzes', ['oj_manage_quizzes'], 30],
    ['problems.books', '문제집', 'problems', 'console.problem_books.index', 'problem_books', ['oj_manage_problem_books'], 40],
    ['problems.xml', '문제 도구', 'problems', 'console.problems.xml', 'problems', ['oj_manage_problem_xml'], 50],
    ['problems.change_id', '문제 번호 변경', 'problems', 'console.problems.change_id', 'problems', ['oj_change_problem_ids'], 52],
    ['problems.topics', '주제 사전', 'problems', 'console.problems.topics', 'problems', [], 60, 'super_admin_only'],

    ['submissions.index', '제출', 'submissions', 'console.submissions.index', 'submissions', ['oj_manage_submissions', 'oj_view_submission_detail', 'oj_rejudge_submissions'], 10],
    ['submissions.ai_feedback', 'AI 피드백', 'submissions', 'console.submissions.ai_feedback', 'ai_feedback', ['oj_manage_ai_feedback'], 20],
    ['submissions.ai_feedback.records', 'AI 피드백 기록', 'submissions', 'console.submissions.ai_feedback.records', 'ai_feedback', ['oj_manage_ai_feedback'], 21],
    ['submissions.ai_guides', 'AI 답변 지침', 'submissions', 'console.submissions.ai_guides', 'ai_feedback', ['oj_manage_ai_feedback'], 22],
    ['submissions.learner_levels', '학습 수준 분석', 'submissions', 'console.submissions.learner_levels', 'ai_feedback', ['oj_manage_ai_feedback'], 24],
    ['submissions.events', '처리 기록', 'submissions', 'console.submissions.events', 'submissions', ['oj_manage_submissions'], 30],
    ['submissions.deleted', '제출 삭제 기록', 'submissions', 'console.submissions.deleted', 'submissions', ['oj_manage_submissions'], 32],
    ['submissions.settings', '제출 설정', 'submissions', 'console.submissions.settings', 'submissions', ['oj_manage_submission_settings'], 40],
    ['submissions.blockly', '블록 코딩', 'submissions', 'console.submissions.block_coding', 'blockly', ['oj_manage_submission_settings'], 42],

    ['community.board', '게시판', 'community', 'console.board.index', 'board', ['oj_manage_board'], 10],
    ['community.board_trash', '삭제된 게시글', 'community', 'console.board.trash', 'board', ['oj_manage_board'], 12],
    ['community.board_new', '게시글 작성', 'community', 'console.board.new', 'board', ['oj_manage_board'], 14],
    ['community.notices', '공지사항', 'community', 'console.notices.index', 'notices', ['oj_manage_notices'], 20],
    ['community.notice_trash', '삭제된 공지', 'community', 'console.notices.trash', 'notices', ['oj_manage_notices'], 22],
    ['community.notice_new', '공지 작성', 'community', 'console.notices.new', 'notices', ['oj_manage_notices'], 24],
    ['messaging.inbox', '쪽지', 'community', 'console.messaging.index', 'messaging', ['oj_manage_messaging'], 30],
    ['messaging.reports', '신고 내역', 'community', 'console.messaging.reports', 'messaging', ['oj_manage_messaging'], 32],
    ['community.settings', '커뮤니티 설정', 'community', 'console.community.settings', 'admin_console', ['oj_manage_settings'], 40, null, ['board', 'notices']],
    ['messaging.settings', '쪽지 설정', 'community', 'console.messaging.settings', 'admin_console', ['oj_manage_settings'], 42, null, ['messaging']],

    ['users.index', '사용자', 'users', 'console.users.index', 'auth', ['oj_manage_users', 'oj_approve_user_registrations'], 10],
    ['users.deleted', '삭제된 사용자', 'users', 'console.users.deleted', 'auth', ['oj_view_deleted_users'], 12],
    ['users.new', '사용자 추가', 'users', 'console.users.new', 'auth', ['oj_create_users'], 14],
    ['users.bulk_import', '사용자 일괄 등록', 'users', 'console.users.bulk_import', 'auth', ['oj_create_users'], 16],
    ['users.roles', '역할과 권한', 'users', 'console.roles.index', 'permission', ['oj_manage_roles'], 20],
    ['users.registration', '가입과 약관', 'users', 'console.users.registration', 'registration', ['oj_manage_registration_settings'], 30],
    ['users.agreements', '약관', 'users', 'console.agreements.index', 'registration', ['oj_manage_agreements'], 32],

    ['operations.site', '사이트', 'operations', 'console.settings.site', 'settings', ['oj_manage_settings'], 10, null, [], '일반'],
    ['operations.home', '홈 화면', 'operations', 'console.settings.home', 'home', ['oj_manage_settings'], 12, null, [], '일반'],
    ['operations.menu', '학생 메뉴', 'operations', 'console.settings.menu', 'settings', ['oj_manage_settings'], 14, null, [], '일반'],
    ['operations.features', '기능', 'operations', 'console.settings.features', 'settings', ['oj_manage_settings'], 20, null, [], '일반'],
    ['operations.database', '채점 환경', 'operations', 'console.settings.database', 'judge_db', ['oj_manage_settings'], 30, null, [], '연결'],
    ['operations.test_data', '테스트 데이터', 'operations', 'console.problems.test_data_settings', 'problems', ['oj_manage_system'], 32, null, [], '연결'],
    ['operations.smtp', '메일', 'operations', 'console.settings.smtp', 'settings', ['oj_manage_smtp'], 40, null, [], '연결'],
    ['operations.ai_provider', 'AI', 'operations', 'console.settings.ai_provider', 'settings', ['oj_manage_settings'], 50, null, [], '연결'],
    ['operations.ai_routing', '작업별 AI 배정', 'operations', 'console.settings.ai_routing', 'settings', ['oj_manage_settings'], 52, null, [], '연결'],
    ['operations.ai_usage', 'AI 사용량', 'operations', 'console.settings.ai_usage', 'settings', ['oj_manage_settings'], 54, null, [], '연결'],
    ['operations.status', '시스템 상태', 'operations', 'console.system_status', 'settings', ['oj_view_system_status', 'oj_manage_settings', 'oj_manage_system'], 60, null, [], '시스템'],
    ['operations.system', '시스템 설정', 'operations', 'console.settings.system', 'settings', ['oj_manage_system'], 62, null, [], '시스템'],
    ['operations.migration', '데이터 이전', 'operations', 'console.settings.migration', 'migration', ['oj_manage_migration'], 64, null, [], '시스템'],
];

return array_map(
    static fn (array $item): array => [
        'key' => $item[0],
        'label' => $item[1],
        'parent' => $item[2],
        'route' => $item[3],
        'feature_key' => $item[4],
        'capabilities' => $item[5],
        'order' => $item[6],
        'policy' => $item[7] ?? null,
        'any_feature_keys' => $item[8] ?? [],
        'section' => $item[9] ?? null,
    ],
    $menus
);
