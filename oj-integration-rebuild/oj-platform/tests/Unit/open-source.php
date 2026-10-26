<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\OpenSourcePageHandler;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Ui\License\OpenSourceCatalog;

$root = dirname(__DIR__, 2);
require_once $root . '/src/Kernel/Autoloader.php';
Autoloader::register($root);
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void {
    ++$checks;
    if (! $value) { throw new RuntimeException($message); }
};
$components = (new OpenSourceCatalog())->components();
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$assert($composer['license'] === 'GPL-3.0-or-later', 'Composer must declare the authorized platform license.');
$assert(str_contains((string) file_get_contents($root . '/oj-platform.php'), 'License: GPL-3.0-or-later'), 'WordPress metadata must match the platform license.');
$assert(str_contains((new OpenSourceCatalog())->platformLicense(), 'Version 3, 29 June 2007'), 'The complete GPLv3 text must be available locally.');
$assert(count($components) === 10, 'All bundled components, helpers, themes and decoder must be inventoried.');
foreach ($components as $component) {
    $assert(str_starts_with($component['source'], 'https://'), 'Source links must be HTTPS.');
    foreach ($component['notices'] as $notice) {
        $assert(trim($notice['text']) !== '', 'Every component must have a readable full license or attribution.');
    }
}
$routes = require $root . '/config/routes.php';
$definition = array_values(array_filter($routes, static fn (array $route): bool => $route['name'] === 'legal.open_source'))[0];
$assert($definition['path'] === '/open-source-licenses' && $definition['authentication'] === 'anonymous', 'The license page must be public without signing in.');
$pages = new class implements PageResponder {
    public function guest(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse { return HttpResponse::html($content, $status); }
    public function authenticated(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse { return HttpResponse::html($content, $status); }
};
$response = (new OpenSourcePageHandler($pages))->handle(new RouteMatch(RouteDefinition::fromArray($definition), []), new HttpRequest('GET', '/open-source-licenses'));
$html = $response->body();
$assert($response->status() === 200, 'Guests must receive the complete license page.');
$assert(str_contains($html, 'GPL-3.0-or-later') && str_contains($html, 'Version 3, 29 June 2007'), 'Public page must show the platform grant and complete license.');
foreach (['HUSTOJ', 'Cole Bemis', 'Facebook, Inc.', 'Zeno Rocha', 'Lode Vandevenne', 'The MathJax Consortium'] as $text) {
    $assert(str_contains($html, $text), 'Missing visible copyright attribution: ' . $text);
}
$assert(! str_contains($html, '별도로 설치하는 실행 기반입니다.'), 'Public license page must not advertise the separately installed site framework.');
$assert(str_contains($html, 'id="addons"'), 'Installed addon notices must have a public section.');
$assert((new \CodingPanda\OjPlatform\Ui\License\OpenSourceCatalog())->addons(sys_get_temp_dir() . '/oj-nonexistent-addons') === [], 'Missing addons must not break the public page.');
$assert(! str_contains($html, '<hi@zenorocha.com>') && str_contains($html, '&lt;hi@zenorocha.com&gt;'), 'License texts must be escaped instead of interpreted as HTML.');
$assert(substr_count($html, '<details') >= 10, 'Complete notice texts must be available on the page.');
$assert(str_contains((string) file_get_contents(dirname($root) . '/scripts/build-release.ps1'), "'oj-platform/THIRD_PARTY_NOTICES.txt'"), 'The release ZIP must require the aggregate notice.');
$assert(str_contains((string) file_get_contents(dirname($root) . '/scripts/build-release.ps1'), "'oj-platform/LICENSE.txt'"), 'The release ZIP must require the complete platform license.');
echo "Open-source notices: $checks checks passed.\n";

if (($argv[1] ?? '') === '--render') { echo $html; }
