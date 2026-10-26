<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavItemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCandidate;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifest;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifestValidator;
use CodingPanda\OjPlatform\Kernel\Addon\AddonNamespaceLedger;
use CodingPanda\OjPlatform\Kernel\Addon\CoreContributionSnapshot;
use CodingPanda\OjPlatform\Kernel\Addon\CoreContributionSnapshotProvider;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$core = new class implements CoreContributionSnapshotProvider {
    public function snapshot(): CoreContributionSnapshot
    {
        return new CoreContributionSnapshot([], [], [], ['classes', 'learning_support'], ['problems'], [], [], []);
    }
};
$ledger = new class implements AddonNamespaceLedger {
    public function ownerOf(string $namespace): string { return ''; }
    public function bind(string $namespace, string $addonId): void {}
};
$handler = new class implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1
    {
        return AddonPageResponseV1::html('화면', '<p>본문</p>');
    }
};

/** @param list<AddonConsoleLocalNavItemV1> $localNav */
$manifest = static function (array $localNav) use ($handler): AddonManifest {
    return new AddonManifest(
        'navcheck',
        '1.0.0',
        dirname(__DIR__) . '/Fixtures/addons/addon-a/plugin.php',
        '^2.0',
        '^1.3',
        '내비 검사',
        'example.test/support',
        [new AddonCapabilityDefinitionV1('navcheck_teach', '수업 운영', 'educator')],
        [
            new AddonConsolePageDefinitionV1('addon.navcheck.home', '/oj-console/addons/addon-a', $handler, 'navcheck_teach'),
            new AddonConsolePageDefinitionV1('addon.navcheck.records', '/oj-console/addons/addon-a/records', $handler, 'navcheck_teach'),
        ],
        [],
        [new AddonConsoleMenuDefinitionV1('addon.navcheck.menu', '내비 검사', 'learning_support', 'addon.navcheck.home', 'navcheck_teach', 10)],
        [],
        [],
        [],
        $localNav
    );
};
$status = static function (array $localNav) use ($core, $ledger, $manifest): string {
    $validator = new AddonManifestValidator('2.1.0', '1.3.0', $core, $ledger);
    $entries = $validator->validate([AddonCandidate::manifest('addon-a', $manifest($localNav))]);

    return $entries[0]->status();
};
$item = static fn (string $key, string $menu, string $route, int $order = 10): AddonConsoleLocalNavItemV1
    => new AddonConsoleLocalNavItemV1('addon.navcheck.' . $key, '구획 ' . $key, $menu, $route, $order);

$assert($status([]) === 'accepted', '로컬 내비가 없는 1.2 방식 매니페스트는 계속 승인돼야 합니다.');
$assert(
    $status([
        $item('a', 'addon.navcheck.menu', 'addon.navcheck.home'),
        $item('b', 'addon.navcheck.menu', 'addon.navcheck.records', 20),
    ]) === 'accepted',
    '메뉴 화면과 하위 화면을 담은 로컬 내비는 승인돼야 합니다.'
);
$assert(
    $status([$item('b', 'addon.navcheck.menu', 'addon.navcheck.records')]) === 'rejected',
    '메뉴 자신의 화면이 빠진 로컬 내비는 거부해야 합니다.'
);
$assert(
    $status([
        $item('a', 'addon.navcheck.menu', 'addon.navcheck.home'),
        $item('b', 'addon.navcheck.other-menu', 'addon.navcheck.records'),
    ]) === 'rejected',
    '없는 메뉴를 가리키는 구획은 거부해야 합니다.'
);
$assert(
    $status([
        $item('a', 'addon.navcheck.menu', 'addon.navcheck.home'),
        $item('b', 'addon.navcheck.menu', 'addon.navcheck.missing'),
    ]) === 'rejected',
    '없는 콘솔 화면을 가리키는 구획은 거부해야 합니다.'
);
$assert(
    $status([
        $item('a', 'addon.navcheck.menu', 'addon.navcheck.home'),
        $item('b', 'addon.navcheck.menu', 'addon.navcheck.home'),
    ]) === 'rejected',
    '같은 화면을 두 번 담은 로컬 내비는 거부해야 합니다.'
);
$assert(
    $status([
        $item('a', 'addon.navcheck.menu', 'addon.navcheck.home'),
        $item('a', 'addon.navcheck.menu', 'addon.navcheck.records'),
    ]) === 'rejected',
    '구획 키가 겹치면 거부해야 합니다.'
);
try {
    new AddonConsoleLocalNavItemV1('addon.other.a', '<b>굵게</b>', 'addon.other.menu', 'addon.other.home', 1);
    $assert(false, '태그가 든 구획 이름은 거부해야 합니다.');
} catch (InvalidArgumentException) {
    ++$checks;
}

echo "Addon API 1.3 로컬 내비 검증 통과: {$checks}개 확인\n";
