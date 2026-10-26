<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Ranking\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceRepository;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Modules\Ranking\Application\RankingService;
use CodingPanda\OjPlatform\Modules\Ranking\Domain\RankingEntry;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;

final class RankingPageHandler implements RouteHandler
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly UserPreferenceRepository $preferences,
        private readonly RankingService $ranking,
        private readonly RouteUrlProvider $urls,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly PaginationView $pagination
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            return HttpResponse::redirect('/login');
        }

        $query = trim($request->queryString('q', ''));
        try {
            $allEntries = $this->ranking->entries();
        } catch (\DomainException $error) {
            return $this->pages->authenticated('전체 랭킹',
                '<div class="ojp-notice ojp-notice--info" role="status"><p>' . $this->escape($error->getMessage())
                . '</p><a class="ojp-button" href="/ranking">다시 확인</a></div>', $request, 503);
        }
        $currentEntry = null;
        foreach ($allEntries as $entry) {
            if ($entry->userId() === $identity->userId()) {
                $currentEntry = $entry;
                break;
            }
        }
        $entries = array_values(array_filter(
            $allEntries,
            fn (RankingEntry $entry): bool =>
                $this->contains($entry->displayName(), $query)
        ));
        $page = max(1, (int) $request->queryString('page', '1'));
        $pageCount = max(1, (int) ceil(count($entries) / self::PAGE_SIZE));
        $page = min($page, $pageCount);

        $rows = '';
        foreach (array_slice(
            $entries,
            ($page - 1) * self::PAGE_SIZE,
            self::PAGE_SIZE
        ) as $entry) {
            $isCurrent = $entry->userId() === $identity->userId();
            $profile = $entry->isProfileVisible()
                ? '<a class="ojp-button ojp-button--small" href="'
                    . $this->escape($this->urls->path(
                        'auth.profile',
                        ['user_id' => $entry->userId()]
                    )) . '">보기</a>'
                : '<span class="ojp-muted">비공개</span>';
            $nameCell = $entry->isProfileVisible()
                ? '<a class="ojp-row-link" href="'
                    . $this->escape($this->urls->path(
                        'auth.profile',
                        ['user_id' => $entry->userId()]
                    )) . '">' . $this->escape($entry->displayName()) . '</a>'
                : $this->escape($entry->displayName());
            $rows .= '<tr' . ($isCurrent ? ' data-current-user="true"' : '')
                . '><td><strong>' . $entry->rank() . '</strong></td>'
                . '<td>' . $nameCell
                . ($isCurrent ? ' <span class="ojp-badge">나</span>' : '')
                . '</td><td>' . number_format($entry->solvedProblemCount())
                . '</td><td>' . number_format($entry->accuracyRate() * 100, 1) . '%'
                . ' <span class="ojp-muted">('
                . number_format($entry->acceptedSubmissionCount()) . '/'
                . number_format($entry->submissionCount()) . ')</span>'
                . '</td><td>' . $profile . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">조건에 맞는 공개 랭킹 기록이 없습니다.</td></tr>';
        }

        $preference = $this->preferences->find($identity->userId());
        $visibility = $preference?->rankingVisibility() ?? 'members';
        $currentRank = $visibility === 'hidden'
            ? '숨김'
            : ($currentEntry instanceof RankingEntry
                ? $currentEntry->rank() . '위'
                : '기록 없음');
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">내 정보 · 학습</p><h1>전체 랭킹</h1>'
            . '<p>공개된 상시 문제의 해결 수를 서비스 사용자끼리 참고합니다.</p>'
            . '</div><div class="ojp-page-actions"><a class="ojp-button"'
            . ' href="' . $this->escape($this->urls->path(
                'learning_dashboard.index'
            )) . '">내 학습</a></div></header>'
            . '<section class="ojp-section"><div class="ojp-section-heading">'
            . '<div><h2>집계 기준</h2><p>공개된 상시 문제의 유효한 채점 완료 제출만 집계합니다. '
            . '같은 문제의 여러 정답은 한 문제로 계산하며, 같은 해결 수는 공동 순위입니다. '
            . '정답률은 맞은 제출 ÷ 채점 완료 제출로 계산하는 참고 정보이며 순위에는 영향을 주지 않습니다.</p></div>'
            . '<span class="ojp-badge ojp-badge--info">내 현재 랭킹 '
            . $this->escape($currentRank) . '</span></div></section>'
            . $this->filter($query)
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>순위</th><th>사용자</th><th>해결한 문제</th>'
            . '<th>정답률 <span class="ojp-muted">(정답/제출)</span></th><th>공개 프로필</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . $this->pagination->links(
                $page,
                $pageCount,
                $this->urls->path('ranking.index'),
                $query === '' ? [] : ['q' => $query]
            )
            . $this->privacyForm($visibility);

        return $this->pages->authenticated('전체 랭킹', $content, $request);
    }

    private function filter(string $query): string
    {
        return '<form class="ojp-filter-bar" method="get" action="'
            . $this->escape($this->urls->path('ranking.index')) . '">'
            . '<div class="ojp-filter-bar__primary"><label class="ojp-field">'
            . '<span class="ojp-label">표시 이름</span><input class="ojp-input"'
            . ' type="search" name="q" value="' . $this->escape($query)
            . '" placeholder="이름 검색"></label></div>'
            . '<button class="ojp-button ojp-button--primary" type="submit">조회</button>'
            . '</form>';
    }

    private function privacyForm(string $visibility): string
    {
        return '<section class="ojp-section"><div class="ojp-section-heading">'
            . '<div><h2>내 랭킹 공개</h2><p>'
            . ($visibility === 'members'
                ? '전체 랭킹에 표시 이름으로 참여합니다.'
                : '내 기록을 전체 랭킹 목록에서 숨기고 있습니다.')
            . '</p></div></div><form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_user_settings')
            . '<input type="hidden" name="mode" value="ranking-visibility">'
            . '<label class="ojp-field"><span class="ojp-label">공개 범위</span>'
            . '<select class="ojp-select" name="ranking_visibility">'
            . '<option value="members"'
            . ($visibility === 'members' ? ' selected' : '')
            . '>로그인 사용자에게 공개</option><option value="hidden"'
            . ($visibility === 'hidden' ? ' selected' : '')
            . '>랭킹에서 숨김</option></select></label>'
            . '<div class="ojp-form-actions"><button class="ojp-button '
            . 'ojp-button--primary" type="submit">공개 설정 저장</button></div>'
            . '</form></section>';
    }

    private function contains(string $value, string $query): bool
    {
        if ($query === '') {
            return true;
        }
        if (function_exists('mb_stripos')) {
            return mb_stripos($value, $query, 0, 'UTF-8') !== false;
        }

        return stripos($value, $query) !== false;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
