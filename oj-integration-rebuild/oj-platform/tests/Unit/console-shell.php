<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\AdminConsole\Shell\ConsoleShell;
use CodingPanda\OjPlatform\Modules\AdminConsole\Navigation\ConsoleMenuItem;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\AccountLinkProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SiteBrandingProvider;
use CodingPanda\OjPlatform\Ui\Asset\UiAssetLocator;
use CodingPanda\OjPlatform\Ui\View\HtmlDocumentRenderer;

require __DIR__ . '/console-menus.php';
$menuAssertions = 0;
$assets = new class implements UiAssetLocator {
    public function stylesheetUrl(): string { return '/assets/oj-ui-system.css'; }
    public function scriptUrl(): string { return '/assets/oj-ui.js'; }
    public function mathJaxLoaderUrl(): string { return '/assets/mathjax-loader.js'; }
    public function iconSpriteUrl(): string { return '/assets/lucide.svg'; }
    public function faviconUrl(): string { return '/assets/favicon.svg'; }
};
$links = new class implements AccountLinkProvider {
    public function logoutUrl(string $redirectPath = '/login'): string { return '/logout'; }
    public function logoutFields(): string { return '<input type="hidden" name="_nonce" value="fixture">'; }
};
$branding = new class implements SiteBrandingProvider {
    public function serviceName(): string { return 'OJ Platform'; }
    public function shortDescription(): string { return ''; }
    public function supportLine(): string { return ''; }
};
$menu = [new ConsoleMenuItem('dashboard', '대시보드', '/oj-console')];
foreach (['users' => '사용자', 'problems' => '문제', 'submissions' => '제출', 'classes' => '수업', 'contests' => '대회', 'community' => '커뮤니티', 'operations' => '운영', 'addon.long-topic' => '추가 학습 관리 기능'] as $key => $label) {
    $children = [new ConsoleMenuItem($key . '.index', $label . ' 목록', '/oj-console/' . $key)];
    for ($i = 1; $i <= 12; $i++) {
        $children[] = new ConsoleMenuItem($key . '.' . $i, $label . ' 관리 항목 ' . $i, '/oj-console/' . $key . '/' . $i);
    }
    $menu[] = new ConsoleMenuItem($key, $label, '/oj-console/' . $key, $children);
}
$content = '<header class="ojp-page-header"><h1>문제 관리</h1></header>';
for ($i = 1; $i <= 35; $i++) {
    $content .= '<section class="ojp-section"><h2>문제 ' . $i . '</h2><p>문제 정보와 제출 결과</p></section>';
}
$console = new ConsoleShell(new HtmlDocumentRenderer($assets), $links, $branding);
$html = $console->render('문제 관리', $content, 'problems.index', new UserIdentity(1, 'admin', 'admin@example.test', '관리자'), $menu);
$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML($html);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$xpath = new DOMXPath($document);
$ids = [];
foreach ($xpath->query('//*[@id]') as $node) {
    $ids[] = $node->getAttribute('id');
}
menuSame(count($ids), count(array_unique($ids)), 'Desktop and mobile menus must use distinct IDs.');
$toggles = $xpath->query('//button[@data-ojp-console-submenu]');
menuSame(16, $toggles->length, 'Every authorized root with children gets a toggle on both surfaces.');
foreach ($toggles as $toggle) {
    $target = $xpath->query('//*[@id="' . $toggle->getAttribute('aria-controls') . '"]')->item(0);
    menuAssert($target !== null, 'Every toggle must control a real child list.');
    $current = $toggle->getAttribute('data-menu-key') === 'problems';
    menuSame($current ? 'true' : 'false', $toggle->getAttribute('aria-expanded'), 'Open the current section by default.');
    menuSame(! $current, $target->hasAttribute('hidden'), 'Other child lists are present but initially hidden.');
}
menuSame(2, $xpath->query('//a[@aria-current="page"]')->length, 'Preserve the current-page marker.');
menuSame(2, $xpath->query('//a[@href="/oj-console/users/12"]')->length, 'Non-current children remain available without first navigating to their parent.');
menuSame(1, $xpath->query('//*[@id="ojp-console-drawer" and @inert]')->length, 'Closed mobile navigation must not take keyboard focus.');
menuSame(0, $xpath->query('//button[@data-menu-key="dashboard"]')->length, 'Leaf menu links do not need a toggle.');
if (($argv[1] ?? '') === '--render' && isset($argv[2])) {
    if (! is_dir($argv[2])) mkdir($argv[2], 0777, true);
    file_put_contents($argv[2] . '/console-shell.html', $html);
}
$iconMethod = (new ReflectionClass(ConsoleShell::class))->getMethod('menuIcon');
menuSame('book-open', $iconMethod->invoke($console, 'problems'), 'Keep the standard core menu icon.');
menuSame('puzzle', $iconMethod->invoke($console, 'addon.example'), 'Unknown menu roots use the common addon icon.');
menuSame('trophy', $iconMethod->invoke($console, 'addon.example', 'trophy'), 'Use supported explicitly configured icons.');
menuSame('puzzle', $iconMethod->invoke($console, 'addon.example', 'unsupported-icon'), 'Unsupported icons never produce an empty sprite reference.');
menuSame('puzzle', $iconMethod->invoke($console, 'addon.example', '<svg>'), 'Invalid icon values fall back safely.');
menuSame(0, $xpath->query('//*[contains(@class,"ojp-console-nav__children")]//svg')->length, 'Second-level menu entries remain text-only.');
$iconDefinition = \CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition::fromArray(['key' => 'example', 'label' => 'Example', 'route' => 'first_accessible_child', 'order' => 1, 'icon' => 'trophy']);
menuSame('trophy', $iconDefinition->icon(), 'Menu definitions preserve the optional icon.');
echo "Console shell: $menuAssertions checks passed.\n";
