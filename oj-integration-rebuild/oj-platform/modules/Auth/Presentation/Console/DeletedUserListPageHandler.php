<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\DeletedUserRepository;
use CodingPanda\OjPlatform\Modules\Auth\Domain\DeletedUserRecord;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class DeletedUserListPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly DeletedUserRepository $deletedUsers,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);

        $records = $this->deletedUsers->findPage(50);
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">감사 기록</p>'
            . '<h1>삭제·익명화 기록</h1>'
            . '<p>자격 증명을 제외한 최소 완료 기록만 확인합니다.</p></div></header>'
            . '<div class="ojp-notice ojp-notice--warning"><strong>복원 기능 없음</strong>'
            . '<p>익명화가 완료된 계정은 이 화면에서 복원할 수 없습니다.</p></div>'
            . $this->table($records);

        return $this->pages->respond(
            '삭제·익명화 기록',
            $content,
            'users.deleted',
            $request
        );
    }

    /**
     * @param list<DeletedUserRecord> $records
     */
    private function table(array $records): string
    {
        $rows = '';
        foreach ($records as $record) {
            $job = $record->jobId() === null
                ? '완료'
                : '완료 JOB-' . $record->jobId();
            $rows .= '<tr><td>' . $record->userId() . '</td>'
                . '<td>' . $this->escape($record->anonymousLabel()) . '</td>'
                . '<td>' . $this->escape($this->dates->format($record->deletedAt())) . '</td>'
                . '<td>' . $record->deletedByUserId() . '</td>'
                . '<td>' . $this->escape($record->reason() ?? '-') . '</td>'
                . '<td><span class="ojp-status-badge ojp-status-badge--success">'
                . $this->escape($job) . '</span></td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6">삭제·익명화 완료 기록이 없습니다.</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>사용자 번호</th><th>최소 식별</th><th>처리 시각</th>'
            . '<th>처리자 번호</th><th>사유</th><th>작업</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
