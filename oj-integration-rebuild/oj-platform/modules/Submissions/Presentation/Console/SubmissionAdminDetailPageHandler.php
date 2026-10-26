<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceViewProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionAccessLevel;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionHtml;

final class SubmissionAdminDetailPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly Authorization $permissions,
        private readonly SubmissionQueryService $submissions,
        private readonly SubmissionSourceViewProvider $sourceViews,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly SubmissionHtml $html,
        private readonly ?\CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionDetailExtensionRegistry $extensions = null
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            return HttpResponse::redirect('/login');
        }
        $id = (int) ($route->parameters()['submission_id'] ?? 0);
        if ($id < 1) {
            return $this->notFound($request);
        }
        $view = $this->submissions->view(
            $identity->userId(),
            $identity->userLogin(),
            new SubmissionId($id)
        );
        if ($view === null) {
            return $this->notFound($request);
        }
        $summary = $view->summary();
        $detail = $view->detail();
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">제출 관리</p><h1>제출 #' . $id . '</h1>'
            . '<p>문제 ' . $summary->problemId() . ' · '
            . $this->html->escape($summary->userLogin())
            . '</p></div><div class="ojp-page-actions">'
            . '<a class="ojp-button" href="/oj-console/submissions">목록</a>'
            . '</div></header><section class="ojp-section">'
            . '<dl class="ojp-description-grid"><div><dt>결과</dt><dd>'
            . $this->html->resultBadge($summary->resultCode())
            . '</dd></div><div><dt>언어</dt><dd>'
            . $this->html->language($summary->languageId())
            . '</dd></div><div><dt>실행 시간</dt><dd>'
            . number_format($summary->timeMilliseconds())
            . 'ms</dd></div><div><dt>메모리</dt><dd>'
            . number_format($summary->memoryKilobytes())
            . 'KB</dd></div><div><dt>상태</dt><dd>'
            . ($summary->isValid() ? '유효' : '무효 처리됨')
            . '</dd></div><div><dt>제출 시각</dt><dd>'
            . $this->html->escape($this->html->date($summary->submittedAt()))
            . '</dd></div></dl></section>'
            . $this->operations($identity->userId(), $summary->isValid(), $id)
            . ($view->access() === SubmissionAccessLevel::Full && $detail !== null
                ? $this->detail($detail)
                : '<div class="ojp-notice ojp-notice--warning">'
                    . '<strong>결과만 볼 수 있습니다.</strong>'
                    . '<p>현재 담당 범위 또는 권한에서는 코드와 오류 내용을 조회할 수 없습니다.</p>'
                    . '</div>')
            . ($this->extensions?->renderAdmin($identity->userId(), $identity->userLogin(), $id) ?? '');

        return $this->pages->respond(
            '제출 #' . $id,
            $content,
            'submissions.index',
            $request
        );
    }

    private function operations(int $actorUserId, bool $valid, int $id): string
    {
        $forms = '';
        if (
            $valid
            && $this->permissions->can(
                $actorUserId,
                'oj_rejudge_submissions'
            )
        ) {
            $forms .= $this->operationForm(
                'oj_submission_rejudge',
                $id,
                '재채점',
                '재채점 사유',
                'ojp-button--primary'
            );
        }
        if ($this->permissions->can($actorUserId, 'oj_delete_submissions')) {
            $forms .= $this->operationForm(
                $valid ? 'oj_submission_delete' : 'oj_submission_restore',
                $id,
                $valid ? '무효 처리' : '복원',
                $valid ? '무효 처리 사유' : '복원 사유',
                $valid ? 'ojp-button--danger' : 'ojp-button--primary'
            );
        }

        return $forms === ''
            ? ''
            : '<section class="ojp-section"><div class="ojp-inline-actions">'
                . $forms . '</div></section>';
    }

    private function operationForm(
        string $action,
        int $id,
        string $button,
        string $placeholder,
        string $buttonClass
    ): string {
        return '<form class="ojp-inline-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields($action)
            . '<input type="hidden" name="submission_ids[]" value="' . $id . '">'
            . '<input type="hidden" name="request_key" value="'
            . bin2hex(random_bytes(32)) . '">'
            . '<label class="ojp-field"><span class="ojp-sr-only">'
            . $placeholder . '</span><input class="ojp-input" type="text"'
            . ' name="reason" minlength="3" maxlength="500" required'
            . ' placeholder="' . $placeholder . '"></label>'
            . '<button class="ojp-button ' . $buttonClass . '" type="submit">'
            . $button . '</button></form>';
    }

    private function detail(
        \CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionDetail $detail
    ): string {
        $sourceView = $this->sourceViews->view($detail->userSource());
        $sections = '<section class="ojp-section"><h2>사용자가 작성한 코드</h2>'
            . '<pre class="ojp-code-block"><code>'
            . $this->html->escape($sourceView->displaySource())
            . '</code></pre></section>';
        if (
            $detail->judgeSource() !== ''
            && $detail->judgeSource() !== $detail->userSource()
        ) {
            $sections .= '<section class="ojp-section"><details class="ojp-disclosure">'
                . '<summary>실제 채점한 코드</summary>'
                . '<pre class="ojp-code-block"><code>'
                . $this->html->escape($detail->judgeSource())
                . '</code></pre></details></section>';
        }
        if (trim($detail->compileError()) !== '') {
            $sections .= '<section class="ojp-section"><h2>컴파일 메시지</h2>'
                . '<pre class="ojp-code-block"><code>'
                . $this->html->escape($detail->compileError())
                . '</code></pre></section>';
        }
        $sections .= $this->html->judgeReport(
            $detail->runtimeError(),
            true
        );

        return $sections;
    }

    private function notFound(HttpRequest $request): HttpResponse
    {
        return $this->pages->respond(
            '제출 상세',
            '<header class="ojp-page-header"><div><p class="ojp-eyebrow">제출 관리</p>'
                . '<h1>제출을 찾을 수 없습니다.</h1>'
                . '<p>대상이 없거나 현재 담당 범위에서 조회할 수 없습니다.</p>'
                . '</div></header>',
            'submissions.index',
            $request,
            404
        );
    }
}
