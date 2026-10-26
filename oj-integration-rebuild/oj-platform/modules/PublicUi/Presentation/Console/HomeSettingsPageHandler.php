<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\HomeSettingsService;

final class HomeSettingsPageHandler implements RouteHandler
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'continue_learning' => '이어서 하기',
        'learning_spaces' => '학습 공간',
        'recent_submissions' => '최근 제출',
        'notices' => '공지',
        'staff_shortcuts' => '운영 바로가기',
    ];

    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly HomeSettingsService $settings,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $snapshot = $this->settings->snapshot();
        $rows = '';
        foreach ($this->settings->blocks() as $index => $block) {
            $prefix = 'blocks[' . $index . ']';
            $rows .= '<tr><td><strong>'
                . $this->escape(self::LABELS[$block->key()] ?? $block->key())
                . '</strong><input type="hidden" name="' . $prefix . '[key]" value="'
                . $this->escape($block->key()) . '"></td><td><input class="ojp-input"'
                . ' type="number" min="0" max="99" name="' . $prefix
                . '[order]" value="' . $block->order() . '"></td><td>'
                . '<input class="ojp-input" type="number" min="1" max="20" name="'
                . $prefix . '[limit]" value="' . $block->limit() . '"></td><td>'
                . '<input type="checkbox" name="' . $prefix . '[enabled]" value="1"'
                . ($block->enabled() ? ' checked' : '') . ' aria-label="'
                . $this->escape(self::LABELS[$block->key()] ?? $block->key())
                . ' 사용"></td></tr>';
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">사용자 화면</p>'
            . '<h1>홈 구성</h1><p>로그인 뒤 첫 화면의 블록 순서와 표시 수를 관리합니다.</p>'
            . '</div></header><section class="ojp-section"><form method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_home_settings')
            . '<input type="hidden" name="expected_revision" value="'
            . $snapshot->revision() . '"><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>블록</th><th>순서</th><th>표시 수</th><th>사용</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<div class="ojp-command-group"><button class="ojp-button ojp-button--primary"'
            . ' type="submit">홈 구성 저장</button></div></form></section>';

        return $this->pages->respond(
            '홈 구성',
            $content,
            'operations.home',
            $request
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
