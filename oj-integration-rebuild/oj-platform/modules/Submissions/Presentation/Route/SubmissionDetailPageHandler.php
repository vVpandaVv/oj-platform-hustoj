<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNavigationContext;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionDetailExtensionRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionAccessLevel;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceViewProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmissionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionHtml;

final class SubmissionDetailPageHandler implements RouteHandler
{
    private const POLL_ACTION = 'oj_submission_poll';

    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly SubmissionQueryService $submissions,
        private readonly SubmissionSettingsProvider $settings,
        private readonly SubmissionSourceViewProvider $sourceViews,
        private readonly AiFeedbackSubmissionProvider $aiFeedbackSubmissions,
        private readonly SubmissionDetailExtensionRegistry $extensions,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly SubmissionHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            return HttpResponse::redirect('/login');
        }
        $submissionId = (int) (
            $route->parameters()['submission_id'] ?? 0
        );
        if ($submissionId < 1) {
            return $this->notFound($request);
        }
        $view = $this->submissions->view(
            $identity->userId(),
            $identity->userLogin(),
            new SubmissionId($submissionId)
        );
        if ($view === null) {
            return $this->notFound($request);
        }
        if ($view->access() === SubmissionAccessLevel::ResultOnly) {
            return HttpResponse::redirect('/submissions');
        }
        if ($view->detail() === null) {
            return $this->notFound($request);
        }
        $summary = $view->summary();
        $detail = $view->detail();
        $sourceView = $this->sourceViews->view($detail->userSource());
        $result = $this->submissions->result($summary->resultCode());
        $display = $this->settings->display();
        $metrics = '';
        if ($display['show_language']) {
            $metrics .= '<div><dt>언어</dt><dd>'
                . $this->html->language($summary->languageId()) . '</dd></div>';
        }
        if ($display['show_time']) {
            $metrics .= '<div><dt>실행 시간</dt><dd data-oj-result-time>'
                . number_format($summary->timeMilliseconds()) . 'ms</dd></div>';
        }
        if ($display['show_memory']) {
            $metrics .= '<div><dt>메모리</dt><dd data-oj-result-memory>'
                . number_format($summary->memoryKilobytes()) . 'KB</dd></div>';
        }
        $poll = $result->isProcessing()
            ? '<form class="ojp-poll-status" data-oj-submission-poll'
                . ' data-endpoint="'
                . $this->html->escape($this->actionUrls->ajaxUrl()) . '"'
                . ' data-max-seconds="'
                . $this->settings->current()->maximumPollSeconds() . '">'
                . $this->security->hiddenFields(self::POLL_ACTION)
                . '<input type="hidden" name="submission_id" value="'
                . $submissionId . '"><div><strong>이 제출만 자동 갱신 중</strong>'
                . '<span data-oj-poll-copy>2초 뒤 결과를 다시 확인합니다.</span>'
                . '</div></form>'
            : '';
        $diagnostics = '';
        if (trim($detail->compileError()) !== '') {
            $diagnostics = '<section class="ojp-section"><h2>컴파일 메시지</h2>'
                . '<pre class="ojp-code-block"><code>'
                . $this->html->escape($detail->compileError())
                . '</code></pre></section>';
        }
        $diagnostics .= $this->html->judgeReport($detail->runtimeError(), false, $display);
        $extensionContent = '';
        if (! $this->extensions->isEmpty()) {
            $extensionSubmission = $this->aiFeedbackSubmissions->findForActor(
                $identity->userId(),
                $identity->userLogin(),
                $submissionId
            );
            if ($extensionSubmission !== null) {
                $extensionContent = $this->extensions->render(
                    $identity->userId(),
                    $identity->userLogin(),
                    $extensionSubmission
                );
            }
        }
        $content = '<div data-oj-submission-complete data-problem-id="'
            . $summary->problemId() . '"></div>'
            . '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">제출 #' . $submissionId . '</p>'
            . '<h1>문제 ' . $summary->problemId() . ' 채점 결과</h1>'
            . '<p>' . $this->html->escape($this->html->date($summary->submittedAt()))
            . ' 제출</p></div><div class="ojp-page-actions">'
            . $this->navigationActions($request, $summary->problemId())
            . '</div></header>'
            . '<section class="ojp-section"><div class="ojp-result-summary">'
            . '<div data-oj-result-badge>'
            . $this->html->resultBadge($summary->resultCode()) . '</div>'
            . '<dl class="ojp-description-grid" data-oj-result-metrics>'
            . $metrics . '</dl></div>' . $poll . '</section>'
            . '<section class="ojp-section"><div class="ojp-section-heading">'
            . '<div><h2>제출 코드</h2><p>사용자가 작성하거나 서버가 재구성한 코드입니다.</p>'
            . '</div></div><pre class="ojp-code-block"><code>'
            . $this->html->escape($sourceView->displaySource())
            . '</code></pre></section>'
            . $diagnostics . $extensionContent;

        return $this->pages->authenticated(
            '제출 #' . $submissionId,
            $content,
            $request
        );
    }

    private function navigationActions(
        HttpRequest $request,
        int $problemId
    ): string {
        $navigation = ProblemNavigationContext::fromQueryValues(
            $request->queryString('contest_id'),
            $request->queryString('contest_problem_number'),
            $request->queryString('class_id')
        );
        $groupId = max(0, (int) $request->queryString('group_id'));
        $inGroupContext = $groupId > 0
            && $navigation->contestId() === null
            && $navigation->classId() === null;

        if ($navigation->contestId() !== null) {
            $listUrl = '/contests/' . $navigation->contestId() . '/submissions';
        } elseif ($navigation->classId() !== null) {
            $listUrl = '/classes/' . $navigation->classId() . '/submissions';
        } elseif ($inGroupContext) {
            $listUrl = '/groups/' . $groupId . '/submissions';
        } else {
            $listUrl = '/submissions';
        }

        $actions = '';
        if ($navigation->contestId() !== null || $navigation->classId() !== null) {
            $actions .= '<a class="ojp-button" href="'
                . $this->html->escape($navigation->returnUrl()) . '">'
                . $this->html->escape($navigation->returnLabel()) . '</a>';
        } elseif ($inGroupContext) {
            $actions .= '<a class="ojp-button" href="/groups/' . $groupId . '">'
                . '그룹으로 돌아가기</a>';
        }
        $actions .= '<a class="ojp-button" href="'
            . $this->html->escape($listUrl) . '">내 제출</a>';

        $resubmitUrl = $navigation->submitUrl($problemId);
        if ($inGroupContext) {
            $resubmitUrl .= (str_contains($resubmitUrl, '?') ? '&' : '?')
                . 'group_id=' . $groupId;
        }
        $actions .= '<a class="ojp-button ojp-button--primary" href="'
            . $this->html->escape($resubmitUrl) . '">다시 제출</a>';

        return $actions;
    }

    private function notFound(HttpRequest $request): HttpResponse
    {
        return $this->pages->authenticated(
            '제출 상세',
            '<header class="ojp-page-header"><div><p class="ojp-eyebrow">제출</p>'
                . '<h1>제출 상세를 볼 수 없습니다.</h1>'
                . '<p>제출이 없거나 현재 정책에서 상세 조회가 제한되어 있습니다.</p>'
                . '</div></header><a class="ojp-button" href="/submissions">'
                . '내 제출로 돌아가기</a>',
            $request,
            404
        );
    }
}
