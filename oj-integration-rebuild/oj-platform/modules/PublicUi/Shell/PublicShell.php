<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Shell;

use CodingPanda\OjPlatform\Modules\Auth\Contract\RegistrationPolicyProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\AccountLinkProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Navigation\PublicMenuItem;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SiteBrandingProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SiteFooterProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SiteFooterVisibilityProvider;
use CodingPanda\OjPlatform\Ui\Component\ColorSchemeChoice;
use CodingPanda\OjPlatform\Ui\View\HtmlDocumentRenderer;

final class PublicShell
{
    private ?\CodingPanda\OjPlatform\Modules\PublicUi\Contract\MessageNotificationProvider $messageNotifications = null;
    private bool $notificationsAvailable = false;

    public function attachMessageNotifications(\CodingPanda\OjPlatform\Modules\PublicUi\Contract\MessageNotificationProvider $provider): void
    {
        $this->messageNotifications = $provider;
    }
    public function __construct(
        private readonly HtmlDocumentRenderer $documents,
        private readonly AccountLinkProvider $accountLinks,
        private readonly RegistrationPolicyProvider $registrationPolicy,
        private readonly SiteBrandingProvider $branding,
        private readonly SiteFooterProvider $footer
    ) {
    }

    public function guest(string $title, string $content): string
    {
        $body = '<a class="ojp-skip-link" href="#ojp-main">본문으로 건너뛰기</a>'
            . '<div class="ojp-public-shell">'
            . '<header class="ojp-public-header">'
            . '<div class="ojp-public-header__inner">'
            . $this->renderBrand('/login')
            . '<nav class="ojp-guest-nav" aria-label="비로그인 메뉴">'
            . '<a href="/login">로그인</a>'
            . ($this->registrationPolicy->current()->isEnabled()
                ? '<a href="/register">회원가입</a>'
                : '')
            . '</nav></div></header>'
            . '<main class="ojp-guest-main" id="ojp-main">'
            . $content . '</main>'
            . $this->siteFooter()
            . '</div>';

        return $this->documents->render($title, $body, 'ojp-app');
    }

    /**
     * @param list<PublicMenuItem> $menu
     */
    public function authenticated(
        string $title,
        string $content,
        UserIdentity $user,
        array $menu,
        bool $canAccessConsole,
        string $currentPath = ''
    ): string {
        $accountItems = $this->accountItems($menu);
        $unread = 0;
        $this->notificationsAvailable = false;
        try {
            $this->notificationsAvailable = $this->messageNotifications?->isEnabled() ?? false;
            if ($this->notificationsAvailable) { $unread = max(0, $this->messageNotifications->unreadCount($user->userId())); }
        }
        catch (\Throwable) { /* Messaging outages must not prevent navigation. */ }
        $body = '<a class="ojp-skip-link" href="#ojp-main">본문으로 건너뛰기</a>'
            . '<div class="ojp-public-shell">'
            . '<header class="ojp-public-header">'
            . '<div class="ojp-public-header__inner">'
            . $this->renderBrand('/')
            . '<nav class="ojp-public-nav" aria-label="사용자 메뉴">'
            . $this->renderMenu($menu, $currentPath)
            . '</nav>'
            . (! $this->notificationsAvailable ? '' : '<a class="ojp-message-notification" href="/messages" title="쪽지함"'
            . ' aria-label="쪽지함, 읽지 않은 쪽지 ' . $unread . '개" data-ojp-message-notification>'
            . $this->documents->icon('mail')
            . '<span class="ojp-message-count" data-ojp-message-count' . ($unread === 0 ? ' hidden' : '') . '>'
            . ($unread > 99 ? '99+' : $unread) . '</span></a>')
            . $this->renderAccountMenu(
                $user,
                $accountItems,
                $canAccessConsole,
                $unread
            )
            . '</div></header>'
            . '<main class="ojp-public-main" id="ojp-main">'
            . '<div class="ojp-shell">' . $content . '</div></main>'
            . $this->siteFooter()
            . $this->renderMobileNavigation(
                $menu,
                $accountItems,
                $canAccessConsole,
                $currentPath
            )
            . '</div>';

        return $this->documents->render($title, $body, 'ojp-app', $user->userId());
    }

