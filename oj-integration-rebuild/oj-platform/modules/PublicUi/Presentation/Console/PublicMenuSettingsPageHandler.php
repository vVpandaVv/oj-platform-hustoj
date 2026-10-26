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
use CodingPanda\OjPlatform\Modules\PublicUi\Application\PublicMenuSettingsService;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\PublicMenuEntry;

final class PublicMenuSettingsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly PublicMenuSettingsService $settings,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $snapshot = $this->settings->snapshot();
        $entries = $this->settings->entries();
        $roots = array_values(array_filter(
            $entries,
            static fn (PublicMenuEntry $entry): bool => $entry->level() === 1
        ));
        $rows = '';
        foreach ($entries as $index => $entry) {
            $rows .= $this->row($index, $entry, $roots);
        }
        $next = count($entries);
        $rows .= $this->emptyRow($next, 'internal', $roots);
        $rows .= $this->emptyRow($next + 1, 'external', $roots);
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">사용자 화면</p>'
            . '<h1>사용자 메뉴</h1><p>1차·2차 메뉴의 이름, 연결, 표시 대상과 순서를 관리합니다.</p>'
            . '</div></header><section class="ojp-section"><form method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_public_menu_settings')
            . '<input type="hidden" name="expected_revision" value="'
            . $snapshot->revision() . '"><div class="ojp-table-wrap">'
            . '<table class="ojp-table ojp-table--form"><thead><tr><th>단계·이름</th>'
            . '<th>연결</th><th>상위 메뉴</th><th>표시 대상</th><th>순서</th>'
            . '<th>모바일</th><th>사용</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div><p class="ojp-field-help">'
            . '내부 페이지는 등록된 화면만 선택할 수 있습니다. 외부 연결은 HTTPS 주소만 허용하며 새 창으로 엽니다.'
            . '</p><div class="ojp-command-group"><button class="ojp-button ojp-button--primary"'
            . ' type="submit">사용자 메뉴 저장</button></div></form>'
            . '<form method="post" action="' . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_reset_public_menu_settings')
            . '<input type="hidden" name="expected_revision" value="'
            . $snapshot->revision() . '"><button class="ojp-button" type="submit">'
            . '기본 메뉴로 되돌리기</button></form></section>';

        return $this->pages->respond(
            '사용자 메뉴',
            $content,
            'operations.menu',
            $request
        );
    }

    /**
     * @param list<PublicMenuEntry> $roots
     */
    private function row(
        int $index,
        PublicMenuEntry $entry,
        array $roots
    ): string {
        $prefix = 'items[' . $index . ']';
        $destination = $entry->isExternal()
            ? '<input class="ojp-input" type="url" name="' . $prefix
                . '[external_url]" value="' . $this->escape($entry->externalUrl() ?? '') . '">'
            : '<select class="ojp-select" name="' . $prefix . '[route_key]">'
                . $this->routeOptions($entry->routeKey() ?? '') . '</select>';

        return '<tr><td><input type="hidden" name="' . $prefix . '[key]" value="'
            . $this->escape($entry->key()) . '"><select class="ojp-select" name="'
            . $prefix . '[level]"><option value="1"'
            . ($entry->level() === 1 ? ' selected' : '') . '>1차</option>'
            . '<option value="2"' . ($entry->level() === 2 ? ' selected' : '')
            . '>2차</option></select><input class="ojp-input" name="' . $prefix
            . '[label]" maxlength="30" value="' . $this->escape($entry->label())
            . '" required></td><td><input type="hidden" name="' . $prefix
            . '[destination_type]" value="' . ($entry->isExternal() ? 'external' : 'internal')
            . '">' . $destination . '</td><td><select class="ojp-select" name="'
            . $prefix . '[parent_menu_key]">' . $this->parentOptions(
                $entry->parentKey(),
                $roots
            ) . '</select></td><td>' . $this->scopeSelect($prefix, $entry->scope())
            . '</td><td><input class="ojp-input" type="number" min="0" max="999" name="'
            . $prefix . '[order]" value="' . $entry->order() . '"></td><td>'
            . $this->mobileSelect($prefix, $entry->mobilePosition())
            . '</td><td><input type="checkbox" name="' . $prefix
            . '[enabled]" value="1"' . ($entry->enabled() ? ' checked' : '')
            . ' aria-label="' . $this->escape($entry->label()) . ' 사용"></td></tr>';
    }

    /**
     * @param list<PublicMenuEntry> $roots
     */
    private function emptyRow(int $index, string $type, array $roots): string
    {
        $prefix = 'items[' . $index . ']';
        $destination = $type === 'external'
            ? '<input class="ojp-input" type="url" name="' . $prefix
                . '[external_url]" placeholder="https://example.com">'
            : '<select class="ojp-select" name="' . $prefix . '[route_key]">'
                . '<option value="">내부 페이지 선택</option>'
                . $this->routeOptions('') . '</select>';

        return '<tr><td><select class="ojp-select" name="' . $prefix
            . '[level]"><option value="1">1차</option><option value="2" selected>2차</option>'
            . '</select><input class="ojp-input" name="' . $prefix
            . '[key]" placeholder="새 메뉴 키"><input class="ojp-input" name="'
            . $prefix . '[label]" maxlength="30" placeholder="새 '
            . ($type === 'external' ? '외부' : '내부') . ' 메뉴"></td><td>'
            . '<input type="hidden" name="' . $prefix . '[destination_type]" value="'
            . $type . '">' . $destination . '</td><td><select class="ojp-select" name="'
            . $prefix . '[parent_menu_key]">' . $this->parentOptions(null, $roots)
            . '</select></td><td>' . $this->scopeSelect($prefix, 'all')
            . '</td><td><input class="ojp-input" type="number" min="0" max="999" name="'
            . $prefix . '[order]" value="90"></td><td>'
            . $this->mobileSelect($prefix, 'more')
            . '</td><td><input type="checkbox" name="' . $prefix
            . '[enabled]" value="1" checked aria-label="새 메뉴 사용"></td></tr>';
    }

    private function routeOptions(string $selected): string
    {
        $html = '<option value="first_accessible_child"'
            . ($selected === 'first_accessible_child' ? ' selected' : '')
            . '>첫 접근 가능한 2차 메뉴</option>';
        foreach ($this->settings->availableInternalRoutes() as $routeKey => $label) {
            $html .= '<option value="' . $this->escape($routeKey) . '"'
                . ($selected === $routeKey ? ' selected' : '') . '>'
                . $this->escape($label) . '</option>';
        }

        return $html;
    }

    /**
     * @param list<PublicMenuEntry> $roots
     */
    private function parentOptions(?string $selected, array $roots): string
    {
        $html = '<option value="">상위 없음</option>';
        foreach ($roots as $root) {
            $html .= '<option value="' . $this->escape($root->key()) . '"'
                . ($selected === $root->key() ? ' selected' : '') . '>'
                . $this->escape($root->label()) . '</option>';
        }

        return $html;
    }

    private function scopeSelect(string $prefix, string $selected): string
    {
        $labels = [
            'all' => '전체',
            'learner' => '학습자',
            'staff' => '운영 담당자',
            'system_admin' => '시스템 관리자',
        ];
        $html = '<select class="ojp-select" name="' . $prefix . '[scope]">';
        foreach ($labels as $value => $label) {
            $html .= '<option value="' . $value . '"'
                . ($selected === $value ? ' selected' : '') . '>' . $label . '</option>';
        }

        return $html . '</select>';
    }

    private function mobileSelect(string $prefix, string $selected): string
    {
        return '<select class="ojp-select" name="' . $prefix . '[mobile_position]">'
            . '<option value="primary"' . ($selected === 'primary' ? ' selected' : '')
            . '>기본</option><option value="more"'
            . ($selected === 'more' ? ' selected' : '') . '>더보기</option></select>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
