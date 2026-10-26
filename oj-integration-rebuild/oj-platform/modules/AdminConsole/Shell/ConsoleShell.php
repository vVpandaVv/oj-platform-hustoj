<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Shell;

use CodingPanda\OjPlatform\Modules\AdminConsole\Navigation\ConsoleMenuItem;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\AccountLinkProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SiteBrandingProvider;
use CodingPanda\OjPlatform\Ui\Component\ColorSchemeChoice;
use CodingPanda\OjPlatform\Ui\View\HtmlDocumentRenderer;

final class ConsoleShell
{
    public function __construct(
        private readonly HtmlDocumentRenderer $documents,
        private readonly AccountLinkProvider $accountLinks,
        private readonly SiteBrandingProvider $branding
    ) {
    }

    /**
     * @param list<ConsoleMenuItem> $menu
     */
    public function render(
        string $title,
        string $content,
        string $currentRoute,
        UserIdentity $user,
        array $menu
    ): string {
        $body = '<script>(function(){try{'
            . 'if(window.localStorage.getItem("ojp-console-sidebar")==="collapsed"){'
            . 'document.documentElement.setAttribute("data-ojp-sidebar","collapsed");'
            . '}}catch(e){}})();</script>'
            . '<a class="ojp-skip-link" href="#ojp-main">본문으로 건너뛰기</a>'
            . '<div class="ojp-console-shell">'
            . '<header class="ojp-console-header"><div class="ojp-console-header__inner">'
            . '<button class="ojp-icon-button ojp-console-menu-button" type="button"'
            . ' aria-label="관리 메뉴 열기" aria-expanded="false"'
            . ' data-ojp-drawer-toggle="ojp-console-drawer">'
            . $this->documents->icon('menu') . '</button>'
            . '<a class="ojp-brand" href="/oj-console">'
            . '<span class="ojp-brand__mark" aria-hidden="true">OJ</span>'
            . '<span class="ojp-brand__name">관리자 콘솔</span></a>'
            . '<button class="ojp-command-button" type="button" data-ojp-command-open'
            . ' aria-haspopup="dialog" aria-label="메뉴와 화면 찾기">'
            . $this->documents->icon('search') . '<span>찾기</span><kbd>Ctrl K</kbd></button>'
            . '<div class="ojp-console-tools">'
            . '<a class="ojp-console-user-link" href="/">'
            . $this->documents->icon('external-link') . '<span>사용자 화면</span></a>'
            . '<div class="ojp-account-wrap">'
            . '<button class="ojp-account-button" type="button"'
            . ' aria-label="관리자 계정 메뉴" title="관리자 계정 메뉴"'
            . ' aria-expanded="false" aria-controls="ojp-console-account-popover"'
            . ' data-ojp-menu-toggle="ojp-console-account-popover">'
            . $this->documents->icon('user') . '</button>'
            . '<div class="ojp-popover-menu ojp-account-popover"'
            . ' id="ojp-console-account-popover" data-ojp-menu hidden>'
            . '<div class="ojp-account-summary"><strong>'
            . $this->escape($user->displayName())
            . '</strong><span>관리자 계정</span></div>'
            . '<a href="/">' . $this->documents->icon('external-link')
            . '<span>사용자 화면</span></a>'
            . ColorSchemeChoice::render()
            . '<form class="ojp-account-logout" method="post" action="'
            . $this->escape($this->accountLinks->logoutUrl()) . '">'
            . $this->accountLinks->logoutFields()
            . '<button class="ojp-account-logout__button" type="submit">'
            . $this->documents->icon('log-out') . '<span>로그아웃</span></button></form>'
            . '</div></div></div>'
            . '</div></header><div class="ojp-console-layout">'
            . '<aside class="ojp-console-sidebar" aria-label="관리자 메뉴">'
            . '<div class="ojp-console-sidebar__head">'
            . '<strong class="ojp-console-sidebar__title">관리자 메뉴</strong>'
            . '<button class="ojp-icon-button ojp-icon-button--small ojp-console-sidebar-toggle"'
            . ' type="button" aria-label="메뉴 접기" aria-expanded="true" data-ojp-sidebar-toggle>'
            . $this->documents->icon('arrow-left') . '</button>'
            . '</div>'
            . $this->renderMenu($menu, $currentRoute, 'sidebar')
            . '</aside><main class="ojp-console-main" id="ojp-main">'
            . '<div class="ojp-shell">'
            . $this->renderBreadcrumb($menu, $currentRoute, $title)
            . $this->withLocalNav($content, $menu, $currentRoute)
            . '</div></main></div>'
            . '<aside class="ojp-drawer" id="ojp-console-drawer"'
            . ' data-open="false" inert aria-label="모바일 관리자 메뉴">'
            . '<div class="ojp-drawer__header"><strong>관리 메뉴</strong>'
            . '<button class="ojp-icon-button" type="button" aria-label="관리 메뉴 닫기"'
            . ' data-ojp-drawer-toggle="ojp-console-drawer">'
            . $this->documents->icon('x') . '</button></div>'
            . '<div class="ojp-drawer__body">'
            . $this->renderMenu($menu, $currentRoute, 'drawer')
            . '</div></aside></div>';

        return $this->documents->render($title, $body, 'ojp-app', $user->userId());
    }

