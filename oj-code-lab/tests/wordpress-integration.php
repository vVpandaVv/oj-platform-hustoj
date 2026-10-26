<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$configPath = getenv('OCL_TEST_CONFIG');
$config = json_decode(file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
if ($config['host'] !== '127.0.0.1' || (int) $config['port'] !== 33368) { throw new RuntimeException('Only the isolated test DB is allowed'); }
$dbName = 'ocl_test_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(3));
$db = new mysqli($config['host'], $config['user'], $config['password'], '', $config['port']);
$db->query("CREATE DATABASE {$dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->close();
define('ABSPATH', rtrim(getenv('OCL_TEST_WP'), '/\\') . '/');
define('DB_NAME', $dbName); define('DB_HOST', '127.0.0.1:33368'); define('DB_USER', $config['user']); define('DB_PASSWORD', $config['password']);
define('DB_CHARSET', 'utf8mb4'); define('DB_COLLATE', '');
define('WP_INSTALLING', true); define('WP_DEBUG', false); define('DISABLE_WP_CRON', true); define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_HOME', 'http://127.0.0.1:33369'); define('WP_SITEURL', WP_HOME);
foreach (array('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY') as $key) { define($key, bin2hex(random_bytes(32))); }
$table_prefix='ocltest_'; $_SERVER['HTTP_HOST']='127.0.0.1:33369'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/';
require ABSPATH . 'wp-settings.php'; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail', static fn() => false);
wp_install('Code Lab test', 'test_admin', 'test@example.invalid', false, '', bin2hex(random_bytes(16)));
$platform = getenv('OCL_TEST_PLATFORM');
require_once $platform . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($platform);
define('OJ_PLATFORM_VERSION', '2.0.0-dev.99'); define('OJ_PLATFORM_ADDON_API_VERSION', '1.1.0');
$testPlugin = getenv('OCL_TEST_PLUGIN') ?: dirname(__DIR__);
require $testPlugin . '/oj-code-lab.php';
use OjCodeLab\Database\Installer;
use OjCodeLab\Database\Notebook_Repository as Books;
use OjCodeLab\Database\Dataset_Repository as Datasets;
use OjCodeLab\Datasets\Dataset_Cache as Cache;
use OjCodeLab\Datasets\Upload_Store;
use OjCodeLab\Support\Request_Limits;
use OjCodeLab\Support\Privacy;
use OjCodeLab\Support\Secret_Cipher;
use OjCodeLab\Datasets\Kaggle_Settings;
use CodingPanda\OjPlatform\AddonApi\V1 as Api;
$checks=0; $metrics=array();
set_exception_handler(static function(Throwable $error):void {fwrite(STDERR, get_class($error).': '.$error->getMessage().' at '.$error->getFile().':'.$error->getLine().PHP_EOL);exit(1);});
function check(bool $ok,string $label):void { global $checks; if (!$ok) {throw new RuntimeException('FAIL: '.$label);} $checks++; echo "PASS $label\n"; }
function request(string $method,string $route,array $params=array()):WP_REST_Response {
    $r=new WP_REST_Request($method,'/oj-code-lab/v1'.$route);if($method==='GET'){$r->set_query_params($params);}else{$r->set_body_params($params);}return rest_do_request($r);
}
$sampleCount=count(glob($testPlugin.'/resources/sample-datasets/*.csv'));
oj_code_lab_activate();
check(get_option('oj_code_lab_db_version')===Installer::SCHEMA_VERSION && Installer::tables_exist(),'fresh activation creates verified schema');
check($sampleCount>0 && count(Datasets::all())===$sampleCount,'fresh activation seeds exactly the bundled samples');
$student=wp_insert_user(array('user_login'=>'learner_a','user_pass'=>wp_generate_password(),'user_email'=>'learner_a@example.invalid','role'=>'subscriber'));
$other=wp_insert_user(array('user_login'=>'learner_b','user_pass'=>wp_generate_password(),'user_email'=>'learner_b@example.invalid','role'=>'subscriber'));
foreach (array($student,$other) as $id) { (new WP_User($id))->add_cap(Api\AddonCapabilityKeyV1::wordpress('oj_code_lab_use')); }
$admin=new WP_User(1);foreach (array('oj_code_lab_review','oj_code_lab_manage_datasets','oj_code_lab_settings') as $cap) {$admin->add_cap(Api\AddonCapabilityKeyV1::wordpress($cap));}
wp_set_current_user(0); check(request('GET','/notebook')->get_status()===403,'anonymous notebook denied');
wp_set_current_user($student);
$book=request('GET','/notebook')->get_data();check($book['revision']===0,'new notebook revision zero');
$cells=array(array('id'=>'one','type'=>'code','code'=>'print("한글")','outputs'=>array(array('type'=>'input','text'=>'입력'))));
check(request('PUT','/notebook',array('formatVersion'=>2,'cells'=>$cells))->get_status()===428,'legacy client without revision denied');
$first=request('PUT','/notebook',array('formatVersion'=>2,'revision'=>0,'cells'=>$cells,'user_id'=>$other));check($first->get_status()===200 && $first->get_data()['revision']===1,'first save advances revision');
check(Books::find_for_user($other)['revision']===0,'forged target does not modify another learner');
check(request('PUT','/notebook',array('formatVersion'=>2,'revision'=>0,'cells'=>array()))->get_status()===409,'competing initial create cannot overwrite');
check(request('PUT','/notebook',array('formatVersion'=>2,'revision'=>1,'cells'=>$cells))->get_data()['revision']===2,'unchanged content still advances revision');
check(request('PUT','/notebook',array('formatVersion'=>2,'revision'=>1,'cells'=>array()))->get_status()===409 && Books::find_for_user($student)['cells']===$cells,'stale update preserves current content');
// Mixed notebook protocol protects text from old tabs and keeps the original bytes.
wp_set_current_user($other);
$noteText="  한글 학습 기록\n<script>alert('literal')</script> & **메모**\n다음 줄  ";
$mixed=array(array('id'=>'text-note','type'=>'text','code'=>$noteText,'outputs'=>array(array('type'=>'error','text'=>'ignored'))),array('id'=>'next-code','code'=>'print(2)','outputs'=>array(array('type'=>'stream','text'=>'2'))));
$mixedSaved=request('PUT','/notebook',array('formatVersion'=>2,'revision'=>0,'cells'=>$mixed));
check($mixedSaved->get_status()===200 && $mixedSaved->get_data()['formatVersion']===2,'mixed text and code notebook saves with format version');
$mixedRead=request('GET','/notebook')->get_data();
check($mixedRead['formatVersion']===2 && $mixedRead['cells'][0]['type']==='text' && $mixedRead['cells'][0]['code']===$noteText && $mixedRead['cells'][0]['outputs']===array() && $mixedRead['cells'][1]['type']==='code','text whitespace HTML literals and mixed order survive real DB round trip');
$oldSave=request('PUT','/notebook',array('revision'=>1,'cells'=>array()));
check($oldSave->get_status()===409 && $oldSave->get_data()['code']==='oj_code_lab_notebook_format_changed' && Books::find_for_user($other)===$mixedRead,'old client cannot overwrite mixed notebook or revision');
$mixedSummary=Books::summaries_for_users(array($other));
check($mixedSummary[$other]['cellCount']===2 && $mixedSummary[$other]['hasError']===false,'text participates in total count but not execution errors');
$unsupported=request('PUT','/notebook',array('formatVersion'=>2,'revision'=>1,'cells'=>array(array('type'=>'future','code'=>'keep'))));
check($unsupported->get_status()===400 && Books::find_for_user($other)===$mixedRead,'unknown cell type cannot silently replace mixed notebook');
$mixedExport=Privacy::export('learner_b@example.invalid');
$exportedBook=json_decode(html_entity_decode($mixedExport['data'][0]['data'][0]['value'],ENT_QUOTES,'UTF-8'),true);
check($exportedBook['formatVersion']===2 && $exportedBook['cells'][0]['code']===$noteText && $exportedBook['cells'][0]['type']==='text','privacy export preserves mixed text and its format');
$mixedErase=Privacy::erase('learner_b@example.invalid');
check($mixedErase['items_removed'] && Books::find_for_user($other)['revision']===0,'privacy erase removes mixed text notebook');
wp_set_current_user($student);
// Simultaneous conditional updates on two real MariaDB connections.
$c1=new mysqli('127.0.0.1',$config['user'],$config['password'],$dbName,33368);$c2=new mysqli('127.0.0.1',$config['user'],$config['password'],$dbName,33368);
$sql='UPDATE '.Installer::notebooks_table().' SET revision=revision+1 WHERE user_id='.(int)$student.' AND revision=2';
$c1->query($sql,MYSQLI_ASYNC);$c2->query($sql,MYSQLI_ASYNC);$pending=array($c1,$c2);$affected=array();
while($pending){$read=$pending;$error=$pending;$reject=$pending;mysqli_poll($read,$error,$reject,1);foreach($read as $connection){$connection->reap_async_query();$affected[]=$connection->affected_rows;$pending=array_values(array_filter($pending,static fn($x)=>$x!==$connection));}}
sort($affected);check($affected===array(0,1),'simultaneous CAS admits exactly one writer');$c1->close();$c2->close();
$wpdb->suppress_errors(true);
$wpdb->query('CREATE TRIGGER ocl_deny_write BEFORE UPDATE ON '.Installer::notebooks_table()." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test-only failure'");
check(request('PUT','/notebook',array('formatVersion'=>2,'revision'=>3,'cells'=>$cells))->get_status()===500,'real database write error becomes HTTP 500');
$wpdb->query('DROP TRIGGER ocl_deny_write');
// Migration from a populated 0.7.x shape must preserve cells and deleted samples.
Datasets::delete(Datasets::all()[0]['id']);
$wpdb->query('ALTER TABLE '.Installer::notebooks_table().' DROP COLUMN revision, DROP COLUMN cell_count, DROP COLUMN has_error');
update_option('oj_code_lab_db_version','0.7.1-dev');Installer::install();
check(get_option('oj_code_lab_db_version')==='2' && Books::find_for_user($student)['cells']===$cells,'populated old schema migrated without data loss');
check(count(Datasets::all())===$sampleCount-1,'migration preserves deliberate sample deletion');
$summary=Books::summaries_for_users(array($student));check($summary[$student]['cellCount']===1,'legacy summary backfilled');
$wpdb->query('DROP TABLE '.Installer::dataset_cache_table());Installer::maybe_install();check(Installer::tables_exist(),'missing cache table is repaired');
// Table creation failure must leave schema marker unadvanced and retryable.
$wpdb->query('ALTER TABLE '.Installer::notebooks_table().' DROP COLUMN revision');update_option('oj_code_lab_db_version','0.7.1-dev');
$denyAlter=static fn($sql)=>str_starts_with($sql,'ALTER TABLE')?'INVALID_TEST_ONLY_SQL':$sql;
add_filter('query',$denyAlter);Installer::install();remove_filter('query',$denyAlter);
check(get_option('oj_code_lab_db_version')==='0.7.1-dev' && (bool)get_option('oj_code_lab_install_error'),'failed schema change does not mark success');Installer::install();
check(get_option('oj_code_lab_db_version')==='2' && !get_option('oj_code_lab_install_error'),'failed migration retries successfully');
// Large classroom: summary query stays small even if each stored notebook has an image.
$large=array(array('code'=>'plot','outputs'=>array(array('type'=>'image','image'=>str_repeat('a',2_000_000)))));$ids=range(1000,1099);
foreach($ids as $id){Books::save_for_user($id,$large,0);} unset($large);
$start=microtime(true);$before=memory_get_usage(true);$all=Books::summaries_for_users($ids);$memory=memory_get_usage(true)-$before;
$metrics['100_learner_summary_ms']=round((microtime(true)-$start)*1000,2);$metrics['summary_memory_delta']=$memory;
check(count($all)===100 && $memory<8_000_000 && $metrics['100_learner_summary_ms']<2000,'100 notebooks (200MB raw) summary fits 8MB / 2s budget');
// UTC cache and strict combined quotas.
update_option('timezone_string','Asia/Seoul');Cache::put('utc','a,b');check(Cache::get('utc')==='a,b','UTC cache works on Seoul site');
$wpdb->query("UPDATE ".Installer::dataset_cache_table()." SET created_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR)");check(Cache::get('utc')===null,'expired cache is a miss');
\OjCodeLab\Jobs\Dataset_Cache_Cleanup::run();check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.Installer::dataset_cache_table())===0,'cron removes expired cache');
$cap=static fn()=>10;add_filter('oj_code_lab_cache_max_bytes',$cap);Cache::put('a','123456');Cache::put('b','123456');check((int)$wpdb->get_var('SELECT COALESCE(SUM(OCTET_LENGTH(content)),0) FROM '.Installer::dataset_cache_table())<=10,'cache quota enforced');remove_filter('oj_code_lab_cache_max_bytes',$cap);
$uploadUsage=(int)$wpdb->get_var('SELECT COALESCE(SUM(OCTET_LENGTH(content)),0) FROM '.Installer::uploads_table());
$uploadQuota=static fn()=>$uploadUsage+10;add_filter('oj_code_lab_uploads_max_bytes',$uploadQuota);
$withinQuota=Upload_Store::store_bytes('123456');$overQuota=Upload_Store::store_bytes('123456');
remove_filter('oj_code_lab_uploads_max_bytes',$uploadQuota);if(is_string($withinQuota)){Upload_Store::delete($withinQuota);}
check(is_string($withinQuota) && is_wp_error($overQuota),'upload total quota accepts remaining capacity and rejects overflow');
$limit=static fn()=>2;add_filter('oj_code_lab_requests_per_minute',$limit);delete_transient('oj_code_lab_rate_user_'.$student);
check(Request_Limits::check()===true && Request_Limits::check()===true && is_wp_error(Request_Limits::check()),'third request exceeds per-user rate limit');remove_filter('oj_code_lab_requests_per_minute',$limit);delete_transient('oj_code_lab_rate_user_'.$student);
$key=Upload_Store::store_bytes('x,y');$dataset=Datasets::create(array('label'=>'delete test','icon'=>'','source'=>'upload','uploadKey'=>$key,'fileName'=>'x.csv','createdBy'=>1));
$wpdb->query('CREATE TRIGGER ocl_deny_delete BEFORE DELETE ON '.Installer::datasets_table()." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test-only failure'");
check(is_wp_error(Datasets::delete($dataset)) && Upload_Store::read($key)==='x,y','failed delete preserves metadata and blob');$wpdb->query('DROP TRIGGER ocl_deny_delete');
check(Datasets::delete($dataset)===true && Upload_Store::read($key)===null,'successful delete removes metadata and blob atomically');
$readFixtureKey=Upload_Store::store_bytes("column\n1");
if(is_wp_error($readFixtureKey)){throw new RuntimeException('Cannot prepare isolated upload read fixture');}
$readFixtureId=Datasets::create(array('label'=>'read failure fixture','icon'=>'','source'=>'upload','uploadKey'=>$readFixtureKey,'fileName'=>'read-fixture.csv','createdBy'=>$other));
if(is_wp_error($readFixtureId)){throw new RuntimeException('Cannot prepare isolated dataset read fixture');}
$readFixture=array('id'=>$readFixtureId);
$encrypted=Secret_Cipher::encrypt('test-api-key');check(is_string($encrypted) && str_starts_with($encrypted,'v2:') && Secret_Cipher::decrypt($encrypted)==='test-api-key','GCM key round trip');
$raw=base64_decode(substr($encrypted,3));$raw[15]=chr(ord($raw[15])^1);check(Secret_Cipher::decrypt('v2:'.base64_encode($raw))==='','tampered ciphertext rejected');
$iv=random_bytes(16);$old=base64_encode($iv.openssl_encrypt('legacy-key','aes-256-cbc',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,$iv));
check(Secret_Cipher::decrypt($old)==='legacy-key','legacy CBC remains readable');
wp_set_current_user(1);check(Kaggle_Settings::save('test_user','test-key')===true && Kaggle_Settings::key()==='test-key','atomic credentials saved');
check(is_wp_error(Kaggle_Settings::save('changed_user','')),'changing account requires new key');Kaggle_Settings::clear();check(!Kaggle_Settings::is_configured(),'credential removal clears access');
check(count(Privacy::export('learner_a@example.invalid')['data'])===1,'WordPress personal-data export includes notebook');
check(Privacy::erase('learner_a@example.invalid')['items_removed'] && Books::find_for_user($student)['revision']===0,'personal-data erase removes notebook');
Books::save_for_user($other,$cells,0);require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($other);check(Books::find_for_user($other)['revision']===0,'WordPress user deletion removes owned notebook');
// A privacy request must not destroy shared teaching files.
check(count(Datasets::all())===$sampleCount && Datasets::find($readFixtureId)['createdBy']===0 && Upload_Store::read($readFixtureKey)==="column\n1",'shared teaching data retained and author unlinked on learner deletion');
// REST privilege checks with actual WordPress capabilities.
wp_set_current_user($student);check(request('POST','/settings/kaggle',array('username'=>'x','key'=>'x'))->get_status()===403,'learner cannot alter credentials');
check(request('DELETE','/datasets/recommended/1')->get_status()===403,'learner cannot delete shared dataset');
$meta=Datasets::create(array('label'=>'secret','icon'=>'','source'=>'url','sourceUrl'=>'https://example.invalid/data?serviceKey=TEST-ONLY','createdBy'=>1));
check(!str_contains(wp_json_encode(request('GET','/datasets/recommended')->get_data()),'TEST-ONLY'),'real REST learner list hides secret URL');
wp_set_current_user(1);check(str_contains(wp_json_encode(request('GET','/datasets/recommended')->get_data()),'TEST-ONLY'),'curator retains original source');
// Execute failed SELECT statements through real wpdb, not a repository double.
$datasetReadTable=Installer::datasets_table();$uploadReadTable=Installer::uploads_table();
$beforeReadFailure=array(count(Datasets::all()),(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$uploadReadTable));
$denyDatasetReads=static fn($sql)=>str_starts_with(ltrim($sql),'SELECT') && str_contains($sql,'FROM '.$datasetReadTable)
    ? 'INVALID_TEST_DATASET_READ_SQL' : $sql;
