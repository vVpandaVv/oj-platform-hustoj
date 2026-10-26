<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\GroupHtml;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\PerformanceModePolicy;
use DomainException;

final class GroupPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly WordPressUserGateway $users,
        private readonly GroupService $groups,
        private readonly PerformanceModePolicy $performanceMode,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly GroupHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        $actorId = $identity?->userId() ?? 0;
        $actorLogin = $identity?->userLogin() ?? '';
        $routeName = $route->route()->key();
        if ($routeName === 'groups.index') {
            $content = '<header class="ojp-page-header"><div>'
                . '<p class="ojp-eyebrow">학습 공간</p><h1>그룹</h1>'
                . '<p>함께 학습하는 구성원과 풀이 현황을 확인합니다.</p>'
                . '</div></header>'
                . $this->html->groupTable(
                    $this->groups->visibleFor($actorId),
                    false
                );

            return $this->pages->authenticated(
                '그룹',
                $content,
                $request
            );
        }

        $groupId = (int) ($route->parameters()['group_id'] ?? 0);
        try {
            $group = $this->groups->findVisible($groupId, $actorId);
            $content = match ($routeName) {
                'groups.members' => $this->members($actorId, $group),
                'groups.submissions' => $this->report(
                    $actorId,
                    $actorLogin,
                    $group,
                    false,
                    $request
                ),
                'groups.ranking' => $this->report(
                    $actorId,
                    $actorLogin,
                    $group,
                    true,
                    $request
                ),
                default => $this->detail($actorId, $group),
            };

            return $this->pages->authenticated(
                $group->name(),
                $content,
                $request
            );
        } catch (DomainException $error) {
            return $this->pages->authenticated(
                '그룹',
                '<div class="ojp-empty-state"><h1>그룹을 볼 수 없습니다.</h1>'
                    . '<p>' . $this->html->escape($error->getMessage())
                    . '</p></div>',
                $request,
                403
            );
        }
    }

    private function detail(int $actorId, LearningGroup $group): string
    {
        $membership = $this->groups->membershipStatus(
            $group->id(),
            $actorId
        );
        $operation = match ($membership) {
            'active' => 'leave',
            'pending' => 'cancel',
            default => 'join',
        };
        $button = match ($operation) {
            'leave' => '그룹 나가기',
            'cancel' => '참여 요청 취소',
            default => '참여 요청',
        };
        $form = '<form class="ojp-action-bar" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields(
                match ($operation) {
                    'leave' => 'oj_group_leave',
                    'cancel' => 'oj_group_cancel_request',
                    default => 'oj_group_join',
                }
            )
            . '<input type="hidden" name="group_id" value="' . $group->id() . '">'
            . '<input type="hidden" name="operation" value="' . $operation . '">';
        if ($operation === 'join' && $group->visibility() === 'code') {
            $form .= '<label class="ojp-field"><span class="ojp-label">'
                . '참여 코드</span><input class="ojp-input" type="password"'
                . ' name="join_code" required></label>';
        }
        $form .= '<button class="ojp-button ojp-button--primary" type="submit">'
            . $button . '</button></form>';
        $modeNotice = $group->isPerformanceMode()
            ? '<div class="ojp-notice ojp-notice--warning"><strong>수행 모드</strong>'
                . '<p>제출은 가능하며 채점 결과만 확인할 수 있습니다. '
                . '본인의 모든 제출 상세와 코드는 표시되지 않습니다.</p></div>'
            : '';
        $relation = $this->groups->relationOf($actorId, $group);
        $canManage = $relation !== 'none';
        $managerIds = $this->groups->managerUserIds($group->id());
        $ownerId = $group->ownerUserId();
        $owner = $this->users->findById($ownerId);
        $managers = $this->users->findMany(
            array_values(array_diff($managerIds, [$ownerId]))
        );
        $memberCount = count($this->groups->memberUserIds($group->id()));
        $pendingCount = $canManage
            ? count($this->groups->memberUserIds($group->id(), 'pending'))
            : 0;

        return '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '그룹</p><h1>' . $this->html->escape($group->name())
            . '</h1><p>' . $this->html->escape($group->description())
            . '</p></div></header>'
            . $this->html->tabs($group->id(), 'show', false)
            . $modeNotice
            . $this->html->infoPanel(
                $group,
                $owner,
                $managers,
                $memberCount,
                $relation,
                $canManage,
                $pendingCount
            )
            . $form;
    }

    private function members(int $actorId, LearningGroup $group): string
    {
        $memberIds = $this->groups->memberUserIds($group->id());
        $managerIds = array_flip($this->groups->managerUserIds($group->id()));
        $showActivity = $this->groups->canManage($actorId, $group)
            || $this->groups->isActiveMember($group->id(), $actorId);
        $solvedByLogin = [];
        if ($showActivity) {
            $accepted = [];
            foreach (
                $this->groups->report($actorId, $group->id())->submissions()
                as $submission
            ) {
                if ($submission->resultCode() === 4) {
                    $accepted[$submission->userLogin()][$submission->problemId()] = true;
                }
            }
            $solvedByLogin = array_map('count', $accepted);
        }
        $members = $this->users->findMany($memberIds);

        return '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '그룹</p><h1>' . $this->html->escape($group->name())
            . ' 구성원</h1><p>구성원 ' . count($members) . '명</p></div></header>'
            . $this->html->tabs($group->id(), 'members', false)
            . ($this->groups->canManage($actorId, $group)
                ? '<div class="ojp-action-bar"><a class="ojp-button" href="/oj-console/groups/'
                    . $group->id() . '/members">구성원 관리</a></div>' : '')
            . $this->html->memberTable(
                $members,
                $managerIds,
                $group->ownerUserId(),
                $solvedByLogin,
                $showActivity,
                $actorId
            );
    }

    private function report(
        int $actorId,
        string $actorLogin,
        LearningGroup $group,
        bool $ranking,
        HttpRequest $request
    ): string {
        $fromDate = trim($request->queryString('from'));
        $toDate = trim($request->queryString('to'));
        $report = $this->groups->report(
            $actorId,
            $group->id(),
            false,
            $fromDate,
            $toDate
        );
        $title = $ranking ? '순위' : '제출 현황';
        $restricted = ! $ranking && $this->performanceMode
            ->restrictsOwnSubmissionDetails($actorId);
        $canManage = $this->groups->canManage($actorId, $group);

        return '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '그룹</p><h1>' . $this->html->escape($group->name()) . ' '
            . $title . '</h1></div></header>'
            . $this->html->tabs(
                $group->id(),
                $ranking ? 'ranking' : 'submissions',
                false
            )
            . $this->html->periodFilter(
                '/groups/' . $group->id() . '/'
                    . ($ranking ? 'ranking' : 'submissions'),
                $fromDate,
                $toDate
            )
            . ($restricted ? $this->performanceModeNotice() : '')
            . ($ranking
                ? $this->html->rankingTable($report)
                : $this->html->submissionTable(
                    $report,
                    ! $restricted,
                    $actorLogin,
                    $canManage
                ));
    }

    private function performanceModeNotice(): string
    {
        return '<div class="ojp-notice ojp-notice--warning">'
            . '<strong>수행 모드</strong>'
            . '<p>본인의 제출 상세와 코드는 표시되지 않으며 채점 결과만 확인할 수 있습니다.</p>'
            . '</div>';
    }
}