    /**
     * @param list<ConsoleMenuItem> $menu
     */
    private function renderMenu(array $menu, string $currentRoute, string $surface): string
    {
        $html = '<nav class="ojp-console-nav" aria-label="관리자 메뉴">';
        $previousSection = null;
        foreach ($menu as $root) {
            if ($previousSection !== null && $root->section() !== $previousSection) {
                $html .= '<hr class="ojp-console-nav__divider">';
            }
            $previousSection = $root->section();
            $expanded = $this->containsCurrent($root, $currentRoute);
            $rootCurrent = $root->key() === $currentRoute;
            $childrenId = 'ojp-console-' . $surface . '-' . substr(hash('sha256', $root->key()), 0, 16);
            $html .= '<section class="ojp-console-nav__group">'
                . '<div class="ojp-console-nav__heading">'
                . '<a class="ojp-console-nav__root'
                . ($expanded ? ' is-current' : '')
                . '"' . ($rootCurrent ? ' aria-current="page"' : '')
                . ' href="' . $this->escape($root->url()) . '"'
                . ' title="' . $this->escape($root->label()) . '">'
                . $this->documents->icon($this->menuIcon($root->key(), $root->icon()))
                . '<span>' . $this->escape($root->label()) . '</span></a>';
            if ($root->children() !== []) {
                $toggleLabel = $root->label() . ($expanded ? ' 하위 메뉴 접기' : ' 하위 메뉴 펼치기');
                $html .= '<button type="button" class="ojp-icon-button ojp-console-nav__toggle"'
                    . ' data-ojp-console-submenu="' . $childrenId . '"'
                    . ' data-menu-key="' . $this->escape($root->key()) . '"'
                    . ' data-menu-label="' . $this->escape($root->label()) . '"'
                    . ' data-current="' . ($expanded ? 'true' : 'false') . '"'
                    . ' aria-controls="' . $childrenId . '" aria-expanded="' . ($expanded ? 'true' : 'false') . '"'
                    . ' aria-label="' . $this->escape($toggleLabel) . '" title="' . $this->escape($toggleLabel) . '">'
                    . $this->documents->icon('chevron-down') . '</button>';
            }
            $html .= '</div>';
            if ($root->children() !== []) {
                $html .= '<div class="ojp-console-nav__children" id="' . $childrenId . '"'
                    . ($expanded ? '' : ' hidden') . '>';
                $childSection = '';
                foreach ($root->children() as $child) {
                    if ($child->section() !== '' && $child->section() !== $childSection) {
                        $html .= '<span class="ojp-console-nav__section">'
                            . $this->escape($child->section()) . '</span>';
                    }
                    $childSection = $child->section();
                    $current = $child->matches($currentRoute);
                    $html .= '<a' . ($current ? ' aria-current="page"' : '')
                        . ' href="' . $this->escape($child->url()) . '">'
                        . '<span>' . $this->escape($child->label())
                        . '</span></a>';
                }
                $html .= '</div>';
            }
            $html .= '</section>';
        }

        return $html . '</nav>';
    }

