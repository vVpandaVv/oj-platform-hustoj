<?php
declare(strict_types=1);
$root = dirname(__DIR__); $platform = dirname($root) . '/oj-integration-rebuild/oj-platform';
require_once $platform . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($platform);
require_once $root . '/src/Core/Autoloader.php'; \OjNetworkLab\Core\Autoloader::register();
define('OJ_NETWORK_LAB_VERSION', '1.4.0-rc.4'); define('OJ_NETWORK_LAB_FILE', $root . '/oj-network-lab.php'); define('ARRAY_A', 'ARRAY_A');
use CodingPanda\OjPlatform\AddonApi\V1 as Api;
use OjNetworkLab\Core\Plugin;
use OjNetworkLab\Addon\RestController;
use OjNetworkLab\Storage\Projects;
use OjNetworkLab\Storage\ProjectValidator;
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
function sanitize_text_field(string $v): string { return strip_tags($v); }
function is_user_logged_in(): bool { return $GLOBALS['actor'] > 0; }
function get_current_user_id(): int { return $GLOBALS['actor']; }
function current_user_can(string $cap): bool { return $GLOBALS['allowed'] && $cap === Api\AddonCapabilityKeyV1::wordpress('oj_network_lab_learn'); }
function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'test-nonce'; }
function get_option(string $name, mixed $fallback = null): mixed { return $name === 'oj_network_lab_schema' ? '1' : $fallback; }
function do_action(string $name, mixed ...$args): void {}
function rest_ensure_response(mixed $data): object { return new class($data) { public array $headers = []; public function __construct(public mixed $data) {} public function header(string $k, string $v): void { $this->headers[$k] = $v; } }; }
class WP_Error { public function __construct(public string $code, public string $message, public array $data = []) {} }
class WP_REST_Request {
    public function __construct(private array $params = [], private array $body = [], private string $nonce = 'test-nonce', private ?string $raw = null) {}
    public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
    public function get_body(): string { return $this->raw ?? json_encode($this->body); }
    public function get_header(string $key): string { return $this->nonce; }
    public function get_json_params(): array { return $this->body; }
}
class TestDb {
    public string $prefix = 'test_'; public int $insert_id = 0; public bool $locked = false; public PDO $pdo;
    public function __construct() { $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $this->pdo->exec('CREATE TABLE test_oj_network_lab_projects (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,client_id TEXT,title TEXT,project_json TEXT,project_hash TEXT,revision INTEGER,updated_at TEXT,UNIQUE(user_id,client_id))'); }
    public function prepare(string $sql, mixed ...$args): string { $i = 0; return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) { $v = $args[$i++]; return $m[0] === '%d' ? (string) (int) $v : $this->pdo->quote((string) $v); }, $sql); }
    public function get_var(string $sql): mixed { if (str_contains($sql, 'GET_LOCK')) { return $this->locked ? '0' : '1'; } if (str_contains($sql, 'RELEASE_LOCK')) { return '1'; } return $this->pdo->query($sql)->fetchColumn(); }
    public function get_results(string $sql, string $format): array { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function get_row(string $sql, string $format): ?array { $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC); return $row === false ? null : $row; }
    public function query(string $sql): int|false { return $this->pdo->exec($sql); }
    public function insert(string $table, array $data, array $formats): int { $stmt = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')'); $stmt->execute(array_values($data)); $this->insert_id = (int) $this->pdo->lastInsertId(); return $stmt->rowCount(); }
    public function delete(string $table, array $where, array $formats): int { return $this->query($this->prepare('DELETE FROM ' . $table . ' WHERE user_id=%d', $where['user_id'])); }
}
$GLOBALS['wpdb'] = new TestDb(); $GLOBALS['actor'] = 7; $GLOBALS['allowed'] = true;
Plugin::$runtime = new class implements Api\AddonRuntimeContextV1 {
    public function snapshot(): Api\AddonRuntimeSnapshotV1 { return new Api\AddonRuntimeSnapshotV1('oj-network-lab', OJ_NETWORK_LAB_VERSION, '>=2.5.9 <3.0.0', '2.5.9', '^1.9', '1.9.0', 'accepted', '', 'test-revision', [], 'test:network'); }
    public function contexts(): Api\AddonContextGatewayV1 { throw new LogicException(); }
    public function aiTasks(): Api\AddonAiTaskGatewayV1 { throw new LogicException(); }
};
$checks = 0;
function check(bool $value, string $label): void { global $checks; if (!$value) { throw new RuntimeException($label); } $checks++; echo 'OK ' . $label . PHP_EOL; }
function rejects(callable $fn, string $label, ?int $code = null): void { try { $fn(); } catch (Throwable $e) { check($code === null || $e->getCode() === $code, $label); return; } throw new RuntimeException('Expected rejection: ' . $label); }
$project = ['schema' => 1, 'engine' => OJ_NETWORK_LAB_VERSION, 'title' => '네트워크 테스트', 'seed' => 42, 'nodes' => [], 'links' => []];
$store = new Projects($GLOBALS['wpdb']); $client = '11111111-1111-4111-8111-111111111111';
check(RestController::authorize(new WP_REST_Request()) === true, 'accepted runtime and declared capability authorize');
$GLOBALS['actor'] = 0; check(RestController::authorize(new WP_REST_Request())->data['status'] === 401, 'guest rejected'); $GLOBALS['actor'] = 7;
check(RestController::authorize(new WP_REST_Request([], [], 'wrong'))->data['status'] === 403, 'nonce rejected');
$GLOBALS['allowed'] = false; check(RestController::authorize(new WP_REST_Request())->data['status'] === 403, 'capability checked'); $GLOBALS['allowed'] = true;
check(RestController::authorize(new WP_REST_Request([], [], 'test-nonce', str_repeat('x', 262145)))->data['status'] === 413, 'oversized request rejected before store');
$saved = $store->save(7, 0, ['clientId' => $client, 'project' => $project]); check($saved['revision'] === 1 && $saved['id'] > 0, 'create persists project');
check($store->save(7, 0, ['clientId' => $client, 'project' => $project])['id'] === $saved['id'] && count($store->list(7)) === 1, 'create retry is idempotent');
rejects(fn () => $store->read(8, $saved['id']), 'other user cannot read');
rejects(fn () => $store->save(8, $saved['id'], ['revision' => 1, 'project' => $project]), 'other user cannot update');
rejects(fn () => $store->delete(8, $saved['id'], 1), 'other user cannot delete');
$changed = $project; $changed['title'] = '수정';
rejects(fn () => $store->save(7, $saved['id'], ['revision' => 0, 'project' => $changed]), 'stale revision cannot overwrite', 409);
$GLOBALS['wpdb']->pdo->exec("UPDATE test_oj_network_lab_projects SET updated_at='2020-01-01 00:00:00'");
$updated = $store->save(7, $saved['id'], ['revision' => 1, 'project' => $changed]); check($updated['revision'] === 2 && $updated['project']['title'] === '수정', 'compare and swap increments revision');
check($store->save(7, $saved['id'], ['revision' => 1, 'project' => $changed])['revision'] === 2, 'lost update response can be retried');
rejects(fn () => $store->delete(7, $saved['id'], 1), 'stale delete rejected', 409);
$GLOBALS['wpdb']->locked = true; rejects(fn () => $store->save(7, 0, ['clientId' => $client, 'project' => $project]), 'concurrent save is bounded', 429); $GLOBALS['wpdb']->locked = false;
rejects(fn () => ProjectValidator::validate([...$project, 'nodes' => array_fill(0, 21, [])]), 'node count enforced');
rejects(fn () => ProjectValidator::validate([...$project, 'engine' => 'old']), 'engine compatibility enforced');
rejects(fn () => ProjectValidator::validate([...$project, '__proto__' => []]), 'prototype keys rejected');
for ($i = 2; $i <= 20; $i++) { $store->save(7, 0, ['clientId' => sprintf('%08d-1111-4111-8111-111111111111', $i), 'project' => $project]); }
rejects(fn () => $store->save(7, 0, ['clientId' => '99999999-1111-4111-8111-111111111111', 'project' => $project]), 'owner quota enforced', 429);
check(count($store->list(7)) === 20 && count($store->list(8)) === 0, 'lists remain owner scoped');
$response = RestController::dispatch('read', new WP_REST_Request(['id' => $saved['id']])); check($response->headers['Cache-Control'] === 'private, no-store', 'private response not cacheable');
$GLOBALS['actor'] = 8; check(RestController::dispatch('read', new WP_REST_Request(['id' => $saved['id']]))->data['status'] === 404, 'REST cannot expose another user project'); $GLOBALS['actor'] = 7;
$store->erase(7); check($store->list(7) === [], 'privacy erase removes own records');
$device = array_fill_keys(['vlans', 'acls', 'bindings', 'inside', 'outside', 'staticNat', 'addresses', 'services', 'policies', 'phase1', 'phase2'], []);
$device['saveMode'] = 'automatic'; $device['nat'] = null;
$managedNode = ['id' => 'n1', 'type' => 'router', 'profile' => 'ios', 'name' => 'RUNNING', 'interfaces' => [], 'routes' => [], 'rules' => [], 'device' => $device,
    'startup' => ['name' => 'STARTUP', 'interfaces' => [], 'routes' => [], 'device' => $device]];
