<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizBankService;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizHtml;

final class QuizBankListPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly QuizBankService $bank,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly QuizHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $keyword = trim($request->queryString('q'));
        $items = $this->bank->list($userId, $keyword);
        $typeLabels = [
            'single_choice' => '단일 선택',
            'multi_choice' => '다중 답',
            'short_answer' => '단답형',
        ];
        $rows = '';
        foreach ($items as $item) {
            $question = $item->question();
            $usage = $this->bank->usageCount($userId, $item->id());
            $rows .= '<tr><td><a class="ojp-row-link" href="/oj-console/quiz-bank/'
                . $item->id() . '/edit">'
                . $this->html->escape($question->title()) . '</a></td><td>'
                . $this->html->escape($typeLabels[$question->type()] ?? $question->type())
                . '</td><td>' . $this->html->score($question->points()) . '</td><td>'
                . number_format($usage) . '</td><td>'
                . '<div class="ojp-command-group">'
                . '<a class="ojp-button ojp-button--small" href="/oj-console/quiz-bank/'
                . $item->id() . '/edit">편집</a>'
                . '<form method="post" action="'
                . $this->html->escape($this->actionUrls->actionUrl()) . '">'
                . $this->security->hiddenFields('oj_quiz_bank_delete')
                . '<input type="hidden" name="bank_id" value="' . $item->id() . '">'
                . '<button class="ojp-button ojp-button--small ojp-button--danger"'
                . ' type="submit">삭제</button></form></div></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">등록된 문항이 없습니다.</td></tr>';
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '문제</p><h1>문항 은행</h1><p>여러 퀴즈에서 불러와 재사용할 수 있는 '
            . '문항을 관리합니다.</p></div><a class="ojp-button ojp-button--primary"'
            . ' href="/oj-console/quiz-bank/new">문항 추가</a></header>'
            . '<form class="ojp-filter-bar" method="get" action="/oj-console/quiz-bank">'
            . '<label class="ojp-field ojp-filter-bar__primary"><span class="ojp-label">'
            . '제목 검색</span><input class="ojp-input" type="search" name="q" value="'
            . $this->html->escape($keyword) . '"></label>'
            . '<button class="ojp-button" type="submit">조회</button></form>'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>제목</th><th>유형</th><th>배점</th><th>사용 중인 퀴즈</th>'
            . '<th>작업</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';

        return $this->pages->respond(
            '문항 은행',
            $content,
            'problems.quiz_bank',
            $request
        );
    }
}
