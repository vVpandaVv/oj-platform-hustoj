<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupMemberNamesService;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\GroupHtml;
use DomainException;

final class GroupMemberPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly WordPressUserGateway $users,
        private readonly GroupService $groups,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly GroupHtml $html,
        private readonly GroupMemberNamesService $memberNames
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $actorId = $this->accounts->current()->identity()?->userId() ?? 0;
        $groupId = (int) ($route->parameters()['group_id'] ?? 0);
        try {
            $group = $this->groups->findManaged($groupId, $actorId);
            $key = $route->route()->key();
            [$tab, $title, $body] = match ($key) {
                'console.groups.approvals' => [
                    'approvals',
                    '가입 승인',
                    $this->approvals($group),
                ],
                'console.groups.managers' => [
                    'managers',
                    '운영자',
                    $this->managers($group),
                ],
                'console.groups.bulk_members' => [
                    'bulk',
                    '구성원 일괄 등록',
                    $this->bulkMembers($group),
                ],
                default => [
                    'members',
                    '구성원',
                    $this->members($group),
                ],
            };
            $content = '<header class="ojp-page-header"><div>'
                . '<p class="ojp-eyebrow">그룹 관리</p><h1>'
                . $this->html->escape($group->name()) . ' ' . $title
                . '</h1></div></header>'
                . $this->html->tabs($groupId, $tab, true) . $body;

            return $this->pages->respond(
                $title,
                $content,
                'classes.groups',
                $request
            );
        } catch (DomainException $error) {
            return $this->pages->respond(
                '그룹 구성원',
                '<div class="ojp-empty-state"><h1>구성원을 관리할 수 없습니다.</h1>'
                    . '<p>' . $this->html->escape($error->getMessage())
                    . '</p></div>',
                'classes.groups',
                $request,
                403
            );
        }
    }

    private function members(LearningGroup $group): string
    {
        $rows = '';
        $managerIds = array_flip($this->groups->managerUserIds($group->id()));
        $members = $this->users->findMany($this->groups->memberUserIds($group->id()));
        $editableNames = array_flip($this->memberNames->editableUserIds($group, $members));
        foreach ($members as $member) {
            $protected = $member->userId() === $group->ownerUserId()
                || isset($managerIds[$member->userId()]);
            $selection = $protected
                ? '<span class="ojp-status-badge">운영 계정</span>'
                : '<input type="checkbox" name="target_user_ids[]" value="'
                    . $member->userId() . '" form="group-reset-passwords">';
            $managedName = $this->html->escape($member->nickname());
            if (isset($editableNames[$member->userId()])) {
                $managedName = '<input class="ojp-input" type="text" maxlength="100"'
                    . ' form="group-member-names" data-ojp-managed-name data-user-id="' . $member->userId() . '"'
                    . ' data-original-name="' . $managedName . '" value="' . $managedName . '"'
                    . ' aria-label="' . $this->html->escape($member->userLogin()) . ' 관리용 이름"'
                    . ' aria-describedby="group-name-error-' . $member->userId() . '">'
                    . '<span class="ojp-field-error" id="group-name-error-' . $member->userId() . '"'
                    . ' data-ojp-name-error role="alert" hidden></span>';
            }
            $rows .= '<tr><td>' . $selection . '</td><td>'
                . $this->html->escape($member->displayName()) . '</td><td>' . $managedName . '</td><td>'
                . $this->html->escape($member->userLogin()) . '</td><td>'
                . $this->membershipForm(
                    'oj_group_remove_membership',
                    $group->id(),
                    $member->userId(),
                    'remove',
                    '제외'
                ) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">활성 구성원이 없습니다.</td></tr>';
        }

        return '<section data-ojp-member-names><form class="ojp-filter-bar" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_group_add_member')
            . '<input type="hidden" name="group_id" value="' . $group->id() . '">'
            . '<input type="hidden" name="operation" value="add">'
            . '<label class="ojp-field"><span class="ojp-label">로그인 아이디</span>'
            . '<input class="ojp-input" type="search"'
            . ' name="target_user_login" autocomplete="off" required></label>'
            . '<button class="ojp-button" type="submit">구성원 추가</button></form>'
            . '<div class="ojp-filter-bar"><label class="ojp-field">'
            . '<span class="ojp-label">구성원 검색</span>'
            . '<input class="ojp-input" type="search" data-ojp-table-filter="ojp-group-member-table"'
            . ' placeholder="이름·아이디 검색" title="표시 이름, 관리용 이름, 로그인 아이디 검색"></label></div>'
            . '<form id="group-member-names" class="ojp-action-bar" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '" data-ojp-name-form>'
            . $this->security->hiddenFields('oj_group_save_member_names')
            . '<input type="hidden" name="group_id" value="' . $group->id() . '">'
            . '<div class="ojp-inline-actions"><button class="ojp-button ojp-button--primary" type="submit" disabled>관리용 이름 일괄 저장</button>'
            . '<button class="ojp-button" type="button" data-ojp-name-reset disabled>변경 취소</button></div>'
            . '<span data-ojp-name-count>변경 0명</span></form>'
            . '<p role="status" aria-live="polite" data-ojp-name-status></p>'
            . '<noscript><p>이름 일괄 편집에는 JavaScript가 필요합니다.</p></noscript>'
            . '<div class="ojp-table-wrap"><table class="ojp-table" id="ojp-group-member-table"'
            . ' data-ojp-sortable-table>'
            . '<thead><tr><th>선택</th><th data-ojp-sort-key="name">표시 이름</th>'
            . '<th data-ojp-sort-key="managed-name">관리용 이름</th>'
            . '<th data-ojp-sort-key="login">로그인 아이디</th>'
            . '<th>작업</th></tr></thead><tbody>' . $rows
            . '</tbody></table></div>'
            . '<form id="group-reset-passwords" class="ojp-action-bar"'
            . ' method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_group_reset_member_password')
            . '<input type="hidden" name="group_id" value="' . $group->id() . '">'
            . '<div class="ojp-password-field" data-ojp-password-group>'
            . '<label class="ojp-field" for="ojp-group-reset-password">'
            . '<span class="ojp-label">새 비밀번호</span>'
            . '<input class="ojp-input" type="password" id="ojp-group-reset-password"'
            . ' name="custom_password" form="group-reset-passwords" autocomplete="off"'
            . ' data-ojp-password-suggest></label>'
            . '<button class="ojp-button ojp-button--small" type="button"'
            . ' data-ojp-password-toggle aria-pressed="false" aria-label="비밀번호 보임">보임</button>'
            . '<button class="ojp-button ojp-button--small" type="button"'
            . ' data-ojp-password-regenerate>새로 생성</button>'
            . '<span class="ojp-password-strength" data-ojp-password-strength></span>'
            . '<label class="ojp-check" data-ojp-weak-password-confirm hidden>'
            . '<input type="checkbox" name="confirm_weak_password" value="1"'
            . ' form="group-reset-passwords">'
            . '<span>약한 비밀번호를 사용합니다</span></label>'
            . '</div>'
            . '<button class="ojp-button" type="submit"'
            . ' data-ojp-confirm="선택한 구성원의 비밀번호를 초기화할까요? 되돌릴 수 없습니다.">'
            . '선택한 구성원 비밀번호 초기화</button></form></section>';
    }

    private function approvals(LearningGroup $group): string
    {
        $rows = '';
        foreach (
            $this->users->findMany(
                $this->groups->memberUserIds($group->id(), 'pending')
            ) as $member
        ) {
            $rows .= '<tr><td>' . $this->html->escape($member->displayName())
                . '</td><td>' . $this->html->escape($member->nickname())
                . '</td><td>' . $this->html->escape($member->userLogin())
                . '</td><td>'
                . $this->membershipForm(
                    'oj_group_approve_membership',
                    $group->id(),
                    $member->userId(),
                    'approve',
                    '승인'
                )
                . $this->membershipForm(
                    'oj_group_reject_membership',
                    $group->id(),
                    $member->userId(),
                    'reject',
                    '거절'
                ) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">승인 대기자가 없습니다.</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>표시 이름</th><th>관리용 이름</th><th>로그인 아이디</th><th>작업</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function managers(LearningGroup $group): string
    {
        $rows = '';
        foreach (
            $this->users->findMany(
                $this->groups->managerUserIds($group->id())
            ) as $manager
        ) {
            $rows .= '<tr><td>' . $this->html->escape($manager->displayName())
                . '</td><td>' . $this->html->escape($manager->nickname())
                . '</td><td>' . $this->html->escape($manager->userLogin())
                . '</td><td>' . $this->managerForm(
                    'oj_group_revoke_manager',
                    $group->id(),
                    $manager->userId(),
                    '해제'
                ) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">위임 운영자가 없습니다.</td></tr>';
        }

        return '<form class="ojp-filter-bar" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_group_grant_manager')
            . '<input type="hidden" name="group_id" value="' . $group->id() . '">'
            . '<label class="ojp-field"><span class="ojp-label">로그인 아이디</span>'
            . '<input class="ojp-input" type="search"'
            . ' name="target_user_login" autocomplete="off" required></label>'
            . '<button class="ojp-button" type="submit">운영자 지정</button></form>'
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>표시 이름</th><th>관리용 이름</th><th>로그인 아이디</th><th>작업</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function bulkMembers(LearningGroup $group): string
    {
        return '<div class="ojp-notice"><strong>입력 형식</strong>'
            . '<p>기존 사용자는 로그인 아이디만 입력합니다. 새 사용자는 '
            . '<code>로그인 아이디,이메일,표시 이름,초기 비밀번호</code> 형식으로 '
            . '한 줄에 한 명씩 입력합니다. 새 계정의 초기 비밀번호는 필수이며 '
            . '암호화된 작업 입력으로만 보관되고 결과 화면에는 표시되지 않습니다.</p></div>'
            . '<form class="ojp-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_group_bulk_members')
            . '<input type="hidden" name="group_id" value="' . $group->id() . '">'
            . '<label class="ojp-field ojp-field--full"><span class="ojp-label">'
            . '구성원 목록</span><textarea class="ojp-textarea" name="members_csv"'
            . ' rows="12" required></textarea></label>'
            . '<div class="ojp-action-bar"><button class="ojp-button '
            . 'ojp-button--primary" type="submit">등록 작업 시작</button>'
            . '</div></form>';
    }

    private function membershipForm(
        string $action,
        int $groupId,
        int $userId,
        string $operation,
        string $label
    ): string {
        return '<form class="ojp-inline-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields($action)
            . '<input type="hidden" name="group_id" value="' . $groupId . '">'
            . '<input type="hidden" name="target_user_id" value="' . $userId . '">'
            . '<input type="hidden" name="operation" value="' . $operation . '">'
            . '<button class="ojp-button ojp-button--small" type="submit">'
            . $label . '</button></form>';
    }

    private function managerForm(
        string $action,
        int $groupId,
        int $userId,
        string $label
    ): string {
        return '<form class="ojp-inline-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields($action)
            . '<input type="hidden" name="group_id" value="' . $groupId . '">'
            . '<input type="hidden" name="target_user_id" value="' . $userId . '">'
            . '<button class="ojp-button ojp-button--small" type="submit">'
            . $label . '</button></form>';
    }
}
