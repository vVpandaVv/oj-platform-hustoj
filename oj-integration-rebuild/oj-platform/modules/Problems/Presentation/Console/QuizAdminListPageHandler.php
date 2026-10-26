<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizService;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizHtml;

final class QuizAdminListPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly QuizService $quizzes,
        private readonly QuizHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $page = max(1, min(100000, (int) $request->queryString('page', '1')));
        $result = $this->quizzes->overviewPage($userId, $page, true);
        $items = $result['items'];
        $rows = '';
        foreach ($items as $item) {
            $problem = $item['problem'];
            $overview = $item['quiz'];
            $draft = $overview->draft();
            $published = $overview->published();
            $problemId = $problem->id()->value();
            $state = $published === null
                ? '<span class="ojp-status-badge ojp-status-badge--warning">초안</span>'
                : '<span class="ojp-status-badge ojp-status-badge--success">게시 v'
                    . $published->revisionNumber() . '</span>';
            $rows .= '<tr><td>' . $problemId . '</td><td><strong>'
                . $this->html->escape(
                    $draft?->definition()->title()
                        ?? $published?->definition()->title()
                        ?? $problem->title()
                )
                . '</strong><span class="ojp-table-subvalue">'
                . $this->html->escape($problem->title()) . '</span></td><td>'
                . $state . '</td><td>'
                . ($draft?->definition()->questionCount()
                    ?? $published?->definition()->questionCount()
                    ?? 0)
                . '</td><td>' . number_format($overview->gradedAttempts())
                . '</td><td><div class="ojp-command-group">'
                . '<a class="ojp-button ojp-button--small" href="/oj-console/quizzes/'
                . $problemId . '/edit">편집</a>'
                . ($published === null
                    ? ''
                    : '<a class="ojp-button ojp-button--small"'
                        . ' href="/oj-console/quizzes/' . $problemId
                        . '/statistics">통계</a>')
                . '</div></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6">관리할 퀴즈가 없습니다.</td></tr>';
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '문제</p><h1>퀴즈 관리</h1><p>초안을 편집하고 새 불변 버전으로 게시합니다.</p>'
            . '</div><a class="ojp-button ojp-button--primary"'
            . ' href="/oj-console/quizzes/new">퀴즈 추가</a></header>'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>문제</th><th>퀴즈</th><th>상태</th><th>문항</th>'
            . '<th>완료 응시</th><th>작업</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>'
            . $this->html->pageLinks('/oj-console/quizzes', $page, $result['has_next']);

        return $this->pages->respond(
            '퀴즈 관리',
            $content,
            'problems.quiz',
            $request
        );
    }
}
