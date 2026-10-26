<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Application\CurrentAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserDirectoryService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class UserDetailPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly UserDirectoryService $directory,
        private readonly CurrentAccountService $accounts,
        private readonly Authorization $permissions,
        /** @var DefinitionRegistry<RoleDefinition> */
        private readonly DefinitionRegistry $roles,
        private readonly SiteDateFormatter $dates
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $userId = $this->userId($route);
        $managed = $this->directory->find($userId);
        if ($managed === null) {
            return $this->pages->respond(
                '사용자 없음',
                '<div class="ojp-empty-state"><p>사용자를 찾을 수 없습니다.</p></div>',
                'users.index',
                $request,
                404
            );
        }

        $identity = $managed->identity();
        $profile = $managed->profile();
        $actorUserId = $this->accounts->current()->identity()?->userId() ?? 0;
        $commands = [];
        if (
            $this->permissions->can($actorUserId, 'oj_edit_users')
            || $this->permissions->can($actorUserId, 'oj_deactivate_users')
        ) {
            $commands[] = '<a class="ojp-button" href="/oj-console/users/'
                . $userId . '/edit">계정 관리</a>';
        }
        if ($this->permissions->can($actorUserId, 'oj_manage_roles')) {
            $commands[] = '<a class="ojp-button" href="/oj-console/users/'
                . $userId . '/roles">역할</a>';
        }
        if ($this->permissions->can($actorUserId, 'oj_manage_users')) {
            $commands[] = '<a class="ojp-button" href="/oj-console/users/'
                . $userId . '/login-history">로그인 기록</a>';
        }

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">사용자</p>'
            . '<h1>' . $this->escape($identity->displayName()) . '</h1>'
            . '<p>@' . $this->escape($identity->userLogin()) . '</p></div>'
            . ($commands !== []
                ? '<div class="ojp-command-group">' . implode('', $commands) . '</div>'
                : '')
            . '</header>'
            . '<section class="ojp-section"><dl class="ojp-detail-list">'
            . '<div><dt>사용자 번호</dt><dd>' . $userId . '</dd></div>'
            . '<div><dt>이메일</dt><dd>' . $this->escape($identity->email()) . '</dd></div>'
            . '<div><dt>학생 이름 (관리용)</dt><dd>' . $this->escape($identity->nickname()) . '</dd></div>'
            . '<div><dt>소속</dt><dd>' . $this->escape($profile->affiliation() ?? '-') . '</dd></div>'
            . '<div><dt>소개</dt><dd>' . nl2br($this->escape($profile->bio() ?? '-')) . '</dd></div>'
            . '<div><dt>생년</dt><dd>' . $this->escape($profile->birthDate() ?? '-') . '</dd></div>'
            . '<div><dt>가입 시각</dt><dd>'
            . $this->escape($this->dates->format($identity->registeredAt())) . '</dd></div>'
            . '<div><dt>이용 상태</dt><dd>' . $this->escape($this->accessStatusLabel($profile->accessStatus())) . '</dd></div>'
            . '<div><dt>역할</dt><dd>' . $this->escape($this->roleLabels($identity->roles())) . '</dd></div>'
            . '</dl></section>';

        return $this->pages->respond(
            $identity->displayName(),
            $content,
            'users.index',
            $request
        );
    }

    private function userId(RouteMatch $route): int
    {
        $value = $route->parameters()['user_id'] ?? '';

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : 0;
    }

    private function accessStatusLabel(AccessStatus $status): string
    {
        return match ($status) {
            AccessStatus::Pending => '승인 대기',
            AccessStatus::Active => '이용 가능',
            AccessStatus::Suspended => '이용 중지',
        };
    }

    /** @param list<string> $roleKeys */
    private function roleLabels(array $roleKeys): string
    {
        $labels = [];
        foreach ($roleKeys as $roleKey) {
            $role = $this->roles->find($roleKey);
            if ($role instanceof RoleDefinition) {
                $labels[] = $role->label();
            }
        }
        $labels = array_values(array_unique($labels));

        return $labels === [] ? '등록된 역할 없음' : implode(', ', $labels);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
