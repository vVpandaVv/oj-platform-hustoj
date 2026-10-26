<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\ClassHtml;
use DomainException;

final class ClassMemberPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly WordPressUserGateway $users,
        private readonly ClassService $classes,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ClassHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $actorId = $this->accounts->current()->identity()?->userId() ?? 0;
        $classId = (int) ($route->parameters()['class_id'] ?? 0);
        try {
            $class = $this->classes->findManaged($classId, $actorId);
        } catch (DomainException $error) {
            return $this->pages->respond(
                '수업 참여자',
                '<div class="ojp-empty-state"><h1>참여자를 볼 수 없습니다.</h1><p>'
                    . $this->html->escape($error->getMessage()) . '</p></div>',
                'classes.index',
                $request,
                403
            );
        }
        $rows = $this->rows(
            $classId,
            $this->classes->participantUserIds($classId),
            'active'
        ) . $this->rows(
            $classId,
            $this->classes->participantUserIds($classId, 'pending'),
            'pending'
        ) . $this->rows(
            $classId,
            $this->classes->participantUserIds($classId, 'invited'),
            'invited'
        );
        if ($rows === '') {
            $rows = '<tr><td colspan="4">참여자, 승인 대기자 또는 초대 대기자가 없습니다.</td></tr>';
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '수업 관리</p><h1>' . $this->html->escape($class->name())
            . ' 참여자</h1></div></header>'
            . $this->html->tabs($classId, 'participants', true)
            . '<form class="ojp-filter-bar" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_class_membership_command')
            . '<input type="hidden" name="class_id" value="' . $classId . '">'
            . '<input type="hidden" name="operation" value="invite">'
            . '<label class="ojp-field"><span class="ojp-label">로그인 아이디</span>'
            . '<input class="ojp-input" type="search" name="target_user_login"'
            . ' autocomplete="off" required>'
            . '</label><button class="ojp-button" type="submit">초대</button></form>'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>사용자</th><th>로그인 아이디</th><th>상태</th><th>작업</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';

        return $this->pages->respond(
            '수업 참여자',
            $content,
            'classes.index',
            $request
        );
    }

    /**
     * @param list<int> $userIds
     */
    private function rows(int $classId, array $userIds, string $status): string
    {
        $rows = '';
        foreach ($this->users->findMany($userIds) as $identity) {
            $operation = match ($status) {
                'pending' => 'approve',
                'invited' => null,
                default => 'remove',
            };
            $statusLabel = match ($status) {
                'pending' => '승인 대기',
                'invited' => '초대 수락 대기',
                default => '참여',
            };
            $action = $operation === null
                ? '<span class="ojp-status-badge">대기</span>'
                : '<form class="ojp-inline-form" method="post" action="'
                    . $this->html->escape($this->actionUrls->actionUrl()) . '">'
                    . $this->security->hiddenFields('oj_class_membership_command')
                    . '<input type="hidden" name="class_id" value="' . $classId . '">'
                    . '<input type="hidden" name="target_user_id" value="'
                    . $identity->userId() . '"><input type="hidden" name="operation" value="'
                    . $operation . '"><button class="ojp-button ojp-button--small"'
                    . ' type="submit">' . ($operation === 'approve' ? '승인' : '제외')
                    . '</button></form>';
            $rows .= '<tr><td>' . $this->html->escape($identity->displayName())
                . '</td><td>' . $this->html->escape($identity->userLogin())
                . '</td><td>' . $statusLabel . '</td><td>' . $action . '</td></tr>';
        }

        return $rows;
    }
}
