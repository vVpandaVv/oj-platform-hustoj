<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNavigationContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDetailSection;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionWrapperCode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Ui\Asset\UiAssetLocator;
use CodingPanda\OjPlatform\Ui\Icon\IconName;
use CodingPanda\OjPlatform\Ui\Icon\IconRenderer;
use Throwable;

final class ProblemDetailPageView
{
    private ?IconRenderer $iconRenderer = null;

    public function __construct(
        private readonly ProblemHtml $html,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly UiAssetLocator $assets,
        private readonly ErrorReporter $errors
    ) {
    }

    /**
     * @param list<ProblemDetailSection> $detailSections
     */
    public function render(
        ProblemDetailPageState $state,
        array $detailSections,
        ?ProblemNavigationContext $navigation = null,
        ?string $submissionPanel = null
    ): string {
        $problem = $state->problem();
        $navigation ??= ProblemNavigationContext::general();

        return '<div class="ojp-problem-detail-page">'
            . $this->breadcrumb($problem, $navigation)
            . $this->header($state, $navigation)
            . '<div class="ojp-problem-detail-grid'
            . ($submissionPanel === null ? '' : ' ojp-problem-detail-grid--with-submission')
            . '">'
            . '<article class="ojp-problem-detail-content">'
            . $this->contentSection(
                '문제',
                $this->html->richText($problem->description()),
                'description'
            )
            . $this->contentSection(
                '입력',
                $this->html->richText($problem->input()),
                'input'
            )
            . $this->contentSection(
                '출력',
                $this->html->richText($problem->output()),
                'output'
            )
            . $this->codePolicy($problem)
            . $this->samples($problem)
            . $this->hint($problem)
            . ($problem->source() === ''
                ? ''
                : '<footer class="ojp-content-footer">출처: '
                    . $this->html->escape($problem->source()) . '</footer>')
            . $this->detailSections($detailSections)
            . '</article>'
            . $this->aside($problem, $navigation, $submissionPanel)
            . '</div></div>';
    }

    private function breadcrumb(
        JudgeProblem $problem,
        ProblemNavigationContext $navigation
    ): string
    {
        return '<nav class="ojp-breadcrumb" aria-label="현재 위치">'
            . '<a href="' . $this->html->escape($navigation->returnUrl()) . '">'
            . $this->html->escape($navigation->rootLabel()) . '</a>'
            . '<span aria-hidden="true">/</span>'
            . '<span>문제 ' . $problem->id()->value() . '</span></nav>';
    }

    private function header(
        ProblemDetailPageState $state,
        ProblemNavigationContext $navigation
    ): string
    {
        $problem = $state->problem();
        $problemId = $problem->id()->value();

        return '<header class="ojp-problem-detail-header">'
            . '<div class="ojp-problem-detail-header__main">'
            . '<div class="ojp-problem-detail-title">'
            . '<p class="ojp-eyebrow">문제 ' . $problemId . '</p>'
            . '<h1>' . $this->html->escape($problem->title()) . '</h1>'
            . '<div class="ojp-problem-runtime" aria-label="채점 제한">'
            . '<span>' . $this->icon(IconName::Clock)
            . '시간 제한 <strong>' . $problem->timeLimitSeconds() . '초</strong></span>'
            . '<span>' . $this->icon(IconName::Memory)
            . '메모리 제한 <strong>'
            . number_format($problem->memoryLimitMegabytes()) . 'MB</strong></span>'
            . '</div>'
            . '<div class="ojp-problem-detail-tags" aria-label="문제 정보">'
            . $this->html->specialJudgeBadge($problem->specialJudge())
            . $this->html->difficulty($state->metadata())
            . $this->html->topicBadges($state->metadata())
            . '</div></div>'
            . '<div class="ojp-problem-detail-actions">'
            . $this->personalBookAction($state)
            . '<a class="ojp-button" href="'
            . $this->html->escape($navigation->returnUrl()) . '">'
            . $this->icon(IconName::ArrowLeft)
            . $this->html->escape($navigation->returnLabel()) . '</a>'
            . '<a class="ojp-button ojp-button--primary" href="'
            . $this->html->escape($navigation->submitUrl($problemId)) . '">'
            . $this->icon(IconName::Send) . '제출하기</a>'
            . '</div></div>'
            . $this->adminTools($state)
            . '</header>';
    }

    private function adminTools(ProblemDetailPageState $state): string
    {
        if (! $state->canEdit() && ! $state->canManageTestData()) {
            return '';
        }
        $problemId = $state->problem()->id()->value();

        return '<div class="ojp-problem-admin-tools" aria-label="관리 작업">'
            . '<strong>관리 작업</strong>'
            . '<div class="ojp-command-group">'
            . ($state->canEdit()
                ? '<a class="ojp-button" href="/oj-console/problems/'
                    . $problemId . '/edit">'
                    . $this->icon(IconName::Edit) . '문제 수정</a>'
                : '')
            . ($state->canManageTestData()
                ? '<a class="ojp-button" href="/oj-console/problems/'
                    . $problemId . '/test-data">'
                    . $this->icon(IconName::Database)
                    . '테스트 데이터 수정</a>'
                : '')
            . '</div></div>';
    }