    /**
     * "Group › Item › Page" for the current menu key. The last step is plain
     * text: it names the page the user is on, so it is not a link.
     *
     * @param list<ConsoleMenuItem> $menu
     */
    private function renderBreadcrumb(array $menu, string $currentRoute, string $title): string
    {
        $trail = [];
        foreach ($menu as $root) {
            if ($root->key() === $currentRoute) {
                $trail = [$root];
                break;
            }
            foreach ($root->children() as $child) {
                if ($child->matches($currentRoute)) {
                    $trail = [$root, $child];
                    break 2;
                }
            }
        }
        if ($trail === []) {
            return '';
        }

        $last = $trail[count($trail) - 1];
        $title = trim($title);
        $showTitle = $title !== '' && $title !== $last->label();
        if (count($trail) === 1 && ! $showTitle) {
            return '';
        }

        $html = '<nav class="ojp-console-breadcrumb" aria-label="현재 위치"><ol>';
        foreach ($trail as $index => $item) {
            $isLast = $index === count($trail) - 1 && ! $showTitle;
            $html .= '<li>' . ($isLast
                ? '<span aria-current="page">' . $this->escape($item->label()) . '</span>'
                : '<a href="' . $this->escape($item->url()) . '">' . $this->escape($item->label()) . '</a>')
                . '</li>';
        }
        if ($showTitle) {
            $html .= '<li><span aria-current="page">' . $this->escape($title) . '</span></li>';
        }

        return $html . '</ol></nav>';
    }

    /**
     * Segments for a sidebar entry that groups several pages (users list and
     * deleted users, AI feedback and answer guides …). They go right under the
     * page header; task members (추가, 글쓰기) become buttons unless the page
     * already links to them.
     *
     * @param list<ConsoleMenuItem> $menu
     */
    private function withLocalNav(string $content, array $menu, string $currentRoute): string
    {
        $entry = null;
        foreach ($menu as $root) {
            foreach ($root->children() as $child) {
                if ($child->members() !== [] && $child->matches($currentRoute)) {
                    $entry = $child;
                    break 2;
                }
            }
        }
        if ($entry === null) {
            return $content;
        }

        $views = '';
        $viewCount = 0;
        $actions = '';
        foreach ($entry->members() as $member) {
            $url = $this->escape($member->url());
            if ($member->isAction()) {
                if ($member->key() !== $currentRoute && ! str_contains($content, 'href="' . $url . '"')) {
                    $actions .= '<a class="ojp-button ojp-button--tinted ojp-button--small" href="' . $url . '">'
                        . $this->documents->icon('plus') . '<span>' . $this->escape($member->label()) . '</span></a>';
                }
                continue;
            }
            ++$viewCount;
            $current = $member->key() === $currentRoute;
            $views .= '<a href="' . $url . '"' . ($current ? ' class="is-current" aria-current="true"' : '') . '>'
                . $this->escape($member->label()) . '</a>';
        }
        if ($viewCount < 2 && $actions === '') {
            return $content;
        }

        $bar = '<div class="ojp-local-nav-bar">'
            . ($viewCount >= 2 ? '<nav class="ojp-local-nav" aria-label="' . $this->escape($entry->label()) . ' 화면">' . $views . '</nav>' : '')
            . ($actions !== '' ? '<div class="ojp-local-nav-bar__actions">' . $actions . '</div>' : '')
            . '</div>';

        // After the page header when there is one, otherwise on top.
        if (preg_match('/<header class="ojp-page-header[^"]*"/', $content, $match, PREG_OFFSET_CAPTURE) === 1) {
            $close = strpos($content, '</header>', (int) $match[0][1]);
            if ($close !== false) {
                $at = $close + strlen('</header>');

                return substr($content, 0, $at) . $bar . substr($content, $at);
            }
        }

        return $bar . $content;
    }

    private function containsCurrent(ConsoleMenuItem $root, string $currentRoute): bool
    {
        if ($root->key() === $currentRoute) {
            return true;
        }
        foreach ($root->children() as $child) {
            if ($child->matches($currentRoute)) {
                return true;
            }
        }

        return false;
    }

    private function menuIcon(string $key, string $configured = ''): string
    {
        if ($configured !== '') {
            return \CodingPanda\OjPlatform\Ui\Icon\IconName::tryFrom($configured)?->value ?? 'puzzle';
        }

        return match ($key) {
            'dashboard' => 'layout-dashboard',
            'users' => 'users',
            'problems' => 'book-open',
            'submissions' => 'send',
            'classes' => 'graduation-cap',
            'contests' => 'trophy',
            'learning_support' => 'lightbulb',
            'community' => 'message-square',
            'messaging' => 'mail',
            'operations' => 'settings',
            default => 'puzzle',
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
