<?php
declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionQueryV1;
use CodingPanda\OjPlatform\Kernel\Addon\RealAddonCodeExecutionGateway;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory;
use CodingPanda\OjPlatform\Modules\Submissions\Application\CodeExecutionProbe;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionEngine;
use CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\Judge\MysqliCodeExecutionEngine;
use CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\Judge\CodeExecutionAccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeCredentials;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;

$root = dirname(__DIR__, 2);
require_once $root . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($root);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) { throw new RuntimeException($message); } ++$checks;
};
$probe = new CodeExecutionProbe(); $marker = 'OJVERIFY_' . str_repeat('e', 64);
$check($probe->inspect(13, "3\n" . $marker, '', $marker)['actual_output'] === "3\n", 'Probe removal preserves exact stdout bytes.');
$check($probe->inspect(13, $marker, '', $marker)['verdict'] === 'executed', 'Empty successful stdout is verified by its marker.');
foreach (['', '3', "3\n" . substr($marker, 0, 30), $marker . 'tail', str_repeat('x', 16001) . $marker, "\xFF" . $marker, "\0" . $marker, $marker . $marker] as $bad) {
    $result = $probe->inspect(13, $bad, '', $marker);
    $check($result['verdict'] !== 'executed' && $result['actual_output'] === '', 'Missing/partial/binary/truncated or duplicated markers fail closed.');
}
foreach ([7 => 'time_limit', 8 => 'memory_limit', 9 => 'output_limit', 10 => 'runtime_error'] as $code => $verdict) {
    $check($probe->inspect($code, 'diagnostic', '', $marker)['verdict'] === $verdict, 'Native terminal failures remain repairable failures.');
}
$error = $probe->inspect(11, '', "/home/judge/run1/Main.c:4: error " . $marker . "\nC:\\judge\\private\\Main.java:7: missing", $marker);
$check($error['verdict'] === 'compile_error' && str_contains($error['diagnostics'], 'Main.c:4') && str_contains($error['diagnostics'], 'Main.java:7')
    && ! str_contains($error['diagnostics'], '/home') && ! str_contains($error['diagnostics'], 'C:\\') && ! str_contains($error['diagnostics'], $marker),
    'Diagnostics preserve file/line and remove absolute server paths and nonce.');
foreach (['python', 'c', 'cpp', 'java'] as $language) {
    $source = $language === 'java' ? 'public class Main { public static void main(String[] a) {} }' : 'print(3)';
    $check(str_contains($probe->instrument($language, $source, $marker), $marker), 'Every supported probe embeds a private completion marker.');
}
$check($probe->instrument('java', 'public class Wrong {}', $marker) === 'public class Wrong {}', 'Malformed Java remains native-compiler repairable without a marker.');
$instrumented = $probe->instrument('cpp', 'int main(){ std::cout << 3; }', $marker);
$check(strpos($instrumented, 'std::cout') < strpos($instrumented, '#include'), 'Probe headers follow the original source and cannot provide its missing declarations.');
$check(str_contains($instrumented, 'std::cout.flush();fputs('), 'C++ independent stream buffers must flush before the final completion marker.');