add_filter('query',$denyDatasetReads);
check(request('GET','/datasets/recommended')->get_status()===500,'real failed dataset list SELECT returns HTTP 500');
wp_set_current_user($student);
check(request('POST','/datasets/recommended/'.$readFixture['id'].'/import')->get_status()===500,'real failed dataset lookup returns HTTP 500 instead of 404');
wp_set_current_user(1);
check(request('POST','/datasets/recommended/restore-samples')->get_status()===500,'real failed sample-key SELECT stops restore with HTTP 500');
remove_filter('query',$denyDatasetReads);
check(array(count(Datasets::all()),(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$uploadReadTable))===$beforeReadFailure,'failed restore preserves dataset and upload row counts');
$denyUploadKey=static fn($sql)=>str_starts_with(ltrim($sql),'SELECT upload_key FROM '.$datasetReadTable)
    ? 'INVALID_TEST_UPLOAD_KEY_SQL' : $sql;
wp_set_current_user($student);add_filter('query',$denyUploadKey);
check(request('POST','/datasets/recommended/'.$readFixture['id'].'/import')->get_status()===500,'real failed upload-key SELECT returns HTTP 500');
remove_filter('query',$denyUploadKey);
$denyUploadRead=static fn($sql)=>str_starts_with(ltrim($sql),'SELECT content FROM '.$uploadReadTable)
    ? 'INVALID_TEST_UPLOAD_READ_SQL' : $sql;
