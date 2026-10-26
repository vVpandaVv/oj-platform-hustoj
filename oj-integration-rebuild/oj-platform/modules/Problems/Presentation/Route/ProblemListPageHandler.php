<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;

final class ProblemListPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly ProblemCatalogService $catalog,
        private readonly ProblemHtml $html,
        private readonly PaginationView $pagination
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $keyword = trim($request->queryString('q'));
        $page = max(1, (int) $request->queryString('page', '1'));
        $limit = 50;
        $offset = ($page - 1) * $limit;
        $problems = $this->catalog->publicPage($keyword, $limit, $offset);
        $total = $this->catalog->publicCount($keyword);
        $metadata = $this->catalog->metadataMany(array_map(
            static fn (JudgeProblem $problem): ProblemId => $problem->id(),
            $problems
        ));

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">문제</p>'
            . '<h1>문제 목록</h1><p>주제와 난이도를 확인하고 풀 문제를 선택합니다.</p></div></header>'
            . '<form class="ojp-filter-bar" method="get" action="/problems">'
            . '<div class="ojp-filter-bar__primary"><label class="ojp-field">'
            . '<span class="ojp-label">검색</span>'
            . '<input class="ojp-input" type="search" name="q" value="'
            . $this->html->escape($keyword)
            . '" placeholder="문제 번호 또는 제목"></label></div>'
            . '<button class="ojp-button" type="submit">검색</button></form>'
            . $this->table($problems, $metadata)
            . $this->pagination->links(
                $page,
                max(1, (int) ceil($total / $limit)),
                '/problems',
                $keyword === '' ? [] : ['q' => $keyword]
            );

        return $this->pages->authenticated('문제 목록', $content, $request);
    }

    /**
     * @param list<JudgeProblem> $problems
     * @param array<int, ProblemMetadata> $metadata
     */
    private function table(array $problems, array $metadata): string
    {
        $rows = '';
        foreach ($problems as $problem) {
            $problemId = $problem->id()->value();
            $rows .= '<tr><td class="ojp-cell-nowrap"><a class="ojp-row-link" href="/problems/'
                . $problemId . '">' . $problemId . '</a></td>'
                . '<td><span class="ojp-table-title"><a class="ojp-row-link" href="/problems/'
                . $problemId . '">' . $this->html->escape($problem->title()) . '</a>'
                . $this->html->specialJudgeBadge($problem->specialJudge())
                . '</span></td>'
                . '<td class="ojp-cell-nowrap">'
                . $this->html->difficulty($metadata[$problemId] ?? null) . '</td>'
                . '<td class="ojp-cell-topics">'
                . $this->html->topicBadges($metadata[$problemId] ?? null) . '</td>'
                . '<td class="ojp-cell-nowrap">' . number_format($problem->accepted()) . ' / '
                . number_format($problem->submitted())
                . '<span class="ojp-table-subvalue">' . $problem->passRate() . '%</span></td>'
                . '<td class="ojp-cell-nowrap"><a class="ojp-button ojp-button--small" href="/problems/'
                . $problemId . '">열기</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6">조건에 맞는 문제가 없습니다.</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table ojp-table--problems">'
            . '<thead><tr><th>번호</th><th>제목</th><th>난이도</th>'
            . '<th>주제</th><th>정답 / 제출</th><th>작업</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }

}