$managed = [...$project, 'schema' => 2, 'nodes' => [$managedNode]];
$storedDevice = $store->save(8, 0, ['clientId' => $client, 'project' => $managed]);
$readDevice = Projects::response($store->read(8, $storedDevice['id']));
check($readDevice['project']['schema'] === 2 && $readDevice['project']['nodes'][0]['name'] === 'RUNNING' && $readDevice['project']['nodes'][0]['startup']['name'] === 'STARTUP', 'schema 2 stores running and startup configurations independently');
$badProfile = $managed; $badProfile['nodes'][0]['type'] = 'pc'; rejects(fn () => ProjectValidator::validate($badProfile), 'server rejects profile and device type mismatch');
$nestedStartup = $managed; $nestedStartup['nodes'][0]['startup']['startup'] = []; rejects(fn () => ProjectValidator::validate($nestedStartup), 'server rejects nested startup configuration');
$excessPeers = $managed; $excessPeers['nodes'][0]['device']['phase1'] = array_fill(0, 5, []); rejects(fn () => ProjectValidator::validate($excessPeers), 'server enforces managed tunnel limit');
$windowsNode = ['id' => 'n1', 'name' => 'WIN-PC', 'type' => 'pc', 'profile' => 'windows', 'forwarding' => false, 'tables' => [], 'chains' => [], 'rules' => [], 'routes' => [],
    'interfaces' => [['name' => 'eth0', 'kind' => 'ethernet', 'address' => '192.168.10.10/24']],
    'windows' => ['adapters' => [['name' => 'eth0', 'dhcp' => false, 'category' => 'Public', 'metric' => 25, 'dns' => '192.168.10.1', 'dnsAutomatic' => false, 'leaseDns' => '']],
        'profiles' => array_map(static fn (string $name): array => ['name' => $name, 'enabled' => true, 'inbound' => 'Block', 'outbound' => 'Allow'], ['Domain', 'Private', 'Public']),
        'rules' => [['name' => 'Web', 'displayName' => 'HTTP', 'enabled' => true, 'direction' => 'Inbound', 'action' => 'Allow', 'profiles' => ['Public'], 'protocol' => 'TCP', 'localAddress' => ['Any'], 'remoteAddress' => ['192.168.10.0/24'], 'localPort' => ['80-90'], 'remotePort' => ['Any'], 'icmpType' => ['Any'], 'interfaces' => ['eth0']]]]];