    private function personalBookAction(ProblemDetailPageState $state): string
    {
        $saved = $state->inPersonalBook();
        if ($saved === null) {
            return '<button class="ojp-icon-button" type="button" disabled '
                . 'aria-label="문제집 상태를 확인할 수 없습니다" '
                . 'title="문제집 상태를 확인할 수 없습니다">'
                . $this->icon(IconName::Bookmark) . '</button>';
        }

        $actionKey = $saved
            ? 'oj_problem_book_personal_remove'
            : 'oj_problem_book_personal_add';
        $label = $saved ? '내 문제집에서 제거' : '내 문제집에 추가';
        try {
            $hiddenFields = $this->security->hiddenFields($actionKey);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return '<button class="ojp-icon-button" type="button" disabled '
                . 'aria-label="문제집 작업을 사용할 수 없습니다" '
                . 'title="문제집 작업을 사용할 수 없습니다">'
                . $this->icon(IconName::Bookmark) . '</button>';
        }

        return '<form class="ojp-inline-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $hiddenFields
            . '<input type="hidden" name="problem_id" value="'
            . $state->problem()->id()->value() . '">'
            . '<button class="ojp-icon-button'
            . ($saved ? ' is-active' : '')
            . '" type="submit" aria-label="' . $label
            . '" title="' . $label . '" aria-pressed="'
            . ($saved ? 'true' : 'false') . '">'
            . $this->icon(IconName::Bookmark) . '</button></form>';
    }

    private function contentSection(string $title, string $body, string $key): string
    {
        $headingId = 'problem-' . $key . '-heading';

        return '<section class="ojp-content-section" aria-labelledby="'
            . $headingId . '"><h2 id="' . $headingId . '">'
            . $this->html->escape($title) . '</h2>' . $body . '</section>';
    }

    private function samples(JudgeProblem $problem): string
    {
        $id = $problem->id()->value();

        return '<section class="ojp-content-section" aria-labelledby="problem-samples-heading">'
            . '<h2 id="problem-samples-heading">예시</h2>'
            . '<div class="ojp-sample-grid">'
            . $this->sample('입력', $problem->sampleInput(), 'sample-input-' . $id)
            . $this->sample('출력', $problem->sampleOutput(), 'sample-output-' . $id)
            . '</div></section>';
    }

    private function sample(string $label, string $value, string $id): string
    {
        return '<div class="ojp-code-block ojp-code-sample ojp-math-ignore">'
            . '<div class="ojp-code-block__label"><span>'
            . $this->html->escape($label) . '</span>'
            . '<button class="ojp-icon-button ojp-icon-button--small" type="button" '
            . 'data-ojp-copy-target="' . $id . '" aria-label="'
            . $this->html->escape($label) . ' 예시 복사" title="'
            . $this->html->escape($label) . ' 예시 복사">'
            . $this->icon(IconName::Copy) . '</button></div>'
            . '<pre id="' . $id . '"><code>'
            . $this->html->escape($value) . '</code></pre></div>';
    }

    private function hint(JudgeProblem $problem): string
    {
        if ($problem->hint() === '') {
            return '';
        }

        return '<section class="ojp-content-section" aria-labelledby="problem-hint-heading">'
            . '<h2 id="problem-hint-heading">힌트</h2>'
            . '<details class="ojp-problem-hint"><summary>'
            . $this->icon(IconName::Lightbulb)
            . '풀이 방향 확인</summary>'
            . $this->html->richText($problem->hint())
            . '</details></section>';
    }

    private function codePolicy(JudgeProblem $problem): string
    {
        $words = $problem->forbiddenWords();
        if (
            $words === []
            && $problem->prefixCode() === ''
            && $problem->suffixCode() === ''
        ) {
            return '';
        }

        $forbidden = $words === []
            ? ''
            : '<h3>제출 금지어</h3><div class="ojp-inline-list" aria-label="제출 금지어">'
                . implode('', array_map(
                    fn (string $word): string => '<code class="ojp-code-chip">'
                        . $this->html->escape($word) . '</code>',
                    $words
                )) . '</div>';
        $wrapper = '';
        if ($problem->prefixCode() !== '' || $problem->suffixCode() !== '') {
            $visibleWrapper = '';
            foreach ([
                '앞에 붙는 코드' => $problem->prefixCode(),
                '뒤에 붙는 코드' => $problem->suffixCode(),
            ] as $label => $source) {
                if ($source === '') {
                    continue;
                }
                $code = new SubmissionWrapperCode($source);
                $visible = $code->visibleCode();
                $visibleWrapper .= $code->hasHiddenLines() && trim($visible) === ''
                    ? '<p class="ojp-muted">' . $label . ': 비공개 코드</p>'
                    : $this->html->code($visible, $label);
            }
            $wrapper = '<details class="ojp-problem-wrapper" open>'
                . '<summary>자동으로 붙는 앞·뒤 코드</summary>'
                . '<p class="ojp-muted">작성한 코드를 검사한 뒤 채점용 소스에 자동으로 붙입니다.</p>'
                . '<div class="ojp-code-policy-grid">'
                . $visibleWrapper
                . '</div></details>';
        }

        return '<section class="ojp-content-section" aria-labelledby="problem-code-policy-heading">'
            . '<h2 id="problem-code-policy-heading">제출 코드 규칙</h2>'
            . $forbidden . $wrapper . '</section>';
    }

