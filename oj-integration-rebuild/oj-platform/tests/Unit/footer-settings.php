<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Settings\Application\SiteSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Settings\Domain\SiteSettings;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Action\SaveSiteSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Console\SiteSettingsPageHandler;

$root = dirname(__DIR__, 2);
require_once $root . '/src/Kernel/Autoloader.php';
Autoloader::register($root);
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void {
    ++$checks;
    if (! $value) { throw new RuntimeException($message); }
};
$store = new class implements SettingsManager {
    private int $revision = 0;
    private mixed $value = null;
    public function read(string $key, mixed $default = null): SettingsSnapshot { return new SettingsSnapshot($this->revision, $this->value ?? $default); }
    public function save(string $key, mixed $value, int $expectedRevision): SettingsSnapshot {
        if ($expectedRevision !== $this->revision) { throw new DomainException('revision conflict'); }
        $this->value = $value;
        return new SettingsSnapshot(++$this->revision, $value);
    }
};
$service = new SiteSettingsService($store);
$assert($service->footerLinkVisible('/terms'), 'Legacy settings default to visible.');
$service->save('OJ', '', '', '', '', [], 0, ['/terms' => false]);
$assert(! $service->footerLinkVisible('/terms'), 'New visibility is persisted.');
$service->save('OJ updated', '', '', '', '', [], 1);
$assert(! $service->footerLinkVisible('/terms'), 'An older client must preserve saved visibility.');
try { $service->save('Stale', '', '', '', '', [], 1, ['/terms' => true]); throw new RuntimeException('Stale save accepted.'); }
catch (DomainException) { $assert(! $service->footerLinkVisible('/terms'), 'CAS conflict must not alter visibility.'); }
$handler = (new ReflectionClass(SaveSiteSettingsActionHandler::class))->newInstanceWithoutConstructor();
$parse = new ReflectionMethod($handler, 'footerVisibility');
$assert($parse->invoke($handler, new HttpRequest('POST', '/')) === null, 'Legacy request preserves current visibility.');
$allOff = $parse->invoke($handler, new HttpRequest('POST', '/', [], ['footer_visibility_present' => '1']));
$assert(count($allOff) === 6 && count(array_filter($allOff)) === 0, 'Unchecked controls explicitly disable all six links.');
foreach (array_keys(SiteSettings::FOOTER_LINKS) as $path) {
    $value = $parse->invoke($handler, new HttpRequest('POST', '/', [], ['footer_visibility_present' => '1', 'footer_visible' => [$path]]));
    $assert($value[$path] && count(array_filter($value)) === 1, 'Each link can be independently selected.');
}
foreach (['/terms', ['/unknown'], [['/terms']]] as $invalid) {
    try { $parse->invoke($handler, new HttpRequest('POST', '/', [], ['footer_visibility_present' => '1', 'footer_visible' => $invalid])); throw new RuntimeException('Invalid visibility accepted.'); }
    catch (InvalidArgumentException) { $assert(true, 'Invalid checkbox input rejected.'); }
}
$page = (new ReflectionClass(SiteSettingsPageHandler::class))->newInstanceWithoutConstructor();
$fields = (new ReflectionMethod($page, 'footerVisibilityFields'))->invoke($page, $service->current());
$assert(substr_count($fields, 'name="footer_visible[]"') === 6, 'The settings form must expose all six controls.');
$assert(substr_count($fields, ' checked') === 5, 'The form must restore the saved hidden selection.');
$assert(str_contains($fields, 'name="footer_visibility_present"'), 'The form must distinguish all-unchecked from old clients.');
echo "Footer settings: $checks checks passed.\n";