$windowsProject = [...$project, 'schema' => 2, 'nodes' => [$windowsNode]];
$storedWindows = $store->save(8, 0, ['clientId' => '22222222-2222-4222-8222-222222222222', 'project' => $windowsProject]);
check(Projects::response($store->read(8, $storedWindows['id']))['project']['nodes'][0]['windows'] === $windowsNode['windows'], 'Windows host and firewall configuration persist without device startup data');
$badWindows = $windowsProject; $badWindows['nodes'][0]['type'] = 'router'; rejects(fn () => ProjectValidator::validate($badWindows), 'server restricts Windows to PC and server');
$badWindows = $windowsProject; $badWindows['nodes'][0]['windows']['profiles'][0]['enabled'] = 'false'; rejects(fn () => ProjectValidator::validate($badWindows), 'server rejects non-Boolean Windows profile state');
$badWindows = $windowsProject; $badWindows['nodes'][0]['windows']['rules'][0]['localPort'] = ['90-80']; rejects(fn () => ProjectValidator::validate($badWindows), 'server rejects invalid Windows firewall port ranges');
$badWindows = $windowsProject; $badWindows['nodes'][0]['windows']['rules'] = array_fill(0, 65, $windowsNode['windows']['rules'][0]); rejects(fn () => ProjectValidator::validate($badWindows), 'server enforces Windows firewall rule limit');
$badWindows = $windowsProject; $badWindows['nodes'][0]['windows']['rules'][0]['remoteAddress'] = ['Any', '192.168.10.1']; rejects(fn () => ProjectValidator::validate($badWindows), 'server rejects mixed Any address conditions');
$badWindows = $windowsProject; $badWindows['nodes'][0]['windows']['rules'][0]['interfaces'] = ['eth9']; rejects(fn () => ProjectValidator::validate($badWindows), 'server checks Windows interface references');
$badWindows = $windowsProject; $badWindows['nodes'][0]['windows']['adapters'][0]['dns'] = 'not-an-ip'; rejects(fn () => ProjectValidator::validate($badWindows), 'server validates Windows adapter DNS addresses');
$badWindows = $windowsProject; $badWindows['nodes'][0]['windows']['rules'][0]['protocol'] = 'ICMPv4'; rejects(fn () => ProjectValidator::validate($badWindows), 'server rejects TCP port conditions on ICMP rules');
$badWindows = $windowsProject; $badWindows['nodes'][0]['windows']['rules'][] = [...$windowsNode['windows']['rules'][0], 'name' => 'web']; rejects(fn () => ProjectValidator::validate($badWindows), 'server rejects case-insensitive Windows rule name duplicates');
$badWindows = $windowsProject; $badWindows['nodes'][0]['startup'] = []; rejects(fn () => ProjectValidator::validate($badWindows), 'server rejects even empty startup data on Windows hosts');
$sixNode = ['id'=>'n1','name'=>'IPv6','type'=>'pc','profile'=>'linux','rules'=>[],'routes'=>[], 'forwarding6'=>false,
    'interfaces'=>[['name'=>'eth0','kind'=>'ethernet','ipv6'=>['2001:db8::10/64'],'mtu'=>1500]],
    'routes6'=>[['dest'=>'::/0','via'=>'2001:db8::1','dev'=>'eth0','metric'=>1024]]];
