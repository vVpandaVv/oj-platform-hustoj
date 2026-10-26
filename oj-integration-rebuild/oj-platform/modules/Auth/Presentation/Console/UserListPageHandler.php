<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Application\CurrentAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ManagedUser;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserDirectoryService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class UserListPageHandler implements RouteHandler
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountService $accounts,
        private readonly UserDirectoryService $directory,
        private readonly Authorization $permissions,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly PaginationView $pagination,
        private readonly SiteDateFormatter $dates
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);

        $actorUserId = $this->accounts->current()->identity()?->userId() ?? 0;
        $canManage = $this->permissions->can($actorUserId, 'oj_manage_users');
        $canApprove = $this->permissions->can(
            $actorUserId,
            'oj_approve_user_registrations'
        );
        $canResetPassword = $this->permissions->can(
            $actorUserId,
            'oj_edit_users'
        );
        $status = $this->statusFilter($request, $canManage);
        $keyword = $canManage ? trim($request->queryString('q', '')) : '';
        $total = $this->directory->count($status, $keyword);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, (int) $request->queryString('page', '1')), $pageCount);
        $users = $this->directory->page(
            $status,
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE,
            $keyword
        );

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">사용자</p>'
            . '<h1>' . ($canManage ? '사용자 목록' : '가입 승인') . '</h1>'
            . '<p>' . ($canManage
                ? '계정 상태와 필요한 관리 작업을 확인합니다.'
                : '승인 대기 계정의 최소 정보만 표시합니다.')
            . '</p></div>'
            . ($canManage && $this->permissions->can($actorUserId, 'oj_create_users')
                ? '<a class="ojp-button ojp-button--primary" href="/oj-console/users/new">사용자 추가</a>'
                : '')
            . '</header>'
            . ($canManage ? $this->filters($status, $keyword) : '')
            . ($canResetPassword
                ? '<div data-ojp-bulk-select>' . $this->bulkForm()
                : '')
            . $this->table(
                $users,
                $canManage,
                $canApprove,
                $canResetPassword
            )
            . ($canResetPassword ? '</div>' : '')
            . $this->rowForms($users, $canApprove)
            . $this->pagination->links(
                $page,
                $pageCount,
                '/oj-console/users',
                array_filter(
                    ['status' => $status?->value ?? '', 'q' => $keyword],
                    static fn (string $value): bool => $value !== ''
                )
            );

        return $this->pages->respond(
            $canManage ? '사용자 목록' : '가입 승인',
            $content,
            'users.index',
            $request
        );
    }

    private function statusFilter(HttpRequest $request, bool $canManage): ?AccessStatus
    {
        if (! $canManage) {
            return AccessStatus::Pending;
        }

        return AccessStatus::tryFrom($request->queryString('status'));
    }

    private function filters(?AccessStatus $status, string $keyword): string
    {
        $value = $status?->value ?? '';

        return '<form class="ojp-filter-bar" method="get" action="/oj-console/users">'
            . '<div class="ojp-filter-bar__primary">'
            . '<label class="ojp-field"><span class="ojp-label">아이디·이메일·이름 검색</span>'
            . '<input class="ojp-input" type="search" name="q" value="'
            . $this->escape($keyword) . '" placeholder="검색어 입력"></label>'
            . '<label class="ojp-field">'
            . '<span class="ojp-label">이용 상태</span><select class="ojp-select" name="status">'
            . $this->option('', '전체', $value)
            . $this->option('pending', '승인 대기', $value)
            . $this->option('active', '이용 가능', $value)
            . $this->option('suspended', '이용 중지', $value)
            . '</select></label></div>'
            . '<button class="ojp-button" type="submit">적용</button></form>';
    }

    private function bulkForm(): string
    {
        return '<form id="ojp-user-bulk-form" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_user_password_reset')
            . '</form><div class="ojp-bulk-bar" data-ojp-bulk-bar hidden>'
            . '<span><strong data-ojp-selected-count>0</strong>명 선택</span>'
            . '<div class="ojp-password-field" data-ojp-password-group>'
            . '<label class="ojp-field" for="ojp-reset-password">'
            . '<span class="ojp-label">새 비밀번호</span>'
            . '<input class="ojp-input" type="password" id="ojp-reset-password" name="custom_password"'
            . ' form="ojp-user-bulk-form" autocomplete="off" data-ojp-password-suggest></label>'
            . '<button class="ojp-button ojp-button--small" type="button"'
            . ' data-ojp-password-toggle aria-pressed="false" aria-label="비밀번호 보임">보임</button>'
            . '<button class="ojp-button ojp-button--small" type="button"'
            . ' data-ojp-password-regenerate>새로 생성</button>'
            . '<span class="ojp-password-strength" data-ojp-password-strength></span>'
            . '<label class="ojp-check" data-ojp-weak-password-confirm hidden>'
            . '<input type="checkbox" name="confirm_weak_password" value="1" form="ojp-user-bulk-form">'
            . '<span>약한 비밀번호를 사용합니다</span></label>'
            . '</div>'
            . '<button class="ojp-button" type="submit" form="ojp-user-bulk-form"'
            . ' data-ojp-confirm="선택한 사용자의 비밀번호를 초기화할까요? 되돌릴 수 없습니다.">'
            . '비밀번호 초기화</button></div>';
    }

    /**
     * @param list<ManagedUser> $users
     */
    private function table(
        array $users,
        bool $canManage,
        bool $canApprove,
        bool $canResetPassword
    ): string
    {
        $rows = '';
        foreach ($users as $managed) {
            $identity = $managed->identity();
            $profile = $managed->profile();
            $actions = [];

            if ($canApprove && $profile->accessStatus() === AccessStatus::Pending) {
                $actions[] = '<button class="ojp-button ojp-button--small" type="submit" form="'
                    . 'ojp-approve-' . $identity->userId() . '">승인</button>';
            }
            if ($canManage) {
                $actions[] = '<a class="ojp-button ojp-button--small" href="/oj-console/users/'
                    . $identity->userId() . '">상세</a>';
            }

            $rows .= '<tr>'
                . ($canResetPassword
                    ? '<td>' . ($profile->accessStatus() === AccessStatus::Active
                        ? '<input type="checkbox" name="user_ids[]" value="'
                            . $identity->userId()
                            . '" form="ojp-user-bulk-form" data-ojp-select-item'
                            . ' aria-label="' . $this->escape($identity->displayName()) . ' 선택">'
                        : '<span aria-hidden="true">-</span>') . '</td>'
                    : '')
                . '<td>' . $this->escape($identity->userLogin()) . '</td>'
                . '<td>' . $this->escape($identity->displayName()) . '</td>'
                . ($canManage ? '<td>' . $this->escape($identity->nickname()) . '</td>' : '')
                . '<td>' . $this->escape($identity->email()) . '</td>'
                . '<td>' . $this->escape($profile->affiliation() ?? '-') . '</td>'
                . '<td>' . $this->escape($this->dates->format($identity->registeredAt())) . '</td>'
                . '<td>' . $this->statusBadge($profile->accessStatus()) . '</td>'
                . '<td><div class="ojp-command-group">' . implode('', $actions) . '</div></td>'
                . '</tr>';
        }

        $columns = 7 + (int) $canResetPassword + (int) $canManage;
        if ($rows === '') {
            $rows = '<tr><td colspan="' . $columns . '">표시할 사용자가 없습니다.</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table ojp-user-table'
            . ($canResetPassword ? ' ojp-user-table--selectable' : '') . '">'
            . '<thead><tr>'
            . ($canResetPassword
                ? '<th><input type="checkbox" data-ojp-select-all aria-label="전체 선택"></th>'
                : '')
            . '<th>로그인 아이디</th><th>표시 이름</th>'
            . ($canManage ? '<th>학생 이름</th>' : '')
            . '<th>이메일</th><th>소속</th>'
            . '<th>가입 시각</th><th>상태</th><th>작업</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /**
     * @param list<ManagedUser> $users
     */
    private function rowForms(
        array $users,
        bool $canApprove
    ): string
    {
        $forms = '';
        foreach ($users as $managed) {
            $userId = $managed->identity()->userId();
            if ($canApprove && $managed->profile()->accessStatus() === AccessStatus::Pending) {
                $forms .= '<form id="ojp-approve-' . $userId . '" method="post" action="'
                    . $this->escape($this->actionUrls->actionUrl()) . '">'
                    . $this->security->hiddenFields('oj_console_approve_user_registration')
                    . '<input type="hidden" name="user_id" value="' . $userId . '"></form>';
            }
        }

        return $forms;
    }

    private function statusBadge(AccessStatus $status): string
    {
        [$class, $label] = match ($status) {
            AccessStatus::Pending => ['warning', '승인 대기'],
            AccessStatus::Active => ['success', '이용 가능'],
            AccessStatus::Suspended => ['danger', '이용 중지'],
        };

        return '<span class="ojp-status-badge ojp-status-badge--'
            . $class . '">' . $label . '</span>';
    }

    private function option(string $value, string $label, string $current): string
    {
        return '<option value="' . $this->escape($value) . '"'
            . ($value === $current ? ' selected' : '') . '>'
            . $this->escape($label) . '</option>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
