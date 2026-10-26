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
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\GroupHtml;
use DomainException;

final class GroupManagementPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly GroupService $groups,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly GroupHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $actorId = $this->accounts->current()->identity()?->userId() ?? 0;
        $name = $route->route()->key();
        if (in_array($name, ['console.groups.index', 'console.groups.deleted'], true)) {
            $deleted = $name === 'console.groups.deleted';
            $groups = array_values(array_filter(
                $this->groups->managedBy($actorId),
                static fn (LearningGroup $group): bool =>
                    $group->isDeleted() === $deleted
            ));
            $addButton = ! $deleted && $this->groups->canCreate($actorId)
                ? '<a class="ojp-button ojp-button--primary"'
                    . ' href="/oj-console/groups/new">그룹 추가</a>'
                : '';
            $content = '<header class="ojp-page-header"><div>'
                . '<p class="ojp-eyebrow">학습 공간</p><h1>'
                . ($deleted ? '보관된 그룹' : '그룹 관리')
                . '</h1></div><div class="ojp-page-actions">'
                . $addButton . '</div></header>'
                . $this->html->groupTable(
                    $groups,
                    true,
                    fn (LearningGroup $group): bool => $this->groups->canArchive($actorId, $group)
                );

            return $this->pages->respond(
                $deleted ? '보관된 그룹' : '그룹 관리',
                $content,
                $deleted ? 'classes.groups_deleted' : 'classes.groups',
                $request
            );
        }

        $groupId = (int) ($route->parameters()['group_id'] ?? 0);
        try {
            $group = $groupId > 0
                ? $this->groups->findManaged($groupId, $actorId)
                : null;
            $content = $name === 'console.groups.delete'
                ? $this->deleteForm($group)
                : $this->editForm($group, $actorId);

            return $this->pages->respond(
                $group === null ? '그룹 추가' : '그룹 설정',
                $content,
                $group === null ? 'classes.groups_new' : 'classes.groups',
                $request
            );
        } catch (DomainException $error) {
            return $this->pages->respond(
                '그룹 관리',
                '<div class="ojp-empty-state"><h1>그룹을 관리할 수 없습니다.</h1>'
                    . '<p>' . $this->html->escape($error->getMessage())
                    . '</p></div>',
                'classes.groups',
                $request,
                403
            );
        }
    }

    private function editForm(?LearningGroup $group, int $actorId): string
    {
        $groupId = $group?->id() ?? 0;
        $title = $group === null ? '그룹 추가' : $group->name() . ' 설정';
        $tabs = $group === null
            ? ''
            : $this->html->tabs($groupId, 'edit', true);

        return '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '그룹 관리</p><h1>' . $this->html->escape($title)
            . '</h1></div></header>' . $tabs
            . '<form class="ojp-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_group_save')
            . '<input type="hidden" name="group_id" value="' . $groupId . '">'
            . '<div class="ojp-form-grid">'
            . $this->input('name', '그룹 이름', $group?->name() ?? '', true)
            . $this->input('slug', '그룹 주소', $group?->slug() ?? '', true)
            . $this->select(
                'visibility',
                '공개 범위',
                $group?->visibility() ?? 'private',
                ['private' => '비공개', 'public' => '공개', 'code' => '참여 코드']
            )
            . $this->select(
                'approval_mode',
                '가입 승인',
                $group?->approvalMode() ?? 'manual',
                ['manual' => '수동 승인', 'automatic' => '자동 승인']
            )
            . $this->select(
                'evaluation_mode',
                '운영 모드',
                $group?->evaluationMode() ?? 'normal',
                ['normal' => '일반 모드', 'performance' => '수행 모드']
            )
            . $this->select(
                'blockly_profile_key',
                'Blockly 프로필',
                $group?->blocklyProfileKey() ?? '',
                [
                    '' => '지정 안 함',
                    'intro' => '입문',
                    'standard' => '표준',
                    'extended' => '확장',
                ]
            )
            . $this->input('join_code', '새 참여 코드', '', false, 'password')
            . '</div><label class="ojp-field ojp-field--full">'
            . '<span class="ojp-label">설명</span><textarea class="ojp-textarea"'
            . ' name="description" rows="6">'
            . $this->html->escape($group?->description() ?? '')
            . '</textarea></label><label class="ojp-check">'
            . '<input type="checkbox" name="enabled" value="1"'
            . (($group?->isEnabled() ?? true) ? ' checked' : '')
            . '><span>그룹 운영</span></label>'
            . '<div class="ojp-action-bar"><button class="ojp-button '
            . 'ojp-button--primary" type="submit">저장</button>'
            . ($group !== null && $this->groups->canArchive($actorId, $group)
                ? $this->html->lifecycleLink($group)
                : '')
            . '</div></form>';
    }

    private function deleteForm(?LearningGroup $group): string
    {
        if ($group === null) {
            throw new DomainException('보관할 그룹을 찾을 수 없습니다.');
        }
        $action = $group->isDeleted() ? 'oj_group_restore' : 'oj_group_archive';
        $label = $group->isDeleted() ? '복원' : '보관';

        return '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '그룹 관리</p><h1>' . $this->html->escape($group->name())
            . ' ' . $label . '</h1></div></header>'
            . '<div class="ojp-notice ojp-notice--warning"><p>구성원과 제출 기록은 '
            . '삭제하지 않고 그룹 접근만 중지합니다.</p></div>'
            . '<form method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields($action)
            . '<input type="hidden" name="group_id" value="' . $group->id() . '">'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . $label . '</button></form>';
    }

    private function input(
        string $name,
        string $label,
        string $value,
        bool $required,
        string $type = 'text'
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">'
            . $label . '</span><input class="ojp-input" type="' . $type
            . '" name="' . $name . '" value="' . $this->html->escape($value)
            . '"' . ($required ? ' required' : '') . '></label>';
    }

    /**
     * @param array<string, string> $options
     */
    private function select(
        string $name,
        string $label,
        string $value,
        array $options
    ): string {
        $html = '<label class="ojp-field"><span class="ojp-label">'
            . $label . '</span><select class="ojp-select" name="' . $name . '">';
        foreach ($options as $option => $text) {
            $html .= '<option value="' . $option . '"'
                . ($option === $value ? ' selected' : '') . '>'
                . $text . '</option>';
        }

        return $html . '</select></label>';
    }
}