$sixProject = [...$project,'nodes'=>[$sixNode]];
$savedSix = $store->save(8,0,['clientId'=>'33333333-3333-4333-8333-333333333333','project'=>$sixProject]);
check(Projects::response($store->read(8,$savedSix['id']))['project']['nodes'][0] === $sixNode,'IPv6 addresses, MTU and routes round-trip through storage');
$bad = $sixProject; $bad['nodes'][0]['interfaces'][0]['ipv6']=['1::2::3/64']; rejects(fn()=>ProjectValidator::validate($bad),'server rejects invalid IPv6');
$bad = $sixProject; $bad['nodes'][0]['interfaces'][0]['mtu']=1279; rejects(fn()=>ProjectValidator::validate($bad),'server enforces minimum IPv6 MTU');
$dynamic = $managed; $dynamic['nodes'][0]['ospf']=['process'=>1,'routerId'=>'1.1.1.1','networks'=>[['prefix'=>'10.0.12.0/30','area'=>0]],'ports'=>[]];
$dynamic['nodes'][0]['bgp']=['asn'=>65001,'routerId'=>'1.1.1.1','networks'=>['192.0.2.0/24'],'neighbors'=>[['ip'=>'10.0.12.2','asn'=>65002,'weight'=>200,'shutdown'=>false]]];
$dynamic['nodes'][0]['startup']['bgp']=$dynamic['nodes'][0]['bgp'];
$savedDynamic=$store->save(8,0,['clientId'=>'44444444-4444-4444-8444-444444444444','project'=>$dynamic]);
check(Projects::response($store->read(8,$savedDynamic['id']))['project']['nodes'][0] === $dynamic['nodes'][0],'OSPF/BGP and startup policy persist exactly');
$bad=$dynamic; $bad['nodes'][0]['bgp']['neighbors'][0]['weight']=65536; rejects(fn()=>ProjectValidator::validate($bad),'server rejects BGP weight overflow');
$bad=$dynamic; $bad['nodes'][0]['startup']['bgp']['neighbors'][0]['shutdown']='false'; rejects(fn()=>ProjectValidator::validate($bad),'server validates startup dynamic routing');
$bad=$dynamic; $bad['nodes'][0]['bgp']['neighbors'][]=$bad['nodes'][0]['bgp']['neighbors'][0]; rejects(fn()=>ProjectValidator::validate($bad),'server rejects duplicate BGP peers');
$store->erase(8);
$builder = new \CodingPanda\OjPlatform\Kernel\Addon\AddonManifestBuilder();
$gateway = new class($builder) implements Api\AddonRegistrationGatewayV1 {
    public function __construct(private object $builder) {}
    public function apiVersion(): string { return '1.9.0'; }
    public function register(string $id, callable $build): void { if ($id !== 'oj-network-lab') throw new RuntimeException(); $build($this->builder); }
};
\OjNetworkLab\Addon\Registration::build($gateway); check(is_object($builder->close()), 'manifest satisfies real platform builder and public DTOs');
echo $checks . " backend and platform contract checks passed.\n";