$users = new class implements WordPressUserGateway {
    public int $actor = 7;
    public function currentUser(): ?UserIdentity { return $this->actor > 0 ? new UserIdentity($this->actor, 'teacher', '', '교사') : null; }
    public function findById(int $userId): ?UserIdentity { return null; }
    public function findByLogin(string $userLogin): ?UserIdentity { return null; }
    public function findByEmail(string $email): ?UserIdentity { return null; }
    public function verifyCredentials(string $userLogin, string $password): ?UserIdentity { return null; }
    public function establishSession(int $userId, bool $remember): void {}
    public function createUser(string $userLogin, string $email, string $password, string $displayName, string $role): UserIdentity { throw new LogicException(); }
    public function updateUser(int $userId, string $email, string $displayName): UserIdentity { throw new LogicException(); }
    public function setRoles(int $userId, array $roles): void {}
    public function setPassword(int $userId, string $password): void {}
    public function invalidateSessions(int $userId): void {}
    public function requestPasswordReset(string $userLogin): void {}
    public function resetPasswordWithKey(string $userLogin, string $resetKey, string $newPassword): void {}
    public function deleteUser(int $userId): void {}
    public function findMany(array $userIds): array { return []; }
};
$permissions = new class implements Authorization {
    public bool $staff = true;
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool { return $this->staff; }
    public function canAny(int $userId, array $capabilities): bool { return $this->staff; }
    public function isSuperAdmin(int $userId): bool { return false; }
};
$engine = new class implements CodeExecutionEngine {
    public array $sources = []; public array $identities = []; public array $records = []; public bool $available = true;
    public function isAvailable(): bool { return $this->available; }
    public function submit(string $identity, int $languageId, string $source, string $input): int {
        $id = $this->identities[$identity] ??= count($this->identities) + 1;
        $this->sources[$id] = $source; $this->records[$id] ??= ['result' => 0, 'output' => '', 'compile_error' => '', 'age_seconds' => 0]; return $id;
    }
    public function poll(int $id, string $identity): ?array { return ($this->identities[$identity] ?? null) === $id ? ($this->records[$id] ?? null) : null; }
    public function erase(string $identityPrefix): void { foreach ($this->identities as $identity => $id) { if (str_starts_with($identity, $identityPrefix . '_')) { unset($this->identities[$identity], $this->records[$id], $this->sources[$id]); } } }
    public function hasRecords(string $identityPrefix): bool { foreach ($this->identities as $identity => $id) { if (str_starts_with($identity, $identityPrefix . '_')) { return true; } } return false; }
};
$key = static fn (): string => str_repeat('secret-', 8);
$make = static fn (string $addon): RealAddonCodeExecutionGateway => new RealAddonCodeExecutionGateway($addon, $engine, $users, $permissions, new AddonCorrelationIdFactory(), $key);
$gateway = $make('oj-primm');
$check($gateway->availability()->isAvailable(), 'Staff can inspect execution availability.');
$request = new AddonCodeExecutionRequestV1(7, 'python', 'print(3)', '', 'run:1');
$first = $gateway->submit($request); $ticket = $first->payload()['ticket'];
$check($first->isSuccessful() && $first->payload()['status'] === 'pending', 'Generation yields a resumable execution ticket.');
$check($gateway->submit($request)->payload()['ticket'] === $ticket && count($engine->records) === 1, 'Same actor/addon/key/content reuse the native run.');
preg_match('/OJVERIFY_[a-f0-9]{64}/', $engine->sources[1], $m); $privateMarker = $m[0];
$engine->records[1] = ['result' => 13, 'output' => "3\n" . $privateMarker, 'compile_error' => '', 'age_seconds' => 2];
$result = $gateway->poll(new AddonCodeExecutionQueryV1(7, $ticket));
$check($result->payload()['verdict'] === 'executed' && $result->payload()['actual_output'] === "3\n", 'Native completed output is exposed only after a valid probe.');
$check(! str_contains(json_encode($result->payload()), $privateMarker), 'Private marker is absent from every public result field.');
$check(! $make('another-addon')->poll(new AddonCodeExecutionQueryV1(7, $ticket))->isSuccessful(), 'Another addon cannot consume a ticket.');
$users->actor = 8;
$check(! $gateway->poll(new AddonCodeExecutionQueryV1(8, $ticket))->isSuccessful(), 'Another teacher cannot consume a ticket.');
$check(! $gateway->submit($request)->isSuccessful(), 'Actor impersonation is rejected before dispatch.');
$users->actor = 7; $permissions->staff = false;
$check(! $gateway->poll(new AddonCodeExecutionQueryV1(7, $ticket))->isSuccessful() && ! $gateway->availability()->isAvailable(), 'Revoked staff permission blocks existing tickets.');
$permissions->staff = true;
$engine->records[1]['result'] = 0; $engine->records[1]['age_seconds'] = 301;
$result = $gateway->poll(new AddonCodeExecutionQueryV1(7, $ticket));
$check(! $result->isSuccessful() && $result->problem()->retryable(), 'A stalled judge is infrastructure failure, not AI-code failure.');
$engine->records[1]['result'] = 11; $engine->records[1]['compile_error'] = '/home/judge/run/Main.c:2: error';
$check($gateway->poll(new AddonCodeExecutionQueryV1(7, $ticket))->payload()['verdict'] === 'compile_error', 'Compile failures provide repair evidence.');
$erasure = new CodeExecutionAccountErasureParticipant($engine, $key);
$check($erasure->blockers(7) === [], 'Available judge supports private-record erasure.');
$erasure->anonymize(7, 'teacher', 'anonymous');
$check($erasure->verify(7, 'anonymous') && $engine->records === [], 'Teacher account erasure removes its private code/input/output records.');

