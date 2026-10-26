<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;

final class ProblemStatisticsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ProblemCatalogService $catalog,
        private readonly ProblemGateway $problems,
        private readonly JudgeResults $results,
        private readonly ProblemHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $problemId = new ProblemId((int) ($route->parameters()['problem_id'] ?? 0));
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $problem = $this->catalog->findVisible($userId, $problemId);
        if ($problem === null) {
            return $this->pages->authenticated(
                '문제 없음',
                '<div class="ojp-empty-state"><h1>통계를 볼 수 없습니다.</h1>'
                    . '<a class="ojp-button" href="/problems">문제 목록</a></div>',
                $request,
                404
            );
        }
        $statistics = $this->problems->statistics($problemId);
        $rows = '';
        foreach ($statistics->resultCounts() as $resultCode => $count) {
            $result = $this->results->find($resultCode);
            $rows .= '<tr><td><span class="'
                . $this->html->escape($result->badgeClass('ojp-status-badge')) . '">'
                . $this->html->escape($result->label()) . '</span></td>'
                . '<td>' . $resultCode . '</td><td>' . number_format($count) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3">아직 제출 기록이 없습니다.</td></tr>';
        }
        $rate = $statistics->submitted() === 0
            ? 0
            : round(($statistics->accepted() / $statistics->submitted()) * 100, 1);
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">문제 '
            . $problemId->value() . '</p><h1>'
            . $this->html->escape($problem->title()) . ' 통계</h1></div>'
            . '<a class="ojp-button" href="/problems/' . $problemId->value()
            . '">문제로 돌아가기</a></header>'
            . '<div class="ojp-metric-strip"><div><strong>'
            . number_format($statistics->submitted()) . '</strong><span>전체 제출</span></div>'
            . '<div><strong>' . number_format($statistics->accepted())
            . '</strong><span>맞은 제출</span></div><div><strong>'
            . $rate . '%</strong><span>정답률</span></div></div>'
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>채점 결과</th><th>결과 코드</th><th>제출 수</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';

        return $this->pages->authenticated(
            $problem->title() . ' 통계',
            $content,
            $request
        );
    }
}
