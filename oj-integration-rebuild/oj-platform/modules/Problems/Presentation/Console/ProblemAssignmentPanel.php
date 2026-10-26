<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CollaboratorRole;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemAssignment;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;

final class ProblemAssignmentPanel
{
    private const ACTION = 'oj_problem_save';

    public function __construct(
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ProblemHtml $html
    ) {
    }

    /**
     * @param array<int, UserIdentity> $users
     */
    public function render(
        ProblemAssignment $assignment,
        array $users,
        bool $canManage
    ): string {
        $problemId = $assignment->problemId()->value();
        $rows = $this->ownerRow($assignment, $users);
        foreach ($assignment->collaborators() as $userId => $role) {
            if ($userId === $assignment->ownerUserId()) {
                continue;
            }
            $rows .= $this->collaboratorRow(
                $problemId,
                $userId,
                $role,
                $users[$userId] ?? null,
                $canManage
            );
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">등록된 담당자가 없습니다.</td></tr>';
        }

        return '<section class="ojp-form-section" id="problem-assignment">'
            . '<div class="ojp-section-heading"><div><h2>담당자</h2><p>'
            . '소유자, 공동 편집자와 테스트데이터 담당 범위를 관리합니다.'
            . '</p></div>'
            . ($canManage
                ? '<span class="ojp-status-badge ojp-status-badge--success">변경 가능</span>'
                : '<span class="ojp-status-badge ojp-status-badge--neutral">조회 전용</span>')
            . '</div><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>사용자</th><th>로그인 아이디</th><th>역할</th>'
            . '<th>명령</th></tr></thead><tbody>' . $rows
            . '</tbody></table></div>'
            . ($canManage ? $this->managementForms($problemId) : '')
            . '</section>';
    }

    /**
     * @param array<int, UserIdentity> $users
     */
    private function ownerRow(
        ProblemAssignment $assignment,
        array $users
    ): string {
        $ownerUserId = $assignment->ownerUserId();
        if ($ownerUserId === null) {
            return '';
        }
        $owner = $users[$ownerUserId] ?? null;

        return '<tr><td>' . $this->html->escape(
            $owner?->displayName() ?? '사용자 #' . $ownerUserId
        ) . '</td><td>' . $this->html->escape(
            $owner?->userLogin() ?? '-'
        ) . '</td><td><span class="ojp-status-badge ojp-status-badge--success">'
            . '소유자</span></td><td>-</td></tr>';
    }

    private function collaboratorRow(
        int $problemId,
        int $userId,
        CollaboratorRole $role,
        ?UserIdentity $user,
        bool $canManage
    ): string {
        return '<tr><td>' . $this->html->escape(
            $user?->displayName() ?? '사용자 #' . $userId
        ) . '</td><td>' . $this->html->escape(
            $user?->userLogin() ?? '-'
        ) . '</td><td>' . $this->html->escape($this->roleLabel($role))
            . '</td><td>'
            . ($canManage
                ? '<form class="ojp-inline-form" method="post" action="'
                    . $this->html->escape($this->actionUrls->actionUrl()) . '">'
                    . $this->security->hiddenFields(self::ACTION)
                    . '<input type="hidden" name="operation" value="revoke">'
                    . '<input type="hidden" name="problem_id" value="' . $problemId . '">'
                    . '<input type="hidden" name="target_user_id" value="' . $userId . '">'
                    . '<button class="ojp-button ojp-button--small" type="submit">'
                    . '담당 해제</button></form>'
                : '-')
            . '</td></tr>';
    }

    private function managementForms(int $problemId): string
    {
        $prefix = '<form class="ojp-assignment-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields(self::ACTION)
            . '<input type="hidden" name="problem_id" value="' . $problemId . '">';

        return '<div class="ojp-assignment-actions">'
            . $prefix
            . '<input type="hidden" name="operation" value="grant">'
            . '<div><h3>공동 담당자 등록</h3><p>WordPress 로그인 아이디로 지정합니다.</p></div>'
            . '<label class="ojp-field"><span class="ojp-label">로그인 아이디</span>'
            . '<input class="ojp-input" name="user_login" maxlength="60" required></label>'
            . '<label class="ojp-field"><span class="ojp-label">역할</span>'
            . '<select class="ojp-select" name="role">'
            . '<option value="editor">공동 편집자</option>'
            . '<option value="testcase_manager">테스트데이터 담당자</option>'
            . '</select></label><button class="ojp-button ojp-button--primary"'
            . ' type="submit">담당자 등록</button></form>'
            . $prefix
            . '<input type="hidden" name="operation" value="assign_owner">'
            . '<div><h3>소유권 이전</h3><p>이전 소유자는 별도로 등록하지 않으면 담당 범위에서 빠집니다.</p></div>'
            . '<label class="ojp-field"><span class="ojp-label">새 소유자 로그인 아이디</span>'
            . '<input class="ojp-input" name="user_login" maxlength="60" required></label>'
            . '<label class="ojp-toggle-row"><input type="checkbox"'
            . ' name="confirm_transfer" value="1" required>'
            . '<span>소유권 이전을 확인했습니다.</span></label>'
            . '<button class="ojp-button" type="submit">소유권 이전</button></form>'
            . '</div>';
    }

    private function roleLabel(CollaboratorRole $role): string
    {
        return match ($role) {
            CollaboratorRole::Editor => '공동 편집자',
            CollaboratorRole::TestcaseManager => '테스트데이터 담당자',
        };
    }
}