add_filter('query',$denyUploadRead);
check(request('POST','/datasets/recommended/'.$readFixture['id'].'/import')->get_status()===500,'real failed upload content SELECT returns HTTP 500');
remove_filter('query',$denyUploadRead);
check(request('POST','/datasets/recommended/999999999/import')->get_status()===404,'genuinely missing dataset retains HTTP 404 after read failures');
wp_set_current_user(1);
// Published gateway contract exercised through real WordPress REST and summary SQL.
require __DIR__.'/platform-fixture.php';$gateway=new OclTestGateway();
\OjCodeLab\Addon\Runtime_Bridge::connect(new OclTestRuntime($gateway));
$page=request('GET','/contexts/class/own/notebooks');
check($page->get_status()===200 && count($page->get_data()['rows'])===50 && $page->get_data()['nextCursor']==='next' && !str_contains(wp_json_encode($page->get_data()),'outputs'),'teacher roster returns 50 summaries without code/images');
$secondPage=request('GET','/contexts/class/own/notebooks',array('cursor'=>'next','rosterRevision'=>'roster-r1'))->get_data()['rows'];check(count($secondPage)===50 && $secondPage[0]['userId']===1050,'second roster page returns remaining learners');
check(request('GET','/contexts/class/other/notebooks')->get_status()===403,'teacher cannot read another context');
check(request('GET','/contexts/class/own/notebooks/999')->get_status()===403,'removed or unrelated learner denied');
check(request('GET','/contexts/class/own/notebooks/1000')->get_status()===200,'authorized teacher reads full notebook');
check(request('GET','/contexts/class/own/notebooks',array('cursor'=>'next'))->get_status()===400,'incomplete cursor state rejected before SDK call');
check(request('GET','/contexts/class/own/notebooks',array('cursor'=>array()))->get_status()===400,'array cursor rejected');
$gateway->failed=true;check(request('GET','/contexts/class/own/notebooks')->get_status()===409,'gateway failure cannot silently return partial roster');$gateway->failed=false;
check(request('GET','/contexts/class/own/notebooks',array('cursor'=>'next','rosterRevision'=>'stale'))->get_status()===409,'changed roster revision rejected');
wp_set_current_user($student);check(request('GET','/contexts/class/own/notebooks')->get_status()===403,'learner cannot read teacher roster');wp_set_current_user(1);
// Use actual percent-encoded REST paths, as encodeURIComponent(context.id) does.
// WordPress keeps encoded path captures until our validate/sanitize callbacks run.
$opaqueGateway = new class($gateway) implements Api\AddonAssignmentRosterGatewayV1 {
    public string $contextType = 'class';
    public string $contextId = 'class:1';
    public array $authorizationIds = array();
    public array $rosterIds = array();
    public function __construct(private OclTestGateway $delegate) {}
    public function listTeachingContexts(Api\AddonTeachingContextQueryV1 $query): Api\AddonContextPageV1 { throw new LogicException('unused'); }
    public function snapshot(Api\AddonContextSnapshotRequestV1 $request): Api\AddonContextSnapshotResultV1 { throw new LogicException('unused'); }
    public function authorize(Api\AddonContextAccessRequestV1 $request): Api\AddonContextAccessDecisionV1 {
        $this->authorizationIds[] = $request->contextId();
        $allowed = $request->actorUserId() === 1 && $request->contextType() === $this->contextType && $request->contextId() === $this->contextId
            && ($request->learnerUserId() === null || in_array($request->learnerUserId(), range(1000, 1099), true));
        return new Api\AddonContextAccessDecisionV1($allowed, $allowed ? '' : 'addon_context_denied',
            $allowed ? new Api\AddonContextSnapshotV1($this->contextType, $this->contextId, 'Opaque test context', 'active', 'context-r1', 'teacher') : null, 'test-opaque-context');
    }
    public function listAssignmentLearners(Api\AddonAssignmentRosterQueryV1 $query): Api\AddonAssignmentLearnerPageV1 {
        $this->rosterIds[] = $query->contextId();
        if ($query->contextId() !== $this->contextId || $query->contextType() !== $this->contextType) { throw new LogicException('Context route was not decoded for the gateway'); }
        return $this->delegate->listAssignmentLearners($query);
    }
};
$opaqueRuntime = new class($opaqueGateway, (new OclTestRuntime($gateway))->snapshot()) implements Api\AddonRuntimeContextV1 {
    public function __construct(private Api\AddonContextGatewayV1 $gateway, private Api\AddonRuntimeSnapshotV1 $runtimeSnapshot) {}
    public function snapshot(): Api\AddonRuntimeSnapshotV1 { return $this->runtimeSnapshot; }
    public function contexts(): Api\AddonContextGatewayV1 { return $this->gateway; }
    public function aiTasks(): Api\AddonAiTaskGatewayV1 { throw new LogicException('unused'); }
};
\OjCodeLab\Addon\Runtime_Bridge::connect($opaqueRuntime);
$encodedRoster = request('GET', '/contexts/class/class%3A1/notebooks');
$metrics['encoded_class_roster_status'] = $encodedRoster->get_status();
check($encodedRoster->get_status() === 200 && count($encodedRoster->get_data()['rows']) === 50
    && end($opaqueGateway->authorizationIds) === 'class:1' && end($opaqueGateway->rosterIds) === 'class:1', 'encoded class context roster reaches gateway with decoded opaque ID');
