<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityProviderDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityProviderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentLearnerPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPrivacyDataDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSiteAssetConfigProviderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSiteAssetDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use CodingPanda\OjPlatform\Kernel\Addon\AcceptedAddonCatalog;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCatalogEntry;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCandidate;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifest;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifestValidator;
use CodingPanda\OjPlatform\Kernel\Addon\AddonNamespaceLedger;
use CodingPanda\OjPlatform\Kernel\Addon\CoreContributionSnapshot;
use CodingPanda\OjPlatform\Kernel\Addon\CoreContributionSnapshotProvider;
use CodingPanda\OjPlatform\Kernel\Addon\RealAddonIntegrationGateway;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;

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
        return new CoreContributionSnapshot([], [], [], [], [], [], [], []);
    }
};
$ledger = new class implements AddonNamespaceLedger {
    /** @var array<string, string> */
    private array $owners = [];

    public function ownerOf(string $namespace): string { return $this->owners[$namespace] ?? ''; }
    public function bind(string $namespace, string $addonId): void { $this->owners[$namespace] = $addonId; }
};
$activities = new class implements AddonActivityProviderV1 {
    public function search(AddonActivityQueryV1 $query): AddonActivityPageV1
    {
        unset($query);

        return new AddonActivityPageV1(true, [], null);
    }
};
$config = new class implements AddonSiteAssetConfigProviderV1 {
    public function config(int $actorUserId): array
    {
        return $actorUserId > 0 ? ['enabled' => true] : [];
    }
};

/** @param list<AddonSiteAssetDefinitionV1> $siteAssets */
$manifest = static function (
    string $addonId,
    string $namespace,
    string $handle,
    array $siteAssets
) use ($activities): AddonManifest {
    return new AddonManifest(
        $namespace,
        '1.0.0',
        dirname(__DIR__) . '/Fixtures/addons/' . $addonId . '/plugin.php',
        '^2.3',
        '^1.4',
        '통합 계약 검사',
        'example.test/integration',
        [],
        [],
        [],
        [],
        [],
        [new AddonAssetBundleDefinitionV1(
            'addon.' . $namespace . '.global-assets',
            $handle,
            [],
            [],
            [],
            '1.0.0'
        )],
        [],
        [],
        [new AddonActivityProviderDefinitionV1('addon.' . $namespace . '.activities', $activities)],
        $siteAssets,
        [new AddonPrivacyDataDefinitionV1(
            'addon.' . $namespace . '.privacy',
            '통합 기능',
            ['사용자 ID'],
            '관리자가 설정한 기간',
            '플랫폼 통합 문서에서 검토'
        )]
    );
};
$siteAsset = static fn (string $namespace, string $configId) => new AddonSiteAssetDefinitionV1(
    'addon.' . $namespace . '.global',
    'addon.' . $namespace . '.global-assets',
    $configId,
    $config
);
$validate = static function (array $candidates) use ($core, $ledger): array {
    return (new AddonManifestValidator('2.3.0', '1.4.0', $core, $ledger))->validate($candidates);
};

$accepted = $validate([AddonCandidate::manifest(
    'addon-a',
    $manifest('addon-a', 'integration_a', 'integration-a', [$siteAsset('integration_a', 'integration-a-config')])
)]);
$assert($accepted[0]->status() === 'accepted', 'Addon API 1.4 통합 기여를 승인해야 합니다.');
$counts = $accepted[0]->manifest()?->contributionCounts() ?? [];
$assert(($counts['activity_provider'] ?? 0) === 1, '활동 제공자 수를 런타임 스냅샷에 포함해야 합니다.');
$assert(($counts['site_asset'] ?? 0) === 1, '사이트 전역 자산 수를 런타임 스냅샷에 포함해야 합니다.');
$assert(($counts['privacy_data'] ?? 0) === 1, '개인정보 인벤토리 수를 런타임 스냅샷에 포함해야 합니다.');

$duplicateInside = $validate([AddonCandidate::manifest(
    'addon-a',
    $manifest('addon-a', 'integration_a', 'integration-a', [
        $siteAsset('integration_a', 'shared-config'),
        new AddonSiteAssetDefinitionV1(
            'addon.integration_a.secondary-global',
            'addon.integration_a.global-assets',
            'shared-config',
            $config
        ),
    ])
)]);
$assert($duplicateInside[0]->status() === 'rejected', '한 애드온 안의 설정 DOM ID 중복을 거부해야 합니다.');

$duplicateAcross = $validate([
    AddonCandidate::manifest(
        'addon-a',
        $manifest('addon-a', 'integration_a', 'integration-a', [$siteAsset('integration_a', 'shared-config')])
    ),
    AddonCandidate::manifest(
        'addon-b',
        $manifest('addon-b', 'integration_b', 'integration-b', [$siteAsset('integration_b', 'shared-config')])
    ),
]);
$assert(
    $duplicateAcross[0]->status() === 'rejected' && $duplicateAcross[1]->status() === 'rejected',
    '애드온 사이의 설정 DOM ID 중복을 양쪽 모두 거부해야 합니다.'
);

