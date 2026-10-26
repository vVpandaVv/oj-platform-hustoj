<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemBookService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;

final class ProblemBookPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ProblemBookService $books,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ProblemHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $view = $this->books->publicView($userId);
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '문제</p><h1>문제집</h1><p>공용 문제 묶음을 살펴보고 저장한 문제를 이어서 풉니다.</p>'
            . '</div></header>'
            . '<section class="ojp-content-section"><div class="ojp-section-heading">'
            . '<div><h2>내 문제집</h2><p>문제 상세에서 직접 저장한 문제입니다.</p></div>'
            . '<span class="ojp-status-badge ojp-status-badge--neutral">'
            . count($view['personal']) . '개</span></div>'
            . $this->personalTable($view['personal']) . '</section>'
            . '<section class="ojp-content-section"><div class="ojp-section-heading">'
            . '<div><h2>공용 문제집</h2><p>관리자가 구성한 학습 순서입니다.</p></div>'
            . '<span class="ojp-status-badge ojp-status-badge--neutral">'
            . count($view['public']) . '개</span></div>'
            . $this->publicBooks($view['public']) . '</section>';

        return $this->pages->authenticated('문제집', $content, $request);
    }

    /**
     * @param list<array{
     *     item:\CodingPanda\OjPlatform\Modules\Problems\Domain\PersonalProblemBookItem,
     *     problem:JudgeProblem
     * }> $items
     */
    private function personalTable(array $items): string
    {
        $rows = '';
        foreach ($items as $item) {
            $problem = $item['problem'];
            $problemId = $problem->id()->value();
            $rows .= '<tr><td>' . $problemId . '</td><td><a class="ojp-row-link"'
                . ' href="/problems/' . $problemId . '">'
                . $this->html->escape($problem->title()) . '</a>'
                . ($item['item']->note() === ''
                    ? ''
                    : '<span class="ojp-table-subvalue">'
                        . $this->html->escape($item['item']->note()) . '</span>')
                . '</td><td>' . $this->html->escape(
                    $this->html->date($item['item']->createdAt())
                )
                . '</td><td><div class="ojp-command-group">'
                . '<a class="ojp-button ojp-button--small" href="/problems/'
                . $problemId . '">열기</a>'
                . '<form method="post" action="'
                . $this->html->escape($this->actionUrls->actionUrl()) . '">'
                . $this->security->hiddenFields('oj_problem_book_personal_remove')
                . '<input type="hidden" name="problem_id" value="' . $problemId . '">'
                . '<button class="ojp-button ojp-button--small ojp-button--danger"'
                . ' type="submit">제거</button></form></div></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">개인 문제집에 저장한 문제가 없습니다.</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>번호</th><th>문제</th><th>저장 시각</th><th>작업</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }

    /**
     * @param list<array{
     *     book:\CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemBook,
     *     problems:list<JudgeProblem>
     * }> $items
     */
    private function publicBooks(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            $book = $item['book'];
            $rows = '';
            foreach ($item['problems'] as $index => $problem) {
                $problemId = $problem->id()->value();
                $rows .= '<tr><td>' . ($index + 1) . '</td><td>'
                    . $problemId . '</td><td><a class="ojp-row-link" href="/problems/'
                    . $problemId . '">' . $this->html->escape($problem->title())
                    . '</a></td><td><a class="ojp-button ojp-button--small"'
                    . ' href="/problems/' . $problemId . '">열기</a></td></tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="4">현재 열 수 있는 문제가 없습니다.</td></tr>';
            }
            $html .= '<section class="ojp-problem-book-section" id="book-'
                . $book->id() . '"><div class="ojp-section-heading"><div><h3>'
                . $this->html->escape($book->name()) . '</h3><p>'
                . $this->html->escape($book->description()) . '</p></div>'
                . '<span class="ojp-status-badge ojp-status-badge--neutral">'
                . count($item['problems']) . '문제</span></div>'
                . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
                . '<th>순서</th><th>번호</th><th>문제</th><th>작업</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table></div></section>';
        }
        if ($html === '') {
            return '<div class="ojp-empty-state"><h3>공개된 공용 문제집이 없습니다.</h3></div>';
        }

        return '<div class="ojp-problem-book-list">' . $html . '</div>';
    }
}
