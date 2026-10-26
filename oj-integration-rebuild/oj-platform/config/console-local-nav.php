<?php

declare(strict_types=1);

/*
 * D-010 메뉴 재구성: 같은 일을 하는 화면은 사이드바에 한 항목만 두고, 화면 안
 * 세그먼트(보기)와 버튼('action', 추가·글쓰기)으로 보여 준다. 각 화면은
 * config/menus.php 정의(기능·권한·주소)를 그대로 쓰므로 볼 수 있는 화면만 나온다.
 *
 * key는 사이드바 항목의 메뉴 키(보통 대표 화면), label은 사이드바 이름이다.
 * members: [메뉴 키, 세그먼트 이름, 'action'?]
 */
return [
    ['key' => 'users.index', 'label' => '사용자', 'members' => [
        ['users.index', '전체 사용자'],
        ['users.deleted', '삭제됨'],
        ['users.new', '사용자 추가', 'action'],
        ['users.bulk_import', '일괄 등록', 'action'],
    ]],
    ['key' => 'users.registration', 'label' => '가입과 약관', 'members' => [
        ['users.registration', '가입 설정'],
        ['users.agreements', '약관'],
    ]],

    ['key' => 'problems.index', 'label' => '문제', 'members' => [
        ['problems.index', '문제 목록'],
        ['problems.new', '문제 추가', 'action'],
    ]],
    ['key' => 'problems.xml', 'label' => '문제 도구', 'members' => [
        ['problems.xml', 'XML 가져오기·내보내기'],
        ['problems.change_id', '번호 변경'],
    ]],

    ['key' => 'submissions.ai_feedback', 'label' => 'AI 피드백', 'members' => [
        ['submissions.ai_feedback.records', '피드백 기록'],
        ['submissions.ai_feedback', '피드백 설정'],
        ['submissions.ai_guides', '답변 지침'],
        ['submissions.learner_levels', '학습 수준 분석'],
    ]],
    ['key' => 'submissions.events', 'label' => '처리 기록', 'members' => [
        ['submissions.events', '처리 기록'],
        ['submissions.deleted', '삭제 기록'],
    ]],
    ['key' => 'submissions.settings', 'label' => '제출 설정', 'members' => [
        ['submissions.settings', '일반'],
        ['submissions.blockly', '블록 코딩'],
    ]],

    ['key' => 'classes.index', 'label' => '수업', 'members' => [
        ['classes.index', '진행 중'],
        ['classes.deleted', '보관됨'],
        ['classes.new', '수업 추가', 'action'],
    ]],
    ['key' => 'classes.groups', 'label' => '그룹', 'members' => [
        ['classes.groups', '운영 중'],
        ['classes.groups_deleted', '보관됨'],
        ['classes.groups_new', '그룹 추가', 'action'],
    ]],
    ['key' => 'contests.index', 'label' => '대회·평가', 'members' => [
        ['contests.index', '대회 목록'],
        ['contests.deleted', '보관됨'],
        ['contests.deleted_submissions', '보관 대회 제출'],
        ['contests.new', '대회 추가', 'action'],
    ]],

    ['key' => 'community.board', 'label' => '게시판', 'members' => [
        ['community.board', '게시글'],
        ['community.board_trash', '휴지통'],
        ['community.board_new', '글쓰기', 'action'],
    ]],
    ['key' => 'community.notices', 'label' => '공지사항', 'members' => [
        ['community.notices', '공지'],
        ['community.notice_trash', '휴지통'],
        ['community.notice_new', '공지 쓰기', 'action'],
    ]],
    ['key' => 'messaging.inbox', 'label' => '쪽지', 'members' => [
        ['messaging.inbox', '쪽지함'],
        ['messaging.reports', '신고 내역'],
    ]],
    ['key' => 'community.settings', 'label' => '커뮤니티 설정', 'members' => [
        ['community.settings', '게시판·공지'],
        ['messaging.settings', '쪽지'],
    ]],

    ['key' => 'operations.site', 'label' => '사이트', 'members' => [
        ['operations.site', '기본 정보'],
        ['operations.home', '홈 화면'],
        ['operations.menu', '학생 메뉴'],
    ]],
    ['key' => 'operations.database', 'label' => '채점 환경', 'members' => [
        ['operations.database', '저지 DB'],
        ['operations.test_data', '테스트 데이터'],
    ]],
    ['key' => 'operations.ai_provider', 'label' => 'AI', 'members' => [
        ['operations.ai_provider', '연결 프로필'],
        ['operations.ai_routing', '작업 배정'],
        ['operations.ai_usage', '사용량'],
    ]],
];