$missingBundle = $validate([AddonCandidate::manifest(
    'addon-a',
    $manifest('addon-a', 'integration_a', 'integration-a', [new AddonSiteAssetDefinitionV1(
        'addon.integration_a.global',
        'addon.integration_a.missing-assets',
        'integration-a-config',
        $config
    )])
)]);
$assert($missingBundle[0]->status() === 'rejected', '선언하지 않은 자산 묶음을 가리키는 사이트 자산을 거부해야 합니다.');

$runtimeActivities = new class implements AddonActivityProviderV1 {
    public function search(AddonActivityQueryV1 $query): AddonActivityPageV1
    {
        return new AddonActivityPageV1(true, [new AddonActivityV1(
            'provider:item',
            '제공자 활동',
            '',
            '/provider-item',
            'material',
            '제공 애드온',
            'application/pdf'
        )], null);
    }
};
$runtimeManifest = new AddonManifest(
    'runtime_source',
    '1.0.0',
    dirname(__DIR__) . '/Fixtures/addons/addon-a/plugin.php',
    '^2.3',
    '^1.4',
    '제공 애드온',
    'example.test/runtime',
    [],
    [],
    [],
    [],
    [new AddonPublicMenuDefinitionV1(
        'addon.runtime_source.menu',
        '권한 활동',
        'learning',
        'addon.runtime_source.page',
        'view',
        10,
        'learner'
    )],
    [],
    [],
    [],
    [new AddonActivityProviderDefinitionV1('addon.runtime_source.activities', $runtimeActivities)]
);
$runtimeCatalog = new AcceptedAddonCatalog();
$runtimeCatalog->commitAndFreeze([
    new AddonCatalogEntry('source-addon', $runtimeManifest, 'accepted', ''),
]);
$runtimeUsers = new class implements WordPressUserGateway {
    public function currentUser(): ?UserIdentity { return new UserIdentity(7, 'teacher', 'hidden@example.test', '교사'); }
    public function findById(int $userId): ?UserIdentity { return null; }
    public function findByLogin(string $userLogin): ?UserIdentity { return null; }
    public function findByEmail(string $email): ?UserIdentity { return null; }
    public function verifyCredentials(string $userLogin, string $password): ?UserIdentity { return null; }
    public function establishSession(int $userId, bool $remember): void {}
    public function createUser(string $userLogin, string $email, string $password, string $displayName, string $role): UserIdentity { throw new RuntimeException('unused'); }
    public function updateUser(int $userId, string $email, string $displayName): UserIdentity { throw new RuntimeException('unused'); }
    public function setRoles(int $userId, array $roles): void {}
    public function setPassword(int $userId, string $password): void {}
    public function invalidateSessions(int $userId): void {}
    public function requestPasswordReset(string $userLogin): void {}
    public function resetPasswordWithKey(string $userLogin, string $resetKey, string $newPassword): void {}
    public function deleteUser(int $userId): void {}
    public function findMany(array $userIds): array { return []; }
};
$runtimePermissions = new class implements Authorization {
    public bool $allowed = true;
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool
    {
        return $this->allowed && $userId === 7 && $capability === 'oj_addon_view';
    }
    public function canAny(int $userId, array $capabilities): bool { return false; }
    public function isSuperAdmin(int $userId): bool { return false; }
};
$runtimeRosters = new class implements AddonAssignmentRosterGatewayV1 {
    public function listTeachingContexts(AddonTeachingContextQueryV1 $query): AddonContextPageV1 { throw new RuntimeException('unused'); }
    public function authorize(AddonContextAccessRequestV1 $request): AddonContextAccessDecisionV1 { throw new RuntimeException('unused'); }
    public function snapshot(AddonContextSnapshotRequestV1 $request): AddonContextSnapshotResultV1 { throw new RuntimeException('unused'); }
    public function listAssignmentLearners(AddonAssignmentRosterQueryV1 $query): AddonAssignmentLearnerPageV1 { throw new RuntimeException('unused'); }
};
$runtimeRoutes = new class implements RouteUrlProvider {
    public function path(string $routeName, array $parameters = []): string { return '/activity/' . rawurlencode($routeName); }
};
$runtimeGateway = new RealAddonIntegrationGateway(
    'consumer-addon',
    $runtimeCatalog,
    $runtimeRoutes,
    (new ReflectionClass(ProblemCatalogService::class))->newInstanceWithoutConstructor(),
    (new ReflectionClass(SubmissionQueryService::class))->newInstanceWithoutConstructor(),
    $runtimeUsers,
    $runtimePermissions,
    $runtimeRosters,
    new AddonCorrelationIdFactory()
);
$runtimePage = $runtimeGateway->searchActivities(new AddonActivityQueryV1(7));
$assert($runtimePage->isSuccessful() && count($runtimePage->items()) === 2, '권한이 있는 메뉴와 활동 제공자를 함께 조회해야 합니다.');
$runtimePermissions->allowed = false;
$runtimePage = $runtimeGateway->searchActivities(new AddonActivityQueryV1(7));
$assert($runtimePage->isSuccessful() && count($runtimePage->items()) === 1, '권한이 없는 공개 메뉴는 통합 활동에서 숨겨야 합니다.');
$runtimePage = $runtimeGateway->searchActivities(new AddonActivityQueryV1(8));
$assert(! $runtimePage->isSuccessful(), '현재 사용자와 다른 actor ID의 통합 조회를 거부해야 합니다.');

echo "Addon API 1.4 통합 기여 검증 통과: {$checks}개 확인\n";
