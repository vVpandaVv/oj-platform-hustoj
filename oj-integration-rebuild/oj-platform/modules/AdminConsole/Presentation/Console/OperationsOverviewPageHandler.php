<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusReader;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;

final class OperationsOverviewPageHandler implements RouteHandler
{
    /**
     * @var array<string, string>
     */
    private const ROUTES = [
        'runtime' => '/oj-console/system-status',
        'judge_database' => '/oj-console/settings/database',
        'jobs' => '/oj-console/system-status',
        'ai' => '/oj-console/settings/ai-provider',
        'last_fatal' => '/oj-console/system-status',
    ];

    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly SystemStatusReader $status,
        private readonly CurrentAccountProvider $accounts,
        private readonly Authorization $permissions
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $actorUserId = $this->accounts->current()->identity()?->userId() ?? 0;
        $isSuperAdmin = $this->permissions->isSuperAdmin($actorUserId);
        $rows = '';
        foreach ($this->status->current() as $item) {
            if (
                ! $isSuperAdmin
                && ! $this->permissions->can($actorUserId, $item->capability())
            ) {
                continue;
            }
            $rows .= '<tr><td><strong>' . $this->escape($item->label())
                . '</strong></td><td><span class="ojp-badge ojp-badge--'
                . $this->escape($item->tone()) . '">'
                . $this->toneLabel($item->tone()) . '</span></td><td>'
                . $this->escape($item->summary()) . '</td><td>'
                . $this->escape($item->nextAction()) . '</td><td><a class="ojp-button" href="'
                . $this->escape(self::ROUTES[$item->key()] ?? '/oj-console/system-status')
                . '">열기</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">현재 권한으로 볼 수 있는 운영 영역이 없습니다.</td></tr>';
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">운영</p>'
            . '<h1>운영 개요</h1><p>설치 완료 뒤 연결·작업·오류 상태와 다음 조치를 한눈에 확인합니다.</p>'
            . '</div></header><section class="ojp-section"><div class="ojp-table-wrap">'
            . '<table class="ojp-table"><thead><tr><th>영역</th><th>상태</th><th>요약</th>'
            . '<th>다음 조치</th><th></th></tr></thead><tbody>'
            . $rows . '</tbody></table></div></section>';

        return $this->pages->respond(
            '운영 개요',
            $content,
            'operations',
            $request
        );
    }

    private function toneLabel(string $tone): string
    {
        return match ($tone) {
            'success' => '정상',
            'info' => '안내',
            'warning' => '확인 필요',
            default => '조치 필요',
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