    private function siteFooter(): string
    {
        $links = '';
        $seen = [];
        foreach (SiteFooterVisibilityProvider::LINKS as $path => $label) {
            $seen[$path] = true;
            if ($this->footer instanceof SiteFooterVisibilityProvider && ! $this->footer->footerLinkVisible($path)) {
                continue;
            }
            $links .= '<a href="' . $this->escape($path) . '">' . $this->escape($label) . '</a>';
        }
        foreach ($this->footer->footerLinks() as $link) {
            if (isset($seen[$link['url']])) {
                continue;
            }
            $seen[$link['url']] = true;
            $external = parse_url($link['url'], PHP_URL_SCHEME) !== null;
            $links .= '<a href="' . $this->escape($link['url']) . '"'
                . ($external ? ' target="_blank" rel="noopener noreferrer"' : '')
                . '>' . $this->escape($link['label']) . '</a>';
        }

        $meta = '';
        if ($this->footer->copyrightNotice() !== '') {
            $meta .= '<span>' . $this->escape($this->footer->copyrightNotice()) . '</span>';
        }
        if ($this->branding->supportLine() !== '') {
            $meta .= '<span>' . $this->escape($this->branding->supportLine()) . '</span>';
        }
        if ($this->footer->administratorEmail() !== '') {
            $email = $this->footer->administratorEmail();
            $meta .= '<a href="mailto:' . $this->escape($email) . '">관리자 문의 '
                . $this->escape($email) . '</a>';
        }

        return '<footer class="ojp-public-footer"><div class="ojp-public-footer__inner">'
            . ($links === '' ? '' : '<nav aria-label="약관 및 정책 문서">' . $links . '</nav>')
            . ($meta === '' ? '' : '<div class="ojp-public-footer__meta">' . $meta . '</div>')
            . '</div></footer>';
    }

    /**
     * @param list<PublicMenuItem> $items
     */
    private function renderMenu(array $items, string $currentPath): string
    {
        $html = '';
        $position = 0;
        foreach ($items as $item) {
            if ($item->key() === 'account') {
                continue;
            }
            $current = $this->isCurrent($item, $currentPath);
            $html .= '<div class="ojp-nav-item">';
            if ($item->children() !== []) {
                $menuId = 'ojp-public-menu-' . (++$position);
                $html .= '<button class="ojp-nav-trigger'
                    . ($current ? ' is-current' : '')
                    . '" type="button" aria-expanded="false"'
                    . ' aria-controls="' . $menuId . '"'
                    . ' data-ojp-menu-toggle="' . $menuId . '">'
                    . '<span>' . $this->escape($item->label()) . '</span>'
                    . $this->documents->icon('chevron-down') . '</button>'
                    . '<div class="ojp-nav-submenu" id="' . $menuId
                    . '" data-ojp-menu hidden>';
                foreach ($item->children() as $child) {
                    $html .= $this->link(
                        $child,
                        $this->isCurrent($child, $currentPath)
                    );
                }
                $html .= '</div>';
            } else {
                $html .= $this->link($item, $current);
            }
            $html .= '</div>';
        }

        return $html;
    }

    private function link(
        PublicMenuItem $item,
        bool $current = false,
        bool $withIcon = false
    ): string
    {
        return '<a class="ojp-nav-link" href="'
            . $this->escape($item->url()) . '"'
            . ($current ? ' aria-current="page"' : '')
            . ($item->isExternal()
                ? ' target="_blank" rel="noopener noreferrer"'
                : '')
            . '>'
            . ($withIcon
                ? $this->documents->icon($this->menuIcon($item->key()))
                : '')
            . '<span>' . $this->escape($item->label()) . '</span>'
            . ($item->isExternal()
                ? $this->documents->icon('external-link')
                : '')
            . '</a>';
    }

    private function renderBrand(string $url): string
    {
        return '<a class="ojp-brand" href="' . $this->escape($url) . '">'
            . '<span class="ojp-brand__mark" aria-hidden="true">OJ</span>'
            . '<span class="ojp-brand__name">'
            . $this->escape($this->branding->serviceName())
            . '</span></a>';
    }

    /**
     * @param list<PublicMenuItem> $menu
     * @return list<PublicMenuItem>
     */
    private function accountItems(array $menu): array
    {
        foreach ($menu as $item) {
            if ($item->key() === 'account') {
                return $item->children();
            }
        }

        return [];
    }

