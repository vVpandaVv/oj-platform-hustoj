<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizService;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizHtml;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;

final class QuizListPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
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
        $result = $this->quizzes->overviewPage($userId, $page);
        $items = $result['items'];
        $rows = '';
        foreach ($items as $item) {
            $problem = $item['problem'];
            $overview = $item['quiz'];
            $revision = $overview->published();
            if ($revision === null) {
                continue;
            }
            $problemId = $problem->id()->value();
            $rows .= '<tr><td><a class="ojp-row-link" href="/quizzes/'
                . $problemId . '">' . $problemId . '</a></td>'
                . '<td><a class="ojp-row-link" href="/quizzes/' . $problemId
                . '">' . $this->html->escape($revision->definition()->title())
                . '</a><span class="ojp-table-subvalue">'
                . $this->html->escape($problem->title()) . '</span></td>'
                . '<td>' . $revision->definition()->questionCount() . '</td>'
                . '<td>' . $revision->definition()->attemptLimit() . '회</td>'
                . '<td>' . number_format($overview->gradedAttempts()) . '</td>'
                . '<td>' . $this->html->score($overview->averageScore()) . '점</td>'
                . '<td><a class="ojp-button ojp-button--small" href="/quizzes/'
                . $problemId . '">열기</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7">현재 응시할 수 있는 퀴즈가 없습니다.</td></tr>';
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '문제</p><h1>퀴즈</h1><p>문제의 핵심 개념을 확인하고 학습 결과를 살펴봅니다.</p>'
            . '</div><span class="ojp-status-badge ojp-status-badge--neutral">'
            . count($items) . '개</span></header>'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>문제</th><th>퀴즈</th><th>문항</th><th>응시 제한</th>'
            . '<th>완료</th><th>평균</th><th>작업</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . $this->html->pageLinks('/quizzes', $page, $result['has_next']);

        return $this->pages->authenticated('퀴즈', $content, $request);
    }
}
