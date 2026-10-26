<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Console;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Application\CurrentAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserDirectoryService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserRoleAssignmentPolicy;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;

final class UserRolesPageHandler implements RouteHandler
{
    /**
     * @param DefinitionRegistry<RoleDefinition> $roles
     */
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly UserDirectoryService $directory,
        private readonly DefinitionRegistry $roles,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly CurrentAccountService $accounts,
        private readonly UserRoleAssignmentPolicy $policy
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
                'users.roles',
                $request,
                404
            );
        }
        $actorUserId = $this->accounts->current()->identity()?->userId() ?? 0;
        if (! $this->policy->allows($actorUserId, $managed->identity())) {
            return $this->pages->respond(
                '역할 변경 불가',
                '<div class="ojp-error-state"><p>이 사용자의 역할은 변경할 수 없습니다.</p>'
                . '<a class="ojp-button" href="/oj-console/users">사용자 목록으로 이동</a></div>',
                'users.roles',
                $request,
                403
            );
        }

        $assigned = array_fill_keys($managed->identity()->roles(), true);
        $choices = '';
        foreach ($this->roles->all() as $role) {
            if (! $role instanceof RoleDefinition) {
                continue;
            }

            $choices .= '<label class="ojp-check"><input type="checkbox" name="roles[]" value="'
                . $this->escape($role->key()) . '"'
                . (isset($assigned[$role->key()]) ? ' checked' : '') . '>'
                . '<span><strong>' . $this->escape($role->label())
                . '</strong><small>' . count($role->capabilities())
                . '개 권한</small></span></label>';
        }

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">사용자</p>'
            . '<h1>사용자 역할</h1><p>'
            . $this->escape($managed->identity()->displayName()) . '</p></div></header>'
            . '<section class="ojp-section"><form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_console_update_user_roles')
            . '<input type="hidden" name="user_id" value="' . $userId . '">'
            . '<div class="ojp-notice"><strong>OJ 운영 역할만 변경합니다.</strong>'
            . '<p>WordPress 기본 역할과 다른 플러그인이 추가한 역할은 그대로 유지됩니다.</p></div>'
            . '<div class="ojp-role-list">' . $choices . '</div>'
            . '<button class="ojp-button ojp-button--primary" type="submit">역할 저장</button>'
            . '</form></section>';

        return $this->pages->respond(
            '사용자 역할',
            $content,
            'users.roles',
            $request
        );
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