$encodedNotebook = request('GET', '/contexts/class/class%3A1/notebooks/1000');
$metrics['encoded_class_notebook_status'] = $encodedNotebook->get_status();
check($encodedNotebook->get_status() === 200 && $encodedNotebook->get_data()['userId'] === 1000
    && end($opaqueGateway->authorizationIds) === 'class:1', 'encoded class learner detail reaches gateway with decoded opaque ID');
unset($encodedNotebook);
check(request('GET', '/contexts/class/class:1/notebooks')->get_status() === 200, 'raw opaque context ID remains compatible');
$opaqueGateway->contextType = 'group'; $opaqueGateway->contextId = 'group:1';
check(request('GET', '/contexts/group/group%3A1/notebooks')->get_status() === 200
    && request('GET', '/contexts/group/group%3A1/notebooks/1000')->get_status() === 200
    && end($opaqueGateway->authorizationIds) === 'group:1' && end($opaqueGateway->rosterIds) === 'group:1', 'encoded group context supports roster and learner detail');
$authorizationCount = count($opaqueGateway->authorizationIds); $invalidPathsDenied = true;
foreach (array('group%253A1', 'group%2F1', 'group%001') as $invalidId) {
    foreach (array('/notebooks', '/notebooks/1000') as $suffix) {
        $invalidPathsDenied = request('GET', '/contexts/group/' . $invalidId . $suffix)->get_status() === 400 && $invalidPathsDenied;
    }
}
check($invalidPathsDenied && count($opaqueGateway->authorizationIds) === $authorizationCount, 'double encoded slash and NUL context IDs are rejected before gateway access');
check(request('GET', '/contexts/group/group%3A2/notebooks')->get_status() === 403
    && request('GET', '/contexts/group/group%3A1/notebooks/999')->get_status() === 403, 'encoded routes retain context and learner authorization boundaries');