    /** @param list<PublicMenuItem> $accountItems */
    private function renderAccountMenu(
        UserIdentity $user,
        array $accountItems,
        bool $canAccessConsole,
        int $unread = 0
    ): string {
        $links = '<div class="ojp-account-summary"><strong>'
            . $this->escape($user->displayName())
            . '</strong><span>내 계정</span></div>';
        if ($this->notificationsAvailable) {
            $links .= '<a href="/messages">' . $this->documents->icon('mail')
                . '<span>쪽지</span><span data-ojp-message-count' . ($unread === 0 ? ' hidden' : '') . '>'
                . ($unread > 99 ? '99+' : $unread) . '</span></a>'
                . '<a href="/messages/find">' . $this->documents->icon('search') . '<span>사용자 찾기</span></a>';
        }
        foreach ($accountItems as $item) {
            $links .= $this->accountLink($item);
        }
        if ($canAccessConsole) {
            $links .= '<a href="/oj-console">'
                . $this->documents->icon('settings')
                . '<span>관리자 화면</span></a>';
        }
        $links .= ColorSchemeChoice::render();
        $links .= '<form class="ojp-account-logout" method="post" action="'
            . $this->escape($this->accountLinks->logoutUrl()) . '">'
            . $this->accountLinks->logoutFields()
            . '<button class="ojp-account-logout__button" type="submit">'
            . $this->documents->icon('log-out')
            . '<span>로그아웃</span></button></form>';

        return '<div class="ojp-account-wrap">'
            . '<button class="ojp-account-button" type="button"'
            . ' aria-label="내 정보 메뉴" title="내 정보 메뉴"'
            . ' aria-expanded="false" aria-controls="ojp-account-popover"'
            . ' data-ojp-menu-toggle="ojp-account-popover">'
            . $this->documents->icon('user')
            . '<span class="ojp-message-dot" data-ojp-message-dot' . ($unread === 0 ? ' hidden' : '') . '></span></button>'
            . '<div class="ojp-popover-menu ojp-account-popover"'
            . ' id="ojp-account-popover" data-ojp-menu hidden>'
            . $links . '</div></div>';
    }

    private function accountLink(PublicMenuItem $item): string
    {
        return '<a href="' . $this->escape($item->url()) . '"'
            . ($item->isExternal()
                ? ' target="_blank" rel="noopener noreferrer"'
                : '')
            . '>' . $this->documents->icon($this->menuIcon($item->key()))
            . '<span>' . $this->escape($item->label()) . '</span>'
            . ($item->isExternal()
                ? $this->documents->icon('external-link')
                : '')
            . '</a>';
    }

    /**
     * @param list<PublicMenuItem> $menu
     * @param list<PublicMenuItem> $accountItems
     */
    private function renderMobileNavigation(
        array $menu,
        array $accountItems,
        bool $canAccessConsole,
        string $currentPath
    ): string {
        // Four tabs plus 더보기. Prefer the D-010 tabs; when an operator has
        // removed one of them, fill the gap with the next menu root in order.
        $itemsByKey = [];
        foreach ($menu as $item) {
            $itemsByKey[$item->key()] = $item;
        }
        $preferred = array_values(array_filter(
            ['home', 'problems', 'classes', 'learning_support'],
            static fn (string $key): bool => isset($itemsByKey[$key])
        ));
        foreach ($menu as $item) {
            if (count($preferred) >= 4) {
                break;
            }
            if ($item->key() !== 'account' && ! in_array($item->key(), $preferred, true)) {
                $preferred[] = $item->key();
            }
        }

        $navigation = '';
        $panels = '';
        foreach ($preferred as $key) {
            if (! isset($itemsByKey[$key])) {
                continue;
            }
            $item = $itemsByKey[$key];
            $current = $this->isCurrent($item, $currentPath);
            if ($item->children() !== []) {
                $panelId = 'ojp-mobile-menu-' . $this->safeKey($key);
                $navigation .= '<button type="button" aria-expanded="false"'
                    . ' aria-controls="' . $panelId . '"'
                    . ' data-ojp-menu-toggle="' . $panelId . '"'
                    . ($current ? ' class="is-current"' : '') . '>'
                    . $this->documents->icon($this->menuIcon($key))
                    . '<span>' . $this->escape($item->label())
                    . '</span></button>';
                $panels .= $this->mobilePanel(
                    $panelId,
                    $item->label(),
                    $item->children()
                );
            } else {
                $navigation .= '<a href="' . $this->escape($item->url()) . '"'
                    . ($current ? ' aria-current="page"' : '') . '>'
                    . $this->documents->icon($this->menuIcon($key))
                    . '<span>' . $this->escape($item->label()) . '</span></a>';
            }
        }

        $moreId = 'ojp-mobile-menu-more';
        $navigation .= '<button type="button" aria-expanded="false"'
            . ' aria-controls="' . $moreId . '" data-ojp-menu-toggle="'
            . $moreId . '">' . $this->documents->icon('ellipsis')
            . '<span>더보기</span></button>';
        $moreLinks = '';
        foreach ($menu as $item) {
            if (in_array($item->key(), $preferred, true)
                || $item->key() === 'account'
            ) {
                continue;
            }
            if ($item->children() === []) {
                $moreLinks .= $this->mobileLink($item);
                continue;
            }
            foreach ($item->children() as $child) {
                $moreLinks .= $this->mobileLink($child);
            }
        }
        foreach ($accountItems as $item) {
            $moreLinks .= $this->mobileLink($item);
        }
        if ($canAccessConsole) {
            $moreLinks .= '<a href="/oj-console">'
                . $this->documents->icon('settings')
                . '<span><strong>관리자 화면</strong>'
                . '<small>운영 화면으로 이동</small></span></a>';
        }
        $panels .= '<div class="ojp-mobile-menu" id="' . $moreId
            . '" data-ojp-menu hidden>'
            . '<div class="ojp-mobile-menu__heading">'
            . '<strong>더보기</strong><span>메뉴</span></div>'
            . $moreLinks . '</div>';

        return '<nav class="ojp-mobile-bottom-nav"'
            . ' aria-label="모바일 사용자 메뉴">'
            . $navigation . '</nav>' . $panels;
    }

