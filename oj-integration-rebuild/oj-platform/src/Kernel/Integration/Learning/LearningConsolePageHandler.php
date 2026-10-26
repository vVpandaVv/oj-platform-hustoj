<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use Throwable;

final class LearningConsolePageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ClassService $classes,
        private readonly ContestService $contests,
        private readonly GroupService $groups,
        private readonly FeatureFlags $features,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $actorId = $this->accounts->current()->identity()?->userId() ?? 0;
        $rows = [
            [
                'classes',
                '수업',
                fn (): int => count($this->classes->managedBy($actorId)),
                '/oj-console/classes',
                '수업 목록',
            ],
            [
                'contests',
                '대회·평가',
                fn (): int => count($this->contests->managedBy($actorId)),
                '/oj-console/contests',
                '대회 목록',
            ],
            [
                'groups',
                '그룹',
                fn (): int => count($this->groups->managedBy($actorId)),
                '/oj-console/groups',
                '그룹 목록',
            ],
        ];
        $body = '';
        foreach ($rows as [$feature, $label, $loader, $path, $linkLabel]) {
            if (! $this->enabled($feature)) {
                continue;
            }
            $body .= '<tr><td><strong>' . $label . '</strong></td><td>'
                . $this->count($loader) . '</td><td><a class="ojp-button ojp-button--small"'
                . ' href="' . $path . '">' . $linkLabel . '</a></td></tr>';
        }
        if ($body === '') {
            $body = '<tr><td colspan="3">현재 사용할 수 있는 학습 공간 기능이 없습니다.</td></tr>';
        }
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">관리자</p><h1>학습 공간</h1>'
            . '<p>수업, 대회·평가, 그룹을 한 곳에서 관리합니다.</p>'
            . '</div></header><div class="ojp-table-wrap">'
            . '<table class="ojp-table"><thead><tr><th>기능</th>'
            . '<th>관리 항목</th><th>이동</th></tr></thead><tbody>'
            . $body . '</tbody></table></div>';

        return $this->pages->respond(
            '학습 공간',
            $content,
            'learning',
            $request
        );
    }

    private function enabled(string $feature): bool
    {
        try {
            return $this->features->isEnabled($feature);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return false;
        }
    }

    private function count(\Closure $loader): int|string
    {
        try {
            return $loader();
        } catch (Throwable $error) {
            $this->errors->report($error);

            return '일시적으로 불러올 수 없음';
        }
    }
}