\OjCodeLab\Addon\Runtime_Bridge::connect(new OclTestRuntime($gateway));
check(request('GET','/datasets/search',array('q'=>array()))->get_status()===400,'array dataset query rejected');
// Real WordPress HTTP dispatch with deterministic upstream fixtures, not a live Kaggle account.
$upstreamBody="x,y\n1,2";$requests=array();$redirectTarget=null;
$httpFixture=static function($pre,$args,$url)use(&$upstreamBody,&$requests,&$redirectTarget){
    $requests[]=array('url'=>$url,'args'=>$args);
    if(count($requests)===1 && $redirectTarget){return array('response'=>array('code'=>302),'headers'=>array('location'=>$redirectTarget),'body'=>'');}
    return array('response'=>array('code'=>200),'headers'=>array(),'body'=>$upstreamBody);
};
add_filter('pre_http_request',$httpFixture,10,3);$client=new \OjCodeLab\Datasets\Kaggle_Client('fixture-user','fixture-key');
$redirectTarget='https://storage.googleapis.com/fixture.csv';$result=$client->download('owner','slug','data.csv');
check(!is_wp_error($result) && count($requests)===2 && isset($requests[0]['args']['headers']['Authorization']) && !isset($requests[1]['args']['headers']['Authorization']),'cross-origin Kaggle redirect strips authorization');
$requests=array();$redirectTarget='http://storage.googleapis.com/fixture.csv';check(is_wp_error($client->download('owner','slug','data.csv')) && count($requests)===1,'HTTPS downgrade redirect rejected');
$redirectTarget=null;$requests=array();$upstreamBody="PK\x03\x04binary";check(is_wp_error($client->download('owner','slug','data.csv')),'malformed compressed upstream bytes rejected');
$upstreamBody='<html>login required</html>';check(is_wp_error($client->download('owner','slug','data.csv')),'HTML login page cannot become CSV');
$upstreamBody='invalid JSON';check(is_wp_error($client->search('demo')),'invalid Kaggle JSON rejected');
// Real REST + real SQL with deterministic provider bytes, including actual ZIP decoding.
$formatsActor=get_current_user_id();wp_set_current_user($student);
$formatsRate=static fn()=>1000;add_filter('oj_code_lab_requests_per_minute',$formatsRate);
Kaggle_Settings::save('formats_fixture','fixture-key');
$formatNotebook=Books::find_for_user($student);
$jsonl="{\"text\":\"한글\",\"nested\":{\"ok\":true}}\n{\"text\":\"둘째\",\"n\":2}\n";
$upstreamBody=$jsonl;
$params=array('owner'=>'formats-owner','slug'=>'fixture','file'=>'records.jsonl');
$plain=request('POST','/datasets/import',$params);
check($plain->get_status()===200 && $plain->get_data()['format']==='jsonl' && $plain->get_data()['content']===$jsonl,'real REST imports JSONL with format and Unicode intact');
$zipTemp=tempnam(sys_get_temp_dir(),'ocl-formats-wp-');
try {
    $archive=new ZipArchive();$archive->open($zipTemp,ZipArchive::OVERWRITE);
    $archive->addFromString('nested/records.jsonl',$jsonl);$archive->addFromString('README.txt','fixture');$archive->close();
    $upstreamBody=file_get_contents($zipTemp);
} finally {unlink($zipTemp);}
$params['file']='bundle.zip';$beforeHttp=count($requests);
$zipped=request('POST','/datasets/import',$params);$again=request('POST','/datasets/import',$params);
check($zipped->get_status()===200 && $zipped->get_data()['fileName']==='records.jsonl' && $zipped->get_data()['format']==='jsonl','real REST decodes ZIP to the selected JSONL member');
check($again->get_data()===$zipped->get_data() && count($requests)===$beforeHttp+1,'real SQL cache retains ZIP member filename and JSONL format');
wp_set_current_user(1);
$registered=request('POST','/datasets/recommended',array('label'=>'ZIP JSONL fixture','source'=>'url','sourceUrl'=>'https://example.org/formats.zip'));
check($registered->get_status()===200,'curator registers a ZIP data URL through real REST');
$formatDatasetId=$registered->get_data()['id'];wp_set_current_user($student);$beforeHttp=count($requests);
$urlFirst=request('POST','/datasets/recommended/'.$formatDatasetId.'/import');
$urlSecond=request('POST','/datasets/recommended/'.$formatDatasetId.'/import');
check($urlFirst->get_status()===200 && $urlFirst->get_data()['format']==='jsonl' && $urlSecond->get_data()===$urlFirst->get_data() && count($requests)===$beforeHttp+1,'recommended URL ZIP round trip uses identical typed cache');
wp_set_current_user(1);
$jsonlUploadKey=Upload_Store::store_bytes($jsonl);
check(is_string($jsonlUploadKey),'JSONL upload content persists in the real uploads table');
$jsonlUploadId=Datasets::create(array('label'=>'Stored JSONL fixture','icon'=>'','source'=>'upload','uploadKey'=>$jsonlUploadKey,'fileName'=>'stored.jsonl','createdBy'=>1));
check(is_int($jsonlUploadId),'stored JSONL metadata retains its data filename in the real database');
wp_set_current_user($student);
$storedJsonl=request('POST','/datasets/recommended/'.$jsonlUploadId.'/import');
check($storedJsonl->get_status()===200 && $storedJsonl->get_data()['content']===$jsonl && $storedJsonl->get_data()['format']==='jsonl' && $storedJsonl->get_data()['fileName']==='stored.jsonl','stored upload JSONL imports through real REST with intact content and reader metadata');
$uploadRowsBefore=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Installer::uploads_table());
$smallPayload=static fn()=>32;add_filter('oj_code_lab_max_dataset_bytes',$smallPayload);
$rejectedPayload=Upload_Store::store_bytes(str_repeat('x',33));
remove_filter('oj_code_lab_max_dataset_bytes',$smallPayload);
check(is_wp_error($rejectedPayload) && $rejectedPayload->get_error_data()['status']===413 && (int)$wpdb->get_var('SELECT COUNT(*) FROM '.Installer::uploads_table())===$uploadRowsBefore && Upload_Store::read($jsonlUploadKey)===$jsonl,'oversized upload creates no row and preserves the previous JSONL bytes');
$upstreamBody=$jsonl;$params['file']='not-cached.jsonl';$beforeHttp=count($requests);
$cacheRowsBefore=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Installer::dataset_cache_table());
$smallCache=static fn()=>1;add_filter('oj_code_lab_cache_max_bytes',$smallCache);
$uncachedFirst=request('POST','/datasets/import',$params);$uncachedSecond=request('POST','/datasets/import',$params);
remove_filter('oj_code_lab_cache_max_bytes',$smallCache);
check($uncachedFirst->get_status()===200 && $uncachedSecond->get_data()===$uncachedFirst->get_data() && count($requests)===$beforeHttp+2 && (int)$wpdb->get_var('SELECT COUNT(*) FROM '.Installer::dataset_cache_table())===$cacheRowsBefore,'typed envelope over the cache quota still imports successfully without writing a cache row');
$upstreamBody="{}\n{private invalid record}\n";$params['file']='invalid.jsonl';
$invalid=request('POST','/datasets/import',$params);
check($invalid->get_status()===415 && !str_contains(wp_json_encode($invalid->get_data()),'private invalid'),'invalid JSONL REST error exposes no source text');
check(Books::find_for_user($student)===$formatNotebook,'data import and failures do not mutate stored notebook');
wp_set_current_user(0);check(request('POST','/datasets/import',$params)->get_status()===403,'anonymous JSONL import is denied');
wp_set_current_user($formatsActor);remove_filter('oj_code_lab_requests_per_minute',$formatsRate);
remove_filter('pre_http_request',$httpFixture,10);
$csv=\OjCodeLab\Support\Csv_Encoding::normalize(iconv('UTF-8','CP949',"이름,점수\n학생,90"));check($csv==="이름,점수\n학생,90",'CP949 conversion is lossless');
check(is_wp_error(\OjCodeLab\Support\Csv_Encoding::normalize("\xFF")),'invalid encoding cannot silently lose bytes');
check(request('POST','/settings/kaggle',array('username'=>array(),'key'=>'x'))->get_status()===400,'non-string credentials rejected');
Kaggle_Settings::save('fixture_user','fixture-key');$denyOption=static fn($sql)=>str_contains($sql, "UPDATE `{$GLOBALS['wpdb']->options}`")?'INVALID_TEST_ONLY_SQL':$sql;
add_filter('query',$denyOption);$clear=Kaggle_Settings::clear();remove_filter('query',$denyOption);check(is_wp_error($clear) && Kaggle_Settings::is_configured(),'failed credential removal is reported and remains retryable');Kaggle_Settings::clear();
check(get_option('oj_code_lab_last_settings_event')['event']==='kaggle_settings_cleared' && !str_contains(wp_json_encode(get_option('oj_code_lab_last_settings_event')),'fixture-key'),'settings audit marker contains no credentials');
\OjCodeLab\Jobs\Dataset_Cache_Cleanup::maybe_schedule();check(\OjCodeLab\Support\Operations::health()['status']==='good','WordPress site health validates schema crypto and cron');

