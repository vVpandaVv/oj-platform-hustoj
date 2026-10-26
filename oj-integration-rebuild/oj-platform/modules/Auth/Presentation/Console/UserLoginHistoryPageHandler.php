<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserDirectoryService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserLoginHistoryRepository;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserLoginRecord;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class UserLoginHistoryPageHandler implements RouteHandler
{
    private const PAGE_SIZE = 30;

    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly UserDirectoryService $directory,
        private readonly UserLoginHistoryRepository $loginHistory,
        private readonly PaginationView $pagination,
        private readonly SiteDateFormatter $dates
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $userId = $this->userId($route);
        $managed = $this->directory->find($userId);
        if ($managed === null) {
            return $this->pages->respond(
                '사용자 없음',
                '<div class="ojp-empty-state"><p>사용자를 찾을 수 없습니다.</p></div>',
                'users.index',
                $request,
                404
            );
        }

        $total = $this->loginHistory->countForUser($userId);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, (int) $request->queryString('page', '1')), $pageCount);
        $records = $this->loginHistory->findForUser(
            $userId,
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE
        );

        $identity = $managed->identity();
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">사용자</p>'
            . '<h1>로그인 기록</h1><p>'
            . $this->escape($identity->displayName()) . ' (@' . $this->escape($identity->userLogin()) . ')'
            . ' · 누적 ' . $total . '건</p></div>'
            . '<div class="ojp-command-group">'
            . '<a class="ojp-button" href="/oj-console/users/' . $userId . '">사용자 상세로 이동</a>'
            . '</div></header>'
            . $this->table($records)
            . $this->pagination->links(
                $page,
                $pageCount,
                '/oj-console/users/' . $userId . '/login-history'
            );

        return $this->pages->respond(
            '로그인 기록',
            $content,
            'users.index',
            $request
        );
    }

    /**
     * @param list<UserLoginRecord> $records
     */
    private function table(array $records): string
    {
        $rows = '';
        foreach ($records as $record) {
            $rows .= '<tr>'
                . '<td>' . $this->escape($this->dates->format($record->loggedInAt())) . '</td>'
                . '<td>' . $this->escape($record->ipAddress() ?? '-') . '</td>'
                . '<td>' . $this->escape($record->userAgent() ?? '-') . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3">로그인 기록이 없습니다.</td></tr>';
        }

        return '<section class="ojp-section"><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>로그인 시각</th><th>IP 주소</th><th>기기·브라우저</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></section>';
    }

    private function userId(RouteMatch $route): int
    {
        $value = $route->parameters()['user_id'] ?? '';

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : 0;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
