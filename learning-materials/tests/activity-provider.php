<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'CodingPanda\\OjPlatform\\AddonApi\\V1\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform/src/AddonApi/V1/' . substr($class, strlen($prefix)) . '.php';
    }
});
define('ARRAY_A', 'ARRAY_A');
foreach (array('Database/Installer', 'Materials/Material', 'Materials/Material_Catalog', 'Addon/Runtime_Bridge',
    'Materials/Material_Access', 'Materials/Material_Repository', 'Support/Capabilities', 'Addon/Material_Activity_Provider') as $class) {
    require dirname(__DIR__) . '/src/' . $class . '.php';
}
$role = 'learner';
function __(string $text, string $domain = ''): string { return $text; }
function get_current_user_id(): int { return 2; }
function is_user_logged_in(): bool { return $GLOBALS['role'] !== 'guest'; }
function current_user_can(string $key): bool {
    return $GLOBALS['role'] === 'manager' || ($GLOBALS['role'] === 'learner'
        && $key === CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::wordpress('learning_materials_view'));
}
function admin_url(string $path): string { return 'https://school.test/wp-admin/' . $path; }
function add_query_arg(array $query, string $url): string { return $url . '?' . http_build_query($query); }
$wpdb = new class {
    public string $prefix = 'wp_';
    public function get_results(string $sql, string $format): array {
        if (str_contains($sql, 'learning_material_groups')) { return array(array('material_id' => 2, 'group_id' => 'secret')); }
        return array(
            array('id' => 1, 'title' => '공개 PDF', 'extension' => 'pdf'),
            array('id' => 2, 'title' => '그룹 PDF', 'extension' => 'pdf'),
            array('id' => 3, 'title' => '초안 PDF', 'extension' => 'pdf', 'publication_status' => 'draft'),
            array('id' => 4, 'title' => '만료 PDF', 'extension' => 'pdf', 'publish_until' => '2000-01-01 00:00:00')
        );
    }
};
$provider = new LearningMaterials\Addon\Material_Activity_Provider();
foreach (array('guest' => 0, 'denied' => 0, 'learner' => 1, 'manager' => 2) as $role => $count) {
    $page = $provider->search(new CodingPanda\OjPlatform\AddonApi\V1\AddonActivityQueryV1(2, '', array('material'), 100));
    if (!$page->isSuccessful() || count($page->items()) !== $count) { throw new RuntimeException('Catalog access mismatch: ' . $role); }
}
$page = $provider->search(new CodingPanda\OjPlatform\AddonApi\V1\AddonActivityQueryV1(99, '', array('material'), 100));
if ($page->items() !== array()) { throw new RuntimeException('Another actor must not be queried'); }
echo "Activity catalog checks passed: login, capability, actor, group and publication boundaries.\n";