    /**
     * @param list<ProblemDetailSection> $sections
     */
    private function detailSections(array $sections): string
    {
        $content = '';
        foreach ($sections as $section) {
            $items = '';
            foreach ($section->items() as $item) {
                $items .= '<li><a href="' . $this->html->escape($item->url()) . '">'
                    . '<strong>' . $this->html->escape($item->title()) . '</strong>'
                    . '<span>' . $this->html->escape($item->meta()) . '</span>'
                    . '<span class="ojp-status-badge ojp-status-badge--neutral">'
                    . $this->html->escape($item->status()) . '</span></a></li>';
            }
            if ($items === '') {
                $items = '<li class="ojp-problem-community__empty">'
                    . '아직 등록된 질문이 없습니다.</li>';
            }
            $content .= '<section class="ojp-problem-questions" data-section="'
                . $this->html->escape($section->key()) . '">'
                . '<div class="ojp-section-heading"><div><h2>'
                . $this->html->escape($section->title()) . '</h2></div>'
                . '<div class="ojp-command-group">'
                . '<a class="ojp-button" href="'
                . $this->html->escape($section->secondaryUrl()) . '">'
                . $this->icon(IconName::Rows)
                . $this->html->escape($section->secondaryLabel()) . '</a>'
                . '<a class="ojp-button" href="'
                . $this->html->escape($section->primaryUrl()) . '">'
                . $this->icon(IconName::Plus)
                . $this->html->escape($section->primaryLabel()) . '</a>'
                . '</div></div><ul class="ojp-problem-community">'
                . $items . '</ul></section>';
        }

        return $content;
    }

    private function aside(
        JudgeProblem $problem,
        ProblemNavigationContext $navigation,
        ?string $submissionPanel
    ): string {
        $problemId = $problem->id()->value();

        return '<aside class="ojp-problem-aside" aria-label="문제 요약">'
            . '<a class="ojp-button ojp-button--primary ojp-problem-aside-submit" href="'
            . $this->html->escape($navigation->submitUrl($problemId)) . '">'
            . $this->icon(IconName::Send) . '제출하기</a>'
            . ($submissionPanel === null
                ? ''
                : '<section class="ojp-problem-submission-panel">'
                    . $submissionPanel . '</section>')
            . '<section><h2>문제 정보</h2><dl class="ojp-problem-info-list">'
            . $this->infoRow('정답률', $problem->passRate() . '%')
            . $this->infoRow('제출', number_format($problem->submitted()) . '회')
            . $this->infoRow('정답', number_format($problem->accepted()) . '회')
            . '</dl></section>'
            . '<section><h2>내 상태</h2><div class="ojp-problem-status-line">'
            . $this->icon(IconName::Info)
            . '<span>제출 현황에서 내 코드의 채점 결과를 확인할 수 있습니다.</span>'
            . '</div></section>'
            . '<section><h2>더 보기</h2><nav class="ojp-problem-aside-links" '
            . 'aria-label="문제 관련 화면">'
            . $this->asideLink(
                '/problems/' . $problemId . '/statistics',
                IconName::Chart,
                '통계'
            )
            . $this->asideLink(
                '/problems/' . $problemId . '/ranking',
                IconName::Trophy,
                '순위'
            )
            . $this->asideLink(
                '/problems/' . $problemId . '/submissions',
                IconName::History,
                '제출 현황'
            )
            . '</nav></section></aside>';
    }

    private function infoRow(string $label, string $value): string
    {
        return '<div><dt>' . $this->html->escape($label) . '</dt><dd>'
            . $this->html->escape($value) . '</dd></div>';
    }

    private function asideLink(string $url, IconName $icon, string $label): string
    {
        return '<a href="' . $this->html->escape($url) . '">'
            . $this->icon($icon)
            . '<span>' . $this->html->escape($label) . '</span></a>';
    }

    private function icon(IconName $icon): string
    {
        $this->iconRenderer ??= new IconRenderer($this->assets->iconSpriteUrl());

        return $this->iconRenderer->render($icon);
    }
}
