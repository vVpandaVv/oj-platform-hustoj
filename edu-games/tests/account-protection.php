<?php
declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1 as Api;
use EduGames\Support\Legacy_Play_Capability;

$platform = rtrim($argv[1] ?? dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform', '/\\');
$mode = $argv[2] ?? 'global';
if (! is_file($platform . '/tests/Unit/account-management-boundaries.php') || ! in_array($mode, array('global', 'group'), true)) {
    fwrite(STDERR, "usage: php tests/account-protection.php <oj-platform dir> [global|group]\n");
    exit(2);
}
$GLOBALS['legacy_games_filters'] = array();
function add_filter(string $hook, callable $callback): bool {
    $GLOBALS['legacy_games_filters'][$hook][] = $callback;
    return true;
}
function legacyGamesFilters(string $hook, mixed $value): mixed {
    foreach ($GLOBALS['legacy_games_filters'][$hook] ?? array() as $callback) {
        $value = $callback($value);
    }
    return $value;
}
if ($mode === 'global') {
    require $platform . '/tests/Unit/account-management-boundaries.php';
    $GLOBALS['account_boundary_addon_filter'] = static fn (mixed $keys): mixed => legacyGamesFilters('oj_platform_unprivileged_addon_capabilities', $keys);
    $sourceUsers =& $GLOBALS['account_boundary_users'];
    $writes =& $GLOBALS['account_boundary_password_writes'];
    $performReset = static fn (string $nonce = 'valid') => $catalogResetHandler->handle($resetRequest(array(30), $nonce));
    $expectedPassword = 'BoundaryReset!42';
} else {
    require $platform . '/tests/Unit/group-account-boundaries.php';
    function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed {
        return legacyGamesFilters($hook, $value);
    }
    $sourceUsers =& $GLOBALS['group_boundary_users'];
    $writes =& $GLOBALS['group_boundary_password_writes'];
    $performReset = static fn (string $nonce = 'fixture-nonce') => $catalogReset->handle($resetRequest(30, $nonce));
    $expectedPassword = 'FixtureOnly!234';
}
require_once dirname(__DIR__) . '/src/Support/Legacy_Play_Capability.php';
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) { throw new RuntimeException($message); }
};
$runtime = static fn (string $id, string $status): Api\AddonRuntimeContextV1 => new class ($id, $status) implements Api\AddonRuntimeContextV1 {
    public function __construct(private readonly string $id, private readonly string $status) {}
    public function snapshot(): Api\AddonRuntimeSnapshotV1 {
        return new Api\AddonRuntimeSnapshotV1($this->id, '1.0.0', '^2.0', '2.5.8', '^1.0', '1.8.0', $this->status,
            $this->status === 'accepted' ? '' : 'addon_manifest_invalid', 'legacy-play-test', array(), 'legacy-play-test');
    }
    public function contexts(): Api\AddonContextGatewayV1 { throw new LogicException('Unused in this test.'); }
    public function aiTasks(): Api\AddonAiTaskGatewayV1 { throw new LogicException('Unused in this test.'); }
};
$legacyPlay = Api\AddonCapabilityKeyV1::wordpress('hustoj_edu_games_play');
$sourceUsers[30]->roles = array('subscriber');
$sourceUsers[30]->allcaps = array_fill_keys(array_merge(array('read', 'subscriber', $legacyPlay), $addonFixture['learner_keys']), true);
$baseCaps = $sourceUsers[30]->allcaps;
$writes = array();
$before = $performReset();
$check($writes === array(), 'Reproduce a protected learner carrying the stale play capability.');
$check(str_contains($before->body(), $legacyPlay) && str_contains($before->body(), 'auth.account_protection.capability_blocked'), 'Diagnostics identify the exact legacy key from production.');
foreach (array('rejected', 'incompatible', 'disabled', 'unregistered') as $status) {
    Legacy_Play_Capability::register($runtime('edu-games', $status));
    $check($GLOBALS['legacy_games_filters'] === array(), 'An unaccepted addon cannot register compatibility: ' . $status);
}
Legacy_Play_Capability::register($runtime('other-addon', 'accepted'));
$check($GLOBALS['legacy_games_filters'] === array(), 'Another addon cannot register the legacy compatibility.');
Legacy_Play_Capability::register($runtime('edu-games', 'accepted'));
$check(Legacy_Play_Capability::unprivileged(array()) === array($legacyPlay), 'Only the historical play key is classified as a learner grant.');
$check(Legacy_Play_Capability::unprivileged(array('oj_addon_fixture_learn', $legacyPlay)) === array('oj_addon_fixture_learn', $legacyPlay), 'Preserve and do not duplicate existing learner keys.');
$invalid = array('invalid' => 'oj_addon_fixture_learn');
$check(Legacy_Play_Capability::unprivileged($invalid) === $invalid, 'Do not normalize malformed associative filter values into a trusted list.');
$check(Legacy_Play_Capability::unprivileged(array('manage_options')) === array('manage_options', $legacyPlay), 'Preserve invalid existing entries for the platform to reject.');
$check($sourceUsers[30]->allcaps === $baseCaps, 'Registering compatibility must not mutate actual grants.');
$performReset('invalid-nonce');
$check($writes === array(), 'Compatibility cannot bypass nonce validation.');
$after = $performReset();
$check(($writes[30] ?? null) === $expectedPassword, 'Reset a learner with current and historical play rights through the real action.');
$check(str_contains($after->body(), '1명 모두 비밀번호 초기화에 성공') && str_contains($after->body(), 'type="password"'), 'Preserve successful reset and default password masking.');
$check(($after->headers()['Cache-Control'] ?? '') === 'private, no-store', 'Do not cache reset results.');
$check($sourceUsers[30]->allcaps === $baseCaps, 'Resetting must not remove, grant or rewrite capabilities.');
foreach (array(
    'legacy-manage' => array(array('subscriber'), array(Api\AddonCapabilityKeyV1::wordpress('hustoj_edu_games_manage') => true)),
    'current-manage' => array(array('subscriber'), array(Api\AddonCapabilityKeyV1::wordpress('edu_games_manage') => true)),
    'unknown-legacy' => array(array('subscriber'), array(Api\AddonCapabilityKeyV1::wordpress('hustoj_edu_games_admin') => true)),
    'platform-manager' => array(array('subscriber'), array('oj_manage_settings' => true)),
    'wordpress-manager' => array(array('subscriber'), array('edit_users' => true)),
    'administrator' => array(array('administrator'), array('manage_options' => true)),
) as $name => [$roles, $extra]) {
    $sourceUsers[30]->roles = $roles;
    $sourceUsers[30]->allcaps = $baseCaps + array_fill_keys($roles, true) + $extra;
    $writes = array();
    $response = $performReset();
    $check($writes === array(), 'Keep privileged targets protected: ' . $name);
    $check(str_contains($response->body(), 'auth.account_protection.'), 'Explain the protected target: ' . $name);
    $check(! str_contains($response->body(), $expectedPassword), 'Do not disclose a password for a protected target: ' . $name);
}
$sourceUsers[30]->roles = array('subscriber');
$sourceUsers[30]->allcaps = $baseCaps;
$GLOBALS['legacy_games_filters'] = array();
$writes = array();
$performReset();
$check($writes === array(), 'Removing compatibility restores the original fail-closed protection.');
define('EDU_GAMES_PATH', dirname(__DIR__) . '/');
define('EDU_GAMES_FILE', EDU_GAMES_PATH . 'edu-games.php');
define('EDU_GAMES_VERSION', (require EDU_GAMES_PATH . 'plugin-package.php')['version']);
require_once EDU_GAMES_PATH . 'src/Core/Autoloader.php';
EduGames\Core\Autoloader::register();
if (! function_exists('__')) {
    function __(string $text, string $domain = ''): string { return $text; }
}
$builder = new CodingPanda\OjPlatform\Kernel\Addon\AddonManifestBuilder();
EduGames\Addon\Oj_Platform_Registration::manifest($builder);
$manifest = $builder->close();
$check($GLOBALS['legacy_games_filters'] === array(), 'Collecting a manifest must not register unaccepted compatibility.');
$declared = array_map(static fn (Api\AddonCapabilityDefinitionV1 $capability): string => $capability->key(), $manifest->capabilities());
$check(in_array('edu_games_play', $declared, true) && ! in_array('hustoj_edu_games_play', $declared, true), 'Keep the current public capability contract unchanged.');
$ready = $builder->onReadyCallback();
$check(is_callable($ready), 'The real manifest has an accepted runtime callback.');
$ready($runtime('edu-games', 'accepted'));
$check($writes === array() && $sourceUsers[30]->allcaps === $baseCaps, 'The actual ready callback must not change credentials or grants.');
$performReset();
$check(($writes[30] ?? null) === $expectedPassword, 'The actual accepted manifest callback enables the real reset action.');
printf("Edu Games legacy account protection (%s): %d checks passed.\n", $mode, $checks);