    /** @param list<PublicMenuItem> $items */
    private function mobilePanel(string $id, string $label, array $items): string
    {
        $links = '';
        foreach ($items as $item) {
            $links .= $this->mobileLink($item);
        }

        return '<div class="ojp-mobile-menu" id="' . $this->escape($id)
            . '" data-ojp-menu hidden>'
            . '<div class="ojp-mobile-menu__heading"><strong>'
            . $this->escape($label)
            . '</strong><span>2차 메뉴</span></div>'
            . $links . '</div>';
    }

    private function mobileLink(PublicMenuItem $item): string
    {
        return '<a href="' . $this->escape($item->url()) . '"'
            . ($item->isExternal()
                ? ' target="_blank" rel="noopener noreferrer"'
                : '')
            . '><span><strong>' . $this->escape($item->label())
            . '</strong><small>'
            . ($item->isExternal() ? '외부 페이지 · 새 창' : '페이지 열기')
            . '</small></span></a>';
    }

    private function isCurrent(PublicMenuItem $item, string $currentPath): bool
    {
        foreach ($item->children() as $child) {
            if ($this->isCurrent($child, $currentPath)) {
                return true;
            }
        }
        if ($item->isExternal()) {
            return false;
        }
        $itemPath = parse_url($item->url(), PHP_URL_PATH);
        $itemPath = is_string($itemPath) ? rtrim($itemPath, '/') : '';
        $path = rtrim($currentPath, '/');
        $itemPath = $itemPath === '' ? '/' : $itemPath;
        $path = $path === '' ? '/' : $path;

        return $path === $itemPath
            || ($itemPath !== '/'
                && str_starts_with($path, $itemPath . '/'));
    }

    private function menuIcon(string $key): string
    {
        if (str_contains($key, 'settings')
            || str_contains($key, 'agreements')
        ) {
            return 'settings';
        }
        if (str_contains($key, 'ranking')) {
            return 'trophy';
        }
        $root = explode('.', $key)[0];

        return match ($root) {
            'home' => 'home',
            'problems', 'quizzes', 'problem_books' => 'book-open',
            'submissions' => 'send',
            'learning', 'classes', 'contests', 'groups',
            'learning_dashboard' => 'graduation-cap',
            'community', 'board', 'notices', 'messaging' => 'message-square',
            'account', 'profile' => 'user',
            'messaging.find' => 'search',
            default => 'arrow-right',
        };
    }

    private function safeKey(string $key): string
    {
        return preg_replace(
            '/[^a-z0-9-]+/',
            '-',
            strtolower($key)
        ) ?? 'item';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
