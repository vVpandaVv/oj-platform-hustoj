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
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionLanguageCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionManagedScopeProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionHtml;

final class SubmissionAdminListPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly Authorization $permissions,
        private readonly SubmissionQueryService $submissions,
        private readonly SubmissionSettingsProvider $settings,
        private readonly SubmissionLanguageCatalog $languages,
        private readonly JudgeResults $results,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly SubmissionHtml $html,
        private readonly SubmissionManagedScopeProvider $managedScope,
        private readonly string $title,
        private readonly string $currentMenu,
        private readonly ?bool $validFilter
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            return HttpResponse::redirect('/login');
        }
        $page = max(1, (int) $request->queryString('page', '1'));
        $problemId = $this->optionalInt($request->queryString('problem_id'));
        $resultCode = $this->optionalInt($request->queryString('result'));
        $languageId = $this->optionalInt($request->queryString('language'));
        $keyword = trim($request->queryString('q'));
        $pageResult = $this->submissions->managedPage(
            $identity->userId(),
            $identity->userLogin(),
            $problemId,
            $resultCode,
            $languageId,
            $this->validFilter,
            $keyword,
            $page,
            $this->managedScope->scopeFor($identity->userId())
        );
        $canRejudge = $this->permissions->can(
            $identity->userId(),
            'oj_rejudge_submissions'
        );
        $canInvalidate = $this->permissions->can(
            $identity->userId(),
            'oj_delete_submissions'
        );
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">제출 관리</p><h1>'
            . $this->html->escape($this->title) . '</h1><p>'
            . ($this->validFilter === false
                ? '무효 처리된 제출 원본을 유지한 채 필요한 항목을 복원합니다.'
                : '채점 결과를 조회하고 선택한 제출을 일괄 처리합니다.')
            . '</p></div></header>'
            . $this->filters(
                $keyword,
                $problemId,
                $resultCode,
                $languageId
            )
            . '<section class="ojp-section">'
            . $this->html->table(
                $pageResult['rows'],
                true,
                $canRejudge || $canInvalidate,
                true,
                $this->settings->display()
            )
            . $this->bulkActions(
                $canRejudge,
                $canInvalidate,
                $this->validFilter === false
            )
            . $this->html->pagination(
                $pageResult['page'],
                $pageResult['total'],
                $pageResult['page_size'],
                $request->path(),
                array_filter([
                    'q' => $keyword,
                    'problem_id' => $problemId,
                    'result' => $resultCode,
                    'language' => $languageId,
                ], static fn (mixed $value): bool => $value !== null && $value !== '')
            )
            . '</section>';

        return $this->pages->respond(
            $this->title,
            $content,
            $this->currentMenu,
            $request
        );
    }

    private function filters(
        string $keyword,
        ?int $problemId,
        ?int $resultCode,
        ?int $languageId
    ): string {
        $resultOptions = '<option value="">모든 결과</option>';
        foreach ($this->results->all() as $result) {
            $resultOptions .= '<option value="' . $result->code() . '"'
                . ($resultCode === $result->code() ? ' selected' : '')
                . '>' . $this->html->escape($result->label()) . '</option>';
        }
        $languageOptions = '<option value="">모든 언어</option>';
        foreach ($this->languages->all() as $id => $label) {
            $languageOptions .= '<option value="' . $id . '"'
                . ($languageId === $id ? ' selected' : '') . '>'
                . $this->html->escape($label) . '</option>';
        }

        return '<form class="ojp-filter-bar" method="get" action="">'
            . '<div class="ojp-filter-bar__primary"><label class="ojp-field">'
            . '<span class="ojp-label">검색</span><input class="ojp-input"'
            . ' type="search" name="q" value="' . $this->html->escape($keyword)
            . '" placeholder="제출 번호, 문제 번호, 사용자"></label></div>'
            . '<label class="ojp-field"><span class="ojp-label">문제 번호</span>'
            . '<input class="ojp-input" type="number" min="1" name="problem_id" value="'
            . ($problemId ?? '') . '"></label>'
            . '<label class="ojp-field"><span class="ojp-label">결과</span>'
            . '<select class="ojp-select" name="result">' . $resultOptions
            . '</select></label><label class="ojp-field">'
            . '<span class="ojp-label">언어</span><select class="ojp-select"'
            . ' name="language">' . $languageOptions . '</select></label>'
            . '<button class="ojp-button" type="submit">조회</button></form>';
    }

    private function bulkActions(
        bool $canRejudge,
        bool $canInvalidate,
        bool $deleted
    ): string {
        $forms = '';
        if ($canRejudge && ! $deleted) {
            $forms .= $this->operationForm(
                'oj_submission_rejudge',
                '선택 재채점',
                '재채점 사유',
                'ojp-button--primary'
            );
        }
        if ($canInvalidate) {
            $forms .= $this->operationForm(
                $deleted ? 'oj_submission_restore' : 'oj_submission_delete',
                $deleted ? '선택 복원' : '선택 무효 처리',
                $deleted ? '복원 사유' : '무효 처리 사유',
                $deleted ? 'ojp-button--primary' : 'ojp-button--danger'
            );
        }

        return $forms === ''
            ? ''
            : '<div class="ojp-bulk-actions" data-oj-submission-bulk>'
                . $forms . '</div>';
    }

    private function operationForm(
        string $action,
        string $button,
        string $placeholder,
        string $buttonClass
    ): string {
        return '<form class="ojp-inline-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl())
            . '" data-oj-bulk-operation>'
            . $this->security->hiddenFields($action)
            . '<input type="hidden" name="request_key" value="'
            . bin2hex(random_bytes(32)) . '">'
            . '<span data-oj-selected-submissions></span>'
            . '<label class="ojp-field"><span class="ojp-sr-only">'
            . $placeholder . '</span><input class="ojp-input" type="text"'
            . ' name="reason" minlength="3" maxlength="500" required'
            . ' placeholder="' . $placeholder . '"></label>'
            . '<button class="ojp-button ' . $buttonClass
            . '" type="submit">' . $button . '</button></form>';
    }

    private function optionalInt(string $value): ?int
    {
        return preg_match('/^\d+$/', $value) === 1
            ? (int) $value
            : null;
    }
}