// WordPress cookie auth rejects an explicitly invalid nonce.
$GLOBALS['wp_rest_auth_cookie']=true;$_REQUEST['_wpnonce']='invalid';$nonceError=rest_cookie_check_errors(null);unset($_REQUEST['_wpnonce']);check(is_wp_error($nonceError),'invalid REST cookie nonce rejected');
// Uninstall preserves records by default and deletes only with the explicit opt-in.
define('WP_UNINSTALL_PLUGIN', 'oj-code-lab/oj-code-lab.php');require $testPlugin.'/uninstall.php';
check(Installer::tables_exist() && !wp_next_scheduled('oj_code_lab_cache_cleanup'),'default uninstall preserves data and clears jobs');
define('OJ_CODE_LAB_DELETE_DATA',true);require $testPlugin.'/uninstall.php';
check(!Installer::tables_exist() && !get_option('oj_code_lab_db_version') && !get_option('oj_code_lab_kaggle_credentials'),'opt-in uninstall removes plugin tables and credentials');
Installer::install();

$metrics['bundled_samples']=$sampleCount;$metrics['checks']=$checks;$metrics['plugin']=OJ_CODE_LAB_VERSION;$metrics['wordpress']=$wp_version;$metrics['php']=PHP_VERSION;$metrics['db']=$wpdb->db_version();$metrics['database']=$dbName;
file_put_contents(getenv('OCL_TEST_REPORT'),wp_json_encode($metrics,JSON_PRETTY_PRINT));
echo "{$checks} real WordPress/MariaDB integration checks passed.\n";