$material=new \CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeMaterialV1('activity:1','content:v1','class','class:3','python',[['input'=>'2','expected_output'=>'4']]);
$context=new class implements \CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1 {
    public bool $active=true;public array $requests=[];
    public function listTeachingContexts(\CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1 $query):\CodingPanda\OjPlatform\AddonApi\V1\AddonContextPageV1 {throw new LogicException();}
    public function snapshot(\CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotRequestV1 $request):\CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotResultV1 {throw new LogicException();}
    public function authorize(\CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1 $request):\CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessDecisionV1 {
        $this->requests[]=$request;
        return new \CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessDecisionV1($this->active,$this->active?'':'addon_access_denied',
            $this->active?new \CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotV1('class','class:3','수업','active','rev1','learner'):null,'practice-check');
    }
};
$practice=new \CodingPanda\OjPlatform\Kernel\Addon\RealAddonPracticeGateway('oj-primm',$engine,$users,$context,new AddonCorrelationIdFactory(),$key,static fn():array=>['python']);
$permissions->staff=false;
$check(!$gateway->submit($request)->isSuccessful(),'Learner practice does not relax staff execution permission.');
$practiceRequest=new \CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeRequestV1(7,$material,'print(int(input())*2)',0,'practice:1');
$practiceRun=$practice->submit($practiceRequest);
$check($practiceRun->isSuccessful()&&$practiceRun->payload()['status']==='pending','A current enrolled learner can run server-owned practice material.');
$check(end($context->requests)->operation()==='participate','Practice reuses platform current participation authorization.');
$practiceTicket=$practiceRun->payload()['ticket'];
$check(!$gateway->poll(new AddonCodeExecutionQueryV1(7,$practiceTicket))->isSuccessful(),'Learner tickets never grant staff access.');
$otherMaterial=new \CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeMaterialV1('activity:2','content:v1','class','class:3','python',$material->tests());
$check(!$practice->poll(new \CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeQueryV1(7,$otherMaterial,$practiceTicket))->isSuccessful(),'Practice ticket is bound to its exact activity.');
$revisedMaterial=new \CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeMaterialV1('activity:1','content:v2','class','class:3','python',$material->tests());
$check(!$practice->poll(new \CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeQueryV1(7,$revisedMaterial,$practiceTicket))->isSuccessful(),'Changed activity content invalidates the practice ticket.');
$context->active=false;
$check(!$practice->poll(new \CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeQueryV1(7,$material,$practiceTicket))->isSuccessful(),'Membership revocation blocks an existing learner run.');
$context->active=true;$users->actor=8;
$check(!$practice->submit($practiceRequest)->isSuccessful(),'Practice rejects actor impersonation.');
$users->actor=7;$permissions->staff=true;
$settings=new class implements \CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager {
    public array $values=[];public array $revisions=[];
    public function read(string $key,mixed $default=null):\CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot{return new \CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot($this->revisions[$key]??0,$this->values[$key]??$default);}
    public function save(string $key,mixed $value,int $expectedRevision):\CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot {
        if(($this->revisions[$key]??0)!==$expectedRevision){throw new RuntimeException('Revision conflict');}
        $this->values[$key]=$value;$this->revisions[$key]=$expectedRevision+1;return $this->read($key);
    }
};
$support=new \CodingPanda\OjPlatform\Modules\Submissions\Application\CodeExecutionSupport($settings,new \CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSettingsProvider($settings),$engine,$key);
$check($support->allowed()===[],'A readable DB never implies any installed runtime has passed its probe.');
$probeResult=$support->probe(7,'python');
$check($probeResult['state']==='pending','A runtime check first dispatches its fixed native program.');
$probeRecord=$settings->values['private_execution']['checks']['python'];
$engine->records[$probeRecord['id']]=['result'=>13,'output'=>"OJ_RUNTIME_OK\n".$probeRecord['marker'],'compile_error'=>'','age_seconds'=>1];
$check($support->probe(7,'python')['state']==='passed'&&$support->allowed()===['python'],'Only exact successfully executed native probe output enables a language.');
$settings->save('submission',['disabled_language_mask'=>1<<6],0);
$check($support->allowed()===[],'A disabled site language is unavailable even with an older passed runtime check.');
$settings->save('submission',['disabled_language_mask'=>0],1);
$check($support->allowed()===[],'Changing and restoring language settings still requires a fresh runtime check.');
$support->savePolicy(1000,1000,9999,$support->policy()['revision']);
$policy=$support->policy();
$check($policy['global_pending']===100&&$policy['actor_pending']===20&&$policy['actor_hourly']===1000,'Runtime concurrency and request budgets are bounded settings.');

