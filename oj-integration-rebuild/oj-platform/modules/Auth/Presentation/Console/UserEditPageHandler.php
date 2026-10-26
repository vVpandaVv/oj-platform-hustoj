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
use CodingPanda\OjPlatform\Modules\Auth\Application\UserDirectoryService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserManagementTargetPolicy;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;

final class UserEditPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly UserDirectoryService $directory,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly CurrentAccountService $accounts,
        private readonly Authorization $permissions,
        private readonly UserManagementTargetPolicy $profileEditPolicy
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
        $canEdit = $this->permissions->can($actorUserId, 'oj_edit_users')
            && $this->profileEditPolicy->allows($actorUserId, $identity);
        $canChangeAccess = $this->permissions->can(
            $actorUserId,
            'oj_deactivate_users'
        ) && $profile->accessStatus() !== AccessStatus::Pending
            && $actorUserId !== $userId
            && $this->profileEditPolicy->allows($actorUserId, $identity, 'oj_deactivate_users');
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">사용자</p>'
            . '<h1>계정 관리</h1><p>@' . $this->escape($identity->userLogin())
            . '</p></div></header>'
            . ($canEdit ? '<section class="ojp-section">'
            . '<form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_console_update_user')
            . '<input type="hidden" name="user_id" value="' . $userId . '">'
            . '<div class="ojp-form-grid">'
            . $this->field('이메일', 'email', 'email', $identity->email())
            . $this->field('표시 이름', 'display_name', 'text', $identity->displayName())
            . $this->field('학생 이름 (관리용)', 'nickname', 'text', $identity->nickname())
            . $this->field('소속', 'affiliation', 'text', $profile->affiliation() ?? '', false)
            . $this->field('생년', 'birth_date', 'date', $profile->birthDate() ?? '', false)
            . $this->textarea('소개', 'bio', $profile->bio() ?? '')
            . '</div><button class="ojp-button ojp-button--primary" type="submit">변경 저장</button>'
            . '</form></section>' : '')
            . ($canChangeAccess ? '<section class="ojp-section">'
            . '<div class="ojp-section-heading"><div><h2>이용 상태</h2></div></div>'
            . '<form class="ojp-control-row" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_console_toggle_user_active')
            . '<input type="hidden" name="user_id" value="' . $userId . '">'
            . '<select class="ojp-select" name="access_status">'
            . $this->option(AccessStatus::Active, $profile->accessStatus(), '이용 가능')
            . $this->option(AccessStatus::Suspended, $profile->accessStatus(), '이용 중지')
            . '</select><button class="ojp-button" type="submit">상태 변경</button>'
            . '</form></section>' : '');

        return $this->pages->respond(
            '계정 관리',
            $content,
            'users.index',
            $request
        );
    }

    private function field(
        string $label,
        string $name,
        string $type,
        string $value,
        bool $required = true
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">'
            . $this->escape($label) . '</span><input class="ojp-input" type="'
            . $this->escape($type) . '" name="' . $this->escape($name)
            . '" value="' . $this->escape($value) . '"'
            . ($required ? ' required' : '') . '></label>';
    }

    private function textarea(string $label, string $name, string $value): string
    {
        return '<label class="ojp-field ojp-field--wide"><span class="ojp-label">'
            . $this->escape($label) . '</span><textarea class="ojp-textarea" name="'
            . $this->escape($name) . '" rows="4" maxlength="1000">'
            . $this->escape($value) . '</textarea></label>';
    }

    private function option(
        AccessStatus $value,
        AccessStatus $current,
        string $label
    ): string {
        return '<option value="' . $value->value . '"'
            . ($value === $current ? ' selected' : '') . '>'
            . $this->escape($label) . '</option>';
    }

    private function userId(RouteMatch $route): int
    {
        $value = $route->parameters()['user_id'] ?? '';

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : 0;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
