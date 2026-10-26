<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;

final class ProblemRankingPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ProblemCatalogService $catalog,
        private readonly ProblemGateway $problems,
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
                '<div class="ojp-empty-state"><h1>순위를 볼 수 없습니다.</h1>'
                    . '<a class="ojp-button" href="/problems">문제 목록</a></div>',
                $request,
                404
            );
        }
        $rows = '';
        foreach ($this->problems->ranking($problemId) as $index => $entry) {
            $rows .= '<tr><td>' . ($index + 1) . '</td><td>'
                . $this->html->escape($entry->userKey()) . '</td><td>'
                . number_format($entry->attempts()) . '</td><td>'
                . $this->html->escape($this->html->date($entry->acceptedAt()))
                . '</td><td>'
                . number_format($entry->runtimeMilliseconds()) . ' ms</td><td>'
                . number_format($entry->memoryKilobytes()) . ' KB</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6">아직 맞힌 사용자가 없습니다.</td></tr>';
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">문제 '
            . $problemId->value() . '</p><h1>'
            . $this->html->escape($problem->title()) . ' 순위</h1></div>'
            . '<a class="ojp-button" href="/problems/' . $problemId->value()
            . '">문제로 돌아가기</a></header>'
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>순위</th><th>사용자</th><th>시도</th>'
            . '<th>정답 시각</th><th>실행 시간</th><th>메모리</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';

        return $this->pages->authenticated(
            $problem->title() . ' 순위',
            $content,
            $request
        );
    }
}