// Run production native prepared SQL over an isolated SQLite dialect adapter.
// MySQL advisory-lock concurrency still requires a real MySQL integration environment.
if (extension_loaded('mysqli') && extension_loaded('pdo_sqlite')) {
    final class ExecutionResultFixture extends mysqli_result {
        private int $position = 0;
        public function __construct(private array $data) {}
        public function fetch_assoc(): array|null|false { return $this->data[$this->position++] ?? null; }
        public function free(): void {}
    }
    final class ExecutionStatementFixture extends mysqli_stmt {
        public static bool $lockAvailable = true;
        private array $values = []; private array $data = [];
        public function __construct(private PDO $db, private string $sql) {}
        public function bind_param(string $types, mixed &...$vars): bool { $this->values = $vars; return true; }
        public function execute(?array $params = null): bool {
            $sql = $this->sql;
            if (str_contains($sql, 'GET_LOCK(')) { $this->data = [['locked' => self::$lockAvailable ? 1 : 0]]; return true; }
            if (str_contains($sql, 'RELEASE_LOCK(')) { $this->data = [['released' => 1]]; return true; }
            $sql = str_replace('LAST_INSERT_ID()', 'last_insert_rowid()', $sql);
            $sql = str_replace('CURRENT_TIMESTAMP - INTERVAL 1 DAY', "datetime('now', '-1 day')", $sql);
            $sql = str_replace('CURRENT_TIMESTAMP - INTERVAL 5 MINUTE', "datetime('now', '-5 minutes')", $sql);
            $sql = str_replace('CURRENT_TIMESTAMP - INTERVAL 1 HOUR', "datetime('now', '-1 hour')", $sql);
            $sql = str_replace('LEFT(`user_id`, 15)', 'substr(`user_id`,1,15)', $sql);
            $sql = str_replace('LEFT(`user_id`,15)', 'substr(`user_id`,1,15)', $sql);
            $sql = str_replace('TIMESTAMPDIFF(SECOND, `s`.`in_date`, CURRENT_TIMESTAMP)', "(strftime('%s','now') - strftime('%s',`s`.`in_date`))", $sql);
            $statement = $this->db->prepare($sql); $statement->execute($params ?? $this->values);
            $this->data = $statement->fetchAll(PDO::FETCH_ASSOC); return true;
        }
        public function get_result(): mysqli_result|false { return new ExecutionResultFixture($this->data); }
        public function close(): true { return true; }
    }
    final class ExecutionConnectionFixture extends mysqli {
        public function __construct(private PDO $db) {}
        public function prepare(string $query): mysqli_stmt|false { return new ExecutionStatementFixture($this->db, $query); }
        public function close(): true { return true; }
    }
    $db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->sqliteCreateFunction('regexp', static fn (string $pattern, string $value): int => preg_match('~' . $pattern . '~', $value));
    $db->exec('CREATE TABLE solution(solution_id INTEGER PRIMARY KEY AUTOINCREMENT,problem_id INTEGER,user_id TEXT,time INTEGER,memory INTEGER,in_date TEXT,result INTEGER,language INTEGER,ip TEXT,contest_id INTEGER,valid INTEGER,num INTEGER,code_length INTEGER)');
    foreach (['source_code' => 'source', 'source_code_user' => 'source', 'custominput' => 'input_text', 'runtimeinfo' => 'error', 'compileinfo' => 'error'] as $table => $column) {
        $db->exec('CREATE TABLE ' . $table . '(solution_id INTEGER PRIMARY KEY,' . $column . ' TEXT)');
    }
    $settings = new class implements JudgeConnectionSettingsProvider {
        public function current(): ?JudgeConnectionSettings { return new JudgeConnectionSettings('localhost', 3306, 'jol', new JudgeCredentials('test',''), new JudgeCredentials('test',''), false, null, true); }
    };
    $connector = new class($db) implements JudgeConnector {
        public function __construct(private PDO $db) {}
        public function read(JudgeConnectionSettings $settings): mysqli { return new ExecutionConnectionFixture($this->db); }
        public function write(JudgeConnectionSettings $settings): mysqli { return new ExecutionConnectionFixture($this->db); }
    };
    $native = new MysqliCodeExecutionEngine($settings, $connector);
    $identity = 'ojv' . str_repeat('a',12) . '_' . str_repeat('b',16);
    $check($native->isAvailable(), 'Native execution requires a readable custominput schema.');
    $id = $native->submit($identity, 6, 'SOURCE', "2\n");
    ExecutionStatementFixture::$lockAvailable = false;
    try { $native->submit($identity,6,'SOURCE',"2\n"); $check(false,'Busy submit lock must block.'); }
    catch (RuntimeException) { $check((int)$db->query('SELECT COUNT(*) FROM solution')->fetchColumn()===1,'Concurrent submit cannot create another execution while its identity is locked.'); }
    try { $native->erase(substr($identity,0,15)); $check(false,'Busy erase lock must block.'); }
    catch (RuntimeException) { $check((int)$db->query('SELECT COUNT(*) FROM source_code')->fetchColumn()===1,'Concurrent erasure cannot delete an execution while its sources are being prepared.'); }
    ExecutionStatementFixture::$lockAvailable = true;
    $row = $db->query('SELECT * FROM solution')->fetch(PDO::FETCH_ASSOC);
    $check((int) $row['problem_id'] === 0 && (int) $row['valid'] === 0 && (int) $row['contest_id'] === 0 && (int) $row['result'] === 0,
        'System run dispatch never targets a problem, learner score or class/contest.');
    $check($row['user_id'] === $identity && $row['user_id'] !== 'teacher' && $row['user_id'] !== '7', 'Native execution stores a pseudonym instead of the teacher account.');
    $check($native->submit($identity, 6, 'SOURCE', "2\n") === $id && (int) $db->query('SELECT COUNT(*) FROM solution')->fetchColumn() === 1, 'Native duplicate dispatch is idempotent.');
    $check($db->query('SELECT input_text FROM custominput')->fetchColumn() === "2\n", 'Custom input reaches the existing native engine unchanged.');
    $db->exec('UPDATE solution SET result=13'); $s = $db->prepare('INSERT INTO runtimeinfo VALUES(?,?)'); $s->execute([$id, "4\n"]);
    $check($native->poll($id, $identity)['output'] === "4\n", 'Native stdout is read through the execution contract.');
    $check($native->poll($id, 'ojv' . str_repeat('c',12) . '_' . str_repeat('d',16)) === null, 'Native record ownership is checked again at SQL boundary.');
    $db->exec('UPDATE solution SET result=3');
    try { $native->erase(substr($identity,0,15)); $check(false, 'Running worker erasure must wait.'); }
    catch (RuntimeException) { $check((int) $db->query('SELECT COUNT(*) FROM solution')->fetchColumn() === 1
        && (int) $db->query('SELECT COUNT(*) FROM runtimeinfo')->fetchColumn() === 1,
        'Erasure keeps worker ownership and logs until native terminal update, preventing orphan runtimeinfo.'); }
    $db->exec('UPDATE solution SET result=13');
    $native->erase(substr($identity,0,15));
    foreach (['solution','source_code','source_code_user','custominput','compileinfo','runtimeinfo'] as $table) {
        $check((int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() === 0, 'Private record erasure includes ' . $table);
    }
    $db->exec("INSERT INTO solution VALUES(100,42,'learner',0,0,datetime('now','-3 days'),4,6,'',0,1,-1,1)");
    $db->exec("INSERT INTO solution VALUES(101,0,'" . $identity . "',0,0,datetime('now','-3 days'),13,6,'',0,0,-1,1)");
    $native->submit('ojv' . str_repeat('a',12) . '_' . str_repeat('c',16),6,'NEXT','');
    $check((int) $db->query('SELECT COUNT(*) FROM solution WHERE solution_id=101')->fetchColumn() === 0
        && (int) $db->query('SELECT COUNT(*) FROM solution WHERE solution_id=100')->fetchColumn() === 1, 'One-day housekeeping removes only private system runs.');
    $recoveredIdentity = 'ojv' . str_repeat('d',12) . '_' . str_repeat('e',16);
    $s = $db->prepare("INSERT INTO solution VALUES(200,0,?,0,0,datetime('now','-10 minutes'),14,6,'',0,0,-1,1)"); $s->execute([$recoveredIdentity]);
    $recoveredId = $native->submit($recoveredIdentity,6,'RECOVERED','');
    $check($recoveredId === 200 && $native->poll($recoveredId,$recoveredIdentity)['age_seconds'] < 2,
        'Resuming an incomplete MC preparation resets the dispatch age instead of immediately timing out.');
    $s = $db->prepare("INSERT INTO solution VALUES(201,0,?,0,0,datetime('now','-3 days'),3,6,'',0,0,-1,1)");
    $s->execute(['ojv' . str_repeat('d',12) . '_' . str_repeat('f',16)]);
    $native->submit('ojv' . str_repeat('a',12) . '_' . str_repeat('f',16),6,'LATER','');
    $check((int) $db->query('SELECT COUNT(*) FROM solution WHERE solution_id=201')->fetchColumn() === 1,
        'Retention also keeps stalled worker ownership until the engine reaches a terminal state.');
    $status=$native->status();
    $check($status['pending']>0&&$status['stalled']===1,'Independent operational status reports pending and stalled native workers.');
    $check($native->housekeeping()['total']===$status['total'],'Independent maintenance can run without creating a new execution.');
    $limited=new MysqliCodeExecutionEngine($settings,$connector,static fn():array=>['global_pending'=>1,'actor_pending'=>1,'actor_hourly'=>1]);
    try{$limited->submit('ojv'.str_repeat('1',12).'_'.str_repeat('2',16),6,'NEW','');$check(false,'Global execution limit must block.');}
    catch(\CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionBusy){$check(true,'Native admission protects the shared judge queue before publishing a new private run.');}
}
// A site's AUTH salts may change while private learner code still awaits erasure.
$identityOptions=[];$identityAuthSalt=$key();
function get_option(string $name,mixed $default=null):mixed{return $GLOBALS['identityOptions'][$name]??$default;}
function add_option(string $name,mixed $value,string $deprecated='',bool $autoload=true):bool{if(array_key_exists($name,$GLOBALS['identityOptions'])){return false;}$GLOBALS['identityOptions'][$name]=$value;return true;}
function wp_salt(string $scheme='auth'):string{return $GLOBALS['identityAuthSalt'];}
$stableKey=new \CodingPanda\OjPlatform\Kernel\WordPress\WordPressCodeExecutionIdentityKey();
$seed=$stableKey->key();
$check(strlen($seed)===64&&$seed!==$key()&&!str_contains(json_encode($identityOptions),$key()),'Stable key is domain-separated and never persists the raw WordPress authentication secret.');
$legacyGateway=$make('upgrade-test');
$legacyTicket=$legacyGateway->submit(new AddonCodeExecutionRequestV1(7,'python','print(5)','','upgrade:1'))->payload()['ticket'];
$legacyId=(int)explode('.',$legacyTicket)[0];
preg_match('/OJVERIFY_[a-f0-9]{64}/',$engine->sources[$legacyId],$legacyMarker);
$engine->records[$legacyId]=['result'=>13,'output'=>"5\n".$legacyMarker[0],'compile_error'=>'','age_seconds'=>1];
$upgradedGateway=new RealAddonCodeExecutionGateway('upgrade-test',$engine,$users,$permissions,new AddonCorrelationIdFactory(),$key,[],null,static fn():string=>$stableKey->key());
$upgradeResult=$upgradedGateway->poll(new AddonCodeExecutionQueryV1(7,$legacyTicket));
$check($upgradeResult->isSuccessful()&&$upgradeResult->payload()['verdict']==='executed'&&$upgradeResult->payload()['actual_output']==="5\n",'Existing V1 tickets and legacy completion probes survive an upgrade before AUTH salt rotation.');
$legacyErasure=new CodeExecutionAccountErasureParticipant($engine,static fn():string=>$stableKey->key(),static fn():string=>wp_salt('auth'));
$legacyErasure->anonymize(7,'teacher','anonymous');
$check($legacyErasure->verify(7,'anonymous'),'Available pre-upgrade AUTH salt still supports legacy private-code erasure.');
$rotatingGateway=new RealAddonCodeExecutionGateway('rotation-test',$engine,$users,$permissions,new AddonCorrelationIdFactory(),
    static fn():string=>wp_salt('auth'),[],null,static fn():string=>$stableKey->key());
$rotatingRequest=new AddonCodeExecutionRequestV1(7,'python','print(9)','','rotation:1');
$beforeRotation=$rotatingGateway->submit($rotatingRequest)->payload()['ticket'];$recordCount=count($engine->records);
$identityAuthSalt=str_repeat('rotated-auth-',6);
$check($stableKey->key()===$seed,'AUTH salt rotation never loses the actor identity key.');
$check(!$rotatingGateway->poll(new AddonCodeExecutionQueryV1(7,$beforeRotation))->isSuccessful(),'Rotating AUTH salts invalidates old ticket signatures.');
$afterRotation=$rotatingGateway->submit($rotatingRequest);
$check($afterRotation->isSuccessful()&&count($engine->records)===$recordCount,'Resuming the same source after rotation reuses its stable native identity.');
$stableErasure=new CodeExecutionAccountErasureParticipant($engine,static fn():string=>$stableKey->key());
$stableErasure->anonymize(7,'teacher','anonymous');
$check($stableErasure->verify(7,'anonymous'),'Actor code and native outputs remain erasable after AUTH salt rotation.');
$identityOptions['oj_platform_private_execution_identity_key']=['format'=>1,'identity_key'=>'damaged'];
try{$stableKey->key();$check(false,'Damaged stable keys must not be replaced.');}catch(RuntimeException){$check(true,'A damaged key fails closed rather than silently losing old actor ownership.');}
echo "OJ private code execution: {$checks} checks passed.\n";
