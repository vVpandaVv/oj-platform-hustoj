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
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;

final class ProblemAdminListPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ProblemCatalogService $catalog,
        private readonly ProblemAccessPolicy $access,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ProblemHtml $html,
        private readonly PaginationView $pagination
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $keyword = trim($request->queryString('q'));
        $visibility = match ($request->queryString('visibility')) {
            'published' => ProblemVisibility::Published,
            'archived' => ProblemVisibility::Archived,
            default => null,
        };
        $specialJudge = match ($request->queryString('judging')) {
            'special' => true,
            'standard' => false,
            default => null,
        };
        $page = max(1, (int) $request->queryString('page', '1'));
        $limit = 50;
        $problems = $this->catalog->managedPage(
            $userId,
            $keyword,
            $visibility,
            $limit,
            ($page - 1) * $limit,
            $specialJudge
        );
        $metadata = $this->catalog->metadataMany(array_map(
            static fn (JudgeProblem $problem): ProblemId => $problem->id(),
            $problems
        ));
        $total = $this->catalog->managedCount(
            $userId,
            $keyword,
            $visibility,
            $specialJudge
        );
        $canGenerateMetadata = false;
        foreach ($problems as $problem) {
            if ($this->access->canGenerateMetadataAi($userId, $problem->id())) {
                $canGenerateMetadata = true;
                break;
            }
        }

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">문제</p>'
            . '<h1>문제 관리</h1><p>담당 문제의 내용과 공개 상태를 관리합니다.</p></div>'
            . '<div class="ojp-command-group">'
            . ($canGenerateMetadata && $total > 0
                ? $this->filteredMetadataForm(
                    $keyword,
                    $visibility,
                    $specialJudge,
                    $total
                )
                : '')
            . ($this->access->canCreate($userId)
                ? '<a class="ojp-button ojp-button--primary" href="/oj-console/problems/new">문제 추가</a>'
                : '')
            . '</div></header>'
            . $this->filters($keyword, $visibility, $specialJudge)
            . '<div data-ojp-bulk-select>'
            . $this->bulkForm($canGenerateMetadata)
            . $this->table($problems, $metadata, $userId)
            . '</div>'
            . $this->pagination->links(
                $page,
                max(1, (int) ceil($total / $limit)),
                '/oj-console/problems',
                array_filter([
                    'q' => $keyword,
                    'visibility' => $visibility?->value ?? '',
                    'judging' => $this->judgingValue($specialJudge),
                ], static fn (string $value): bool => $value !== '')
            );

        return $this->pages->respond(
            '문제 관리',
            $content,
            'problems.index',
            $request
        );
    }

    private function filters(
        string $keyword,
        ?ProblemVisibility $visibility,
        ?bool $specialJudge
    ): string {
        $judging = $this->judgingValue($specialJudge);

        return '<form class="ojp-filter-bar" method="get" action="/oj-console/problems">'
            . '<div class="ojp-filter-bar__primary">'
            . '<label class="ojp-field"><span class="ojp-label">검색</span>'
            . '<input class="ojp-input" type="search" name="q" value="'
            . $this->html->escape($keyword) . '" placeholder="문제 번호 또는 제목"></label>'
            . '<label class="ojp-field"><span class="ojp-label">상태</span>'
            . '<select class="ojp-select" name="visibility">'
            . $this->option('', '전체', $visibility?->value ?? '')
            . $this->option('published', '공개', $visibility?->value ?? '')
            . $this->option('archived', '보관', $visibility?->value ?? '')
            . '</select></label>'
            . '<label class="ojp-field"><span class="ojp-label">채점 방식</span>'
            . '<select class="ojp-select" name="judging">'
            . $this->option('', '전체', $judging)
            . $this->option('standard', '일반 채점', $judging)
            . $this->option('special', '특수 채점', $judging)
            . '</select></label></div>'
            . '<button class="ojp-button" type="submit">적용</button></form>';
    }

    private function bulkForm(bool $canGenerateMetadata): string
    {
        return '<form id="ojp-problem-bulk-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_problem_bulk_visibility')
            . '<input type="hidden" name="archived" value="1"></form>'
            . ($canGenerateMetadata
                ? '<form id="ojp-problem-metadata-form" method="post" action="'
                    . $this->html->escape($this->actionUrls->actionUrl())
                    . '" data-ojp-bulk-copy>'
                    . $this->security->hiddenFields('oj_problem_generate_metadata')
                    . '</form>'
                : '')
            . '<div class="ojp-bulk-bar" data-ojp-bulk-bar hidden>'
            . '<span><strong data-ojp-selected-count>0</strong>개 선택</span>'
            . ($canGenerateMetadata
                ? '<button class="ojp-button" type="submit"'
                    . ' form="ojp-problem-metadata-form">메타데이터 생성</button>'
                : '')
            . '<button class="ojp-button" type="submit" form="ojp-problem-bulk-form">'
            . '선택 문제 보관</button></div>';
    }

    /**
     * @param list<JudgeProblem> $problems
     * @param array<int, ProblemMetadata> $metadata
     */
    private function table(array $problems, array $metadata, int $userId): string
    {
        $rows = '';
        foreach ($problems as $problem) {
            $id = $problem->id()->value();
            $canArchive = $this->access->canArchive($userId, $problem->id());
            $canEdit = $this->access->canEdit($userId, $problem->id());
            $canGenerateMetadata = $this->access->canGenerateMetadataAi(
                $userId,
                $problem->id()
            );
            $rows .= '<tr><td><input type="checkbox" name="problem_ids[]" value="'
                . $id . '" form="ojp-problem-bulk-form" data-ojp-select-item'
                . ($canArchive ? '' : ' disabled')
                . ' aria-label="' . $id . '번 선택"></td>'
                . '<td class="ojp-cell-nowrap">' . $id . '</td><td><span class="ojp-table-title">'
                . ($canEdit
                    ? '<a class="ojp-row-link" href="/oj-console/problems/'
                        . $id . '/edit">' . $this->html->escape($problem->title()) . '</a>'
                    : $this->html->escape($problem->title()))
                . $this->html->specialJudgeBadge($problem->specialJudge())
                . '</span></td>'
                . '<td class="ojp-cell-nowrap">'
                . $this->html->difficulty($metadata[$id] ?? null) . '</td>'
                . '<td class="ojp-cell-topics">'
                . $this->html->topicBadges($metadata[$id] ?? null) . '</td>'
                . '<td class="ojp-cell-nowrap"><span class="ojp-status-badge ojp-status-badge--'
                . ($problem->visibility() === ProblemVisibility::Published ? 'success' : 'neutral')
                . '">' . ($problem->visibility() === ProblemVisibility::Published ? '공개' : '보관')
                . '</span></td><td><div class="ojp-command-group">'
                . '<a class="ojp-button ojp-button--small" href="/problems/' . $id
                . '">보기</a>'
                . ($canEdit
                    ? '<a class="ojp-button ojp-button--small" href="/oj-console/problems/'
                        . $id . '/edit">수정</a>'
                    : '')
                . ($this->access->canManageTestData($userId, $problem->id())
                    ? '<a class="ojp-button ojp-button--small" href="/oj-console/problems/'
                        . $id . '/test-data">테스트 데이터</a>'
                    : '')
                . ($canGenerateMetadata
                    ? '<form class="ojp-inline-form" method="post" action="'
                        . $this->html->escape($this->actionUrls->actionUrl()) . '">'
                        . $this->security->hiddenFields('oj_problem_generate_metadata')
                        . '<input type="hidden" name="problem_ids[]" value="' . $id . '">'
                        . '<button class="ojp-button ojp-button--small" type="submit">'
                        . '메타데이터 생성</button></form>'
                    : '')
                . ($canArchive
                    ? '<a class="ojp-button ojp-button--small" href="/oj-console/problems/'
                        . $id . '/delete">'
                        . ($problem->visibility() === ProblemVisibility::Published ? '보관' : '복원')
                        . '</a>'
                    : '')
                . '</div></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7">관리할 수 있는 문제가 없습니다.</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table ojp-table--problems">'
            . '<thead><tr><th><input type="checkbox" data-ojp-select-all aria-label="전체 선택"></th>'
            . '<th>번호</th><th>제목</th><th>난이도</th><th>주제</th><th>상태</th><th>작업</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function judgingValue(?bool $specialJudge): string
    {
        return match ($specialJudge) {
            true => 'special',
            false => 'standard',
            null => '',
        };
    }

    private function option(string $value, string $label, string $current): string
    {
        return '<option value="' . $value . '"'
            . ($value === $current ? ' selected' : '') . '>'
            . $this->html->escape($label) . '</option>';
    }

    private function filteredMetadataForm(
        string $keyword,
        ?ProblemVisibility $visibility,
        ?bool $specialJudge,
        int $total
    ): string {
        $judging = $this->judgingValue($specialJudge);

        return '<form class="ojp-inline-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_problem_generate_metadata_filtered')
            . '<input type="hidden" name="q" value="' . $this->html->escape($keyword) . '">'
            . '<input type="hidden" name="visibility" value="'
            . $this->html->escape($visibility?->value ?? '') . '">'
            . '<input type="hidden" name="judging" value="'
            . $this->html->escape($judging) . '">'
            . '<button class="ojp-button" type="submit">검색 결과 '
            . number_format($total) . '개 메타데이터 생성</button></form>';
    }
}
