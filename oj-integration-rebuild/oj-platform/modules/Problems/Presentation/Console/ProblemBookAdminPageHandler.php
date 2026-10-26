<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemBookService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemBook;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemBookVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use DomainException;

final class ProblemBookAdminPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
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
        $selectedId = max(0, (int) $request->queryString('book_id'));
        try {
            $view = $this->books->managedView(
                $userId,
                $selectedId > 0 ? $selectedId : null
            );
        } catch (DomainException $error) {
            return $this->pages->respond(
                '문제집 관리',
                '<div class="ojp-empty-state"><h1>문제집을 관리할 수 없습니다.</h1>'
                    . '<p>' . $this->html->escape($error->getMessage()) . '</p></div>',
                'problems.books',
                $request,
                403
            );
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '문제</p><h1>문제집 관리</h1><p>공용 문제집만 관리하며 개인 문제집은 조회하지 않습니다.</p>'
            . '</div><a class="ojp-button ojp-button--primary"'
            . ' href="/oj-console/problem-books">문제집 추가</a></header>'
            . '<div class="ojp-split-layout"><section class="ojp-form-section">'
            . '<div class="ojp-section-heading"><div><h2>공용 문제집</h2><p>'
            . count($view['books']) . '개</p></div></div>'
            . $this->bookTable($view['books'], $view['selected'])
            . '</section><section class="ojp-form-section">'
            . '<h2>' . ($view['selected'] === null ? '새 문제집' : '문제집 편집')
            . '</h2>' . $this->editForm(
                $view['books'],
                $view['selected'],
                $view['problems']
            ) . '</section></div>';

        return $this->pages->respond(
            '문제집 관리',
            $content,
            'problems.books',
            $request
        );
    }

    /**
     * @param list<ProblemBook> $books
     */
    private function bookTable(array $books, ?ProblemBook $selected): string
    {
        $rows = '';
        foreach ($books as $book) {
            $rows .= '<tr' . ($selected?->id() === $book->id() ? ' class="is-selected"' : '')
                . '><td><a class="ojp-row-link" href="/oj-console/problem-books?book_id='
                . $book->id() . '">' . $this->html->escape($book->name())
                . '</a><span class="ojp-table-subvalue">'
                . $this->html->escape($book->slug()) . '</span></td><td>'
                . ($book->visibility() === ProblemBookVisibility::Members
                    ? '전체 사용자'
                    : '비공개')
                . '</td><td>' . count($book->problemIds()) . '</td><td>'
                . '<a class="ojp-button ojp-button--small"'
                . ' href="/oj-console/problem-books?book_id=' . $book->id()
                . '">편집</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">공용 문제집이 없습니다.</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>이름</th><th>공개</th><th>문제</th><th>작업</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }

    /**
     * @param list<ProblemBook> $books
     * @param list<JudgeProblem> $problems
     */
    private function editForm(
        array $books,
        ?ProblemBook $selected,
        array $problems
    ): string {
        $selectedProblemIds = array_map(
            static fn ($problemId): int => $problemId->value(),
            $selected?->problemIds() ?? []
        );
        $parentOptions = '<option value="">상위 없음</option>';
        foreach ($books as $book) {
            if ($book->parentId() !== null || $book->id() === $selected?->id()) {
                continue;
            }
            $parentOptions .= '<option value="' . $book->id() . '"'
                . ($book->id() === $selected?->parentId() ? ' selected' : '')
                . '>' . $this->html->escape($book->name()) . '</option>';
        }

        return '<form class="ojp-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_problem_book_save_category')
            . '<input type="hidden" name="book_id" value="'
            . ($selected?->id() ?? 0) . '"><div class="ojp-form-grid">'
            . $this->textField('name', '이름', $selected?->name() ?? '', true)
            . $this->textField('slug', '영문 주소 키', $selected?->slug() ?? '', false)
            . '<label class="ojp-field"><span class="ojp-label">상위 문제집</span>'
            . '<select class="ojp-select" name="parent_id">' . $parentOptions
            . '</select></label>'
            . '<label class="ojp-field"><span class="ojp-label">공개 범위</span>'
            . '<select class="ojp-select" name="visibility">'
            . '<option value="members"'
            . ($selected?->visibility() !== ProblemBookVisibility::Private
                ? ' selected'
                : '')
            . '>전체 사용자</option><option value="private"'
            . ($selected?->visibility() === ProblemBookVisibility::Private
                ? ' selected'
                : '')
            . '>비공개</option></select></label>'
            . '<label class="ojp-field"><span class="ojp-label">정렬 순서</span>'
            . '<input class="ojp-input" type="number" min="0" name="sort_order" value="'
            . ($selected?->sortOrder() ?? 0) . '"></label>'
            . '<label class="ojp-field ojp-field--wide"><span class="ojp-label">설명</span>'
            . '<textarea class="ojp-textarea" name="description" rows="3">'
            . $this->html->escape($selected?->description() ?? '')
            . '</textarea></label></div>'
            . '<div class="ojp-section-heading ojp-section-heading--compact"><div>'
            . '<h3>문제 구성</h3><p>표시 순서는 문제 번호 오름차순입니다.</p></div></div>'
            . '<label class="ojp-field"><span class="ojp-label">문제 검색</span>'
            . '<input class="ojp-input" type="search" placeholder="번호 또는 제목"'
            . ' data-oj-problem-book-filter></label>'
            . '<div class="ojp-problem-picker" data-oj-problem-book-picker>'
            . $this->problemOptions($problems, $selectedProblemIds)
            . '</div><div class="ojp-command-group">'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . ($selected === null ? '문제집 추가' : '변경 저장') . '</button></div></form>'
            . ($selected === null
                ? ''
                : '<form method="post" action="'
                    . $this->html->escape($this->actionUrls->actionUrl()) . '">'
                    . $this->security->hiddenFields('oj_problem_book_delete_category')
                    . '<input type="hidden" name="book_id" value="' . $selected->id()
                    . '"><button class="ojp-button ojp-button--danger" type="submit">'
                    . '문제집 삭제</button></form>');
    }

    /**
     * @param list<JudgeProblem> $problems
     * @param list<int> $selectedIds
     */
    private function problemOptions(array $problems, array $selectedIds): string
    {
        $html = '';
        foreach ($problems as $problem) {
            $id = $problem->id()->value();
            $search = strtolower($id . ' ' . $problem->title());
            $html .= '<label class="ojp-problem-picker__item" data-oj-problem-book-problem="'
                . $this->html->escape($search) . '"><input type="checkbox"'
                . ' name="problem_ids[]" value="' . $id . '"'
                . (in_array($id, $selectedIds, true) ? ' checked' : '')
                . '><span><strong>' . $id . '</strong> '
                . $this->html->escape($problem->title()) . '</span></label>';
        }
        if ($html === '') {
            return '<p class="ojp-muted">추가할 수 있는 문제가 없습니다.</p>';
        }

        return $html;
    }

    private function textField(
        string $name,
        string $label,
        string $value,
        bool $wide
    ): string {
        return '<label class="ojp-field' . ($wide ? ' ojp-field--wide' : '')
            . '"><span class="ojp-label">' . $this->html->escape($label)
            . '</span><input class="ojp-input" type="text" name="'
            . $this->html->escape($name) . '" value="'
            . $this->html->escape($value) . '" required></label>';
    }
}
