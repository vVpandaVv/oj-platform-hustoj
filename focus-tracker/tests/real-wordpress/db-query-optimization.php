<?php
declare(strict_types=1);

/** Narrow native query-count comparison, not an HTTP capacity certification. */
if (PHP_SAPI !== 'cli') { exit(1); }
$mode = $argv[1] ?? '';
if ($mode === 'install-final-zip') { define('FS_METHOD', 'direct'); }
$workspace = dirname(__DIR__, 3);
$native = realpath($workspace . '/_notes/tmp/focus-tracker-db-optimization');
if ($native === false || strtolower(str_replace('\\', '/', $native)) !== 'd:/프로젝트/plugins/_notes/tmp/focus-tracker-db-optimization') { throw new RuntimeException('Disposable root guard failed.'); }
$config = json_decode((string) file_get_contents($native . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
if (($config['home'] ?? '') !== 'http://127.0.0.1:33918' || ($config['dbHost'] ?? '') !== '127.0.0.1:33917'
    || ($config['dbName'] ?? '') !== 'focus_acceptance_dbopt_20261028' || realpath($config['wpPath'] ?? '') !== realpath($native . '/wordpress')) { throw new RuntimeException('Disposable site guard failed.'); }
$identity = json_decode((string) file_get_contents($native . '/owned-server-identity.json'), true, 512, JSON_THROW_ON_ERROR);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$guard = new mysqli('127.0.0.1', $config['dbUser'], $config['dbPassword'], $config['dbName'], 33917);
$server = $guard->query('SELECT VERSION() AS version,@@version_comment AS vendor,@@server_uuid AS uuid,@@port AS port,@@datadir AS datadir,@@transaction_isolation AS isolationLevel,@@innodb_flush_log_at_trx_commit AS flushAtCommit,@@sync_binlog AS syncBinlog')->fetch_assoc();
foreach ($server as $key => $value) { if (! array_key_exists($key, $identity) || $identity[$key] !== $value) { throw new RuntimeException('Owned Oracle identity changed.'); } }
$guard->close();
$wpConfig = (string) file_get_contents($config['wpPath'] . '/wp-config.php');
foreach (array('DB_NAME'=>'dbName','DB_USER'=>'dbUser','DB_PASSWORD'=>'dbPassword','DB_HOST'=>'dbHost','WP_HOME'=>'home','WP_SITEURL'=>'home') as $constant=>$field) {
    $expected = 'define(' . var_export($constant, true) . ', ' . var_export($config[$field], true) . ');';
    if (substr_count($wpConfig, $expected) !== 1 || preg_match_all('/define\s*\(\s*[\'\"]' . $constant . '[\'\"]\s*,/', $wpConfig) !== 1) { throw new RuntimeException('Owned WordPress configuration changed.'); }
}
if (substr_count($wpConfig, "\$table_prefix = 'ftreal_';") !== 1 || file_exists($config['wpPath'].'/wp-content/db.php')) { throw new RuntimeException('Owned WordPress database configuration changed.'); }
unset($wpConfig);

$GLOBALS['dbopt_phase'] = 'bootstrap';
$GLOBALS['dbopt_counts'] = array();
$queryObserver = static function (string $sql): string {
    $phase = $GLOBALS['dbopt_phase'] ?? '';
    if ($phase === '') { return $sql; }
    if (! isset($GLOBALS['dbopt_counts'][$phase])) { $GLOBALS['dbopt_counts'][$phase] = array('statements' => 0, 'verbs' => array(), 'tableMentions' => array()); }
    $counts =& $GLOBALS['dbopt_counts'][$phase];
    ++$counts['statements'];
    $verb = preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|SHOW|START|COMMIT|ROLLBACK|SET|CREATE|ALTER)\b/i', $sql, $match) ? strtoupper($match[1]) : 'OTHER';
    $counts['verbs'][$verb] = ($counts['verbs'][$verb] ?? 0) + 1;
    if (preg_match('/^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([A-Za-z0-9_]+)`?/i',$sql,$target)) {
        foreach (array('focus_tracker_activities','focus_tracker_participations','focus_tracker_activity_links','focus_tracker_events','focus_tracker_scores','options') as $family) {
            if (str_ends_with($target[1],$family)) { $counts['writesByTable'][$family][$verb]=($counts['writesByTable'][$family][$verb]??0)+1;break; }
        }
    }
    if ($verb === 'SELECT' && preg_match('/(?:`?[A-Za-z0-9_]*(?:group_memberships|classes)`?)(?![A-Za-z0-9_])/i', $sql)) {
        $counts['contextSelects'] = ($counts['contextSelects'] ?? 0) + 1;
    }
    foreach (array('focus_tracker_activities','focus_tracker_participations','focus_tracker_activity_links','focus_tracker_events','focus_tracker_scores','focus_tracker_daily','focus_tracker_checks','options','group_memberships','learning_groups','class_enrollments','class_group_links','classes','users','usermeta') as $family) {
        if (preg_match('/(?:`?[A-Za-z0-9_]*' . preg_quote($family, '/') . '`?)(?![A-Za-z0-9_])/i', $sql)) { $counts['tableMentions'][$family] = ($counts['tableMentions'][$family] ?? 0) + 1; }
    }
    unset($counts);
    return $sql;
};
$GLOBALS['wp_filter']['query'][10][] = array('function' => $queryObserver, 'accepted_args' => 1);
$_SERVER['HTTP_HOST'] = '127.0.0.1:33918'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['SERVER_PORT'] = '33918';
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['DOCUMENT_ROOT'] = $config['wpPath'];
require $config['wpPath'] . '/wp-load.php';
$bootstrap = $GLOBALS['dbopt_counts']['bootstrap'] ?? array(); $GLOBALS['dbopt_phase'] = '';
foreach (array('DB_NAME'=>'dbName','DB_USER'=>'dbUser','DB_PASSWORD'=>'dbPassword','DB_HOST'=>'dbHost','WP_HOME'=>'home','WP_SITEURL'=>'home') as $constant=>$field) {
    if (! defined($constant) || constant($constant) !== $config[$field]) { throw new RuntimeException('Booted WordPress configuration does not match the owned site.'); }
}
$actualConnection = $wpdb->get_row('SELECT DATABASE() AS databaseName,VERSION() AS version,@@version_comment AS vendor,@@server_uuid AS uuid,@@port AS port,@@datadir AS datadir,@@transaction_isolation AS isolationLevel,@@innodb_flush_log_at_trx_commit AS flushAtCommit,@@sync_binlog AS syncBinlog',ARRAY_A);
if (! is_array($actualConnection) || ($actualConnection['databaseName']??null) !== $config['dbName'] || $wpdb->prefix !== 'ftreal_') { throw new RuntimeException('Booted WordPress is connected to a different database.'); }
unset($actualConnection['databaseName']);
foreach ($actualConnection as $key=>$value) { if (! array_key_exists($key,$identity) || $identity[$key] !== $value) { throw new RuntimeException('Booted WordPress Oracle identity changed.'); } }

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use CodingPanda\OjPlatform\Kernel\Boot\Kernel;
use CodingPanda\OjPlatform\Kernel\Composition\CompositionRoot;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressErrorReporter;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressHookRegistrar;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassSaveCommand;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupSaveCommand;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use FocusTracker\Database\Activity_Repository;
use FocusTracker\Database\Installer;
use FocusTracker\Privacy\Privacy_Guard;
use FocusTracker\Privacy\Personal_Data;
use FocusTracker\Reporting\Statistics_Service;
use FocusTracker\Support\Clock;
use FocusTracker\Tracking\Page_Context;

$checks = array();
function dbopt_check(bool $condition, string $label): void {
    $GLOBALS['checks'][$label] = $condition;
    if (! $condition) { throw new RuntimeException($label); }
}
function dbopt_save(string $name, array $value): void {
    global $native;
    $path = $native . '/artifacts/' . $name . '.json';
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) { throw new RuntimeException('Native report write failed.'); }
}
function dbopt_user(int $id): void { wp_set_current_user(0); wp_set_current_user($id)->get_role_caps(); }
function dbopt_rest(string $method, string $route, array $body = array()): WP_REST_Response {
    $request = new WP_REST_Request($method, '/focus-tracker/v1' . $route);
    $nonce = wp_create_nonce('wp_rest'); $request->set_header('X-WP-Nonce', $nonce); $request->set_query_params(array('_wpnonce' => $nonce));
    if ($method === 'GET') { $request->set_query_params($body + array('_wpnonce' => $nonce)); }
    else { $request->set_header('Content-Type','application/json'); $request->set_body((string) wp_json_encode($body)); }
    return rest_do_request($request);
}
function dbopt_ok(WP_REST_Response $response): array {
    if ($response->get_status() !== 200 || ! is_array($response->get_data())) { throw new RuntimeException('Unexpected native REST result: ' . $response->get_status() . '/' . (string) ($response->get_data()['code'] ?? 'unknown')); }
    return $response->get_data();
}
function dbopt_container(): object {
    $container = (new CompositionRoot(PluginEnvironment::fromPluginFile(OJ_PLATFORM_FILE, OJ_PLATFORM_VERSION, '6.6', '8.3'), new WordPressHookRegistrar(), new WordPressErrorReporter()))->build();
    $container->get(Kernel::class)->boot(); return $container;
}
function dbopt_activity(int $teacher, string $type, string $id, string $name, int $start, int $end): int {
    dbopt_user($teacher);
    $result = dbopt_ok(dbopt_rest('POST','/activities',array('name'=>$name,'context_type'=>$type,'context_id'=>$id,'problem_ids'=>null,'starts_at'=>gmdate('c',intdiv($start,1000)),'ends_at'=>gmdate('c',intdiv($end,1000)))));
    return (int) $result['id'];
}
function dbopt_packet(array $fixture, int $user, string $uid): array {
    $start = $fixture['referenceNow'] - 110000;
    $page = Page_Context::issue($user, '/oj-addons/learning-materials/1', array('group_id'=>$fixture['groupId']), $start);
    if (! is_array($page)) { throw new RuntimeException('Native page signing failed.'); }
    return array('user_id'=>$user,'problem_id'=>null,'page_context'=>$page['token'],'context_type'=>'group','context_id'=>'group:'.$fixture['groupId'],'session_uid'=>$uid,'session_started_at'=>$start,'is_final'=>true,
        'events'=>array(array('seq'=>1,'type'=>'session_start','occurred_at'=>$start,'meta'=>array('visible'=>true,'focused'=>true)),array('seq'=>2,'type'=>'heartbeat','occurred_at'=>$start+1000,'meta'=>array('visible'=>true,'focused'=>true)),array('seq'=>3,'type'=>'heartbeat','occurred_at'=>$start+15000,'meta'=>array('visible'=>true,'focused'=>true)),array('seq'=>4,'type'=>'session_end','occurred_at'=>$start+30000)));
}
function dbopt_gate_body(array $fixture, int $user, string $type): array {
    $query = $type === 'general' ? array() : array($type.'_id'=>$fixture[$type.'Id']);
    $page = Page_Context::issue($user, '/oj-addons/learning-materials/1', $query, $fixture['referenceNow']);
    return array('user_id'=>$user,'problem_id'=>null,'page_context'=>$page['token'],'context_type'=>$type,'context_id'=>$type==='general'?null:$type.':'.$fixture[$type.'Id']);
}
function dbopt_measure(string $name, callable $callback, callable $business): array {
    global $wpdb;
    $GLOBALS['dbopt_counts'][$name] = array('statements'=>0,'verbs'=>array(),'tableMentions'=>array());
    $before = $wpdb->num_queries; $started = microtime(true); $GLOBALS['dbopt_phase'] = $name;
    try { $value = $callback(); } finally { $GLOBALS['dbopt_phase'] = ''; }
    $counts = $GLOBALS['dbopt_counts'][$name];
    dbopt_check($wpdb->num_queries - $before === $counts['statements'], 'Native query observer accounts for every ' . $name . ' statement');
    dbopt_check($wpdb->last_error === '', 'Native SQL ' . $name . ' has no database error');
    $result = $business($value);
    return array('queries'=>$counts,'elapsedMs'=>round((microtime(true)-$started)*1000,3),'elapsedScope'=>'The whole measured operation plus business-result normalization and assertions; not SQL wall time.','business'=>$result,'businessSha256'=>hash('sha256',(string)wp_json_encode($result)));
}
function dbopt_explain(array $fixture): array {
    global $wpdb;
    $captured = null;
    $capture = static function (string $sql) use (&$captured): string { if (str_starts_with(ltrim($sql),'SELECT ') && str_contains($sql,'FROM '.Installer::activities_table()) && str_contains($sql,'ends_at >=')) { $captured=$sql; } return $sql; };
    add_filter('query',$capture,999,1);
    $rows = Activity_Repository::find_overlapping_for_context('group','group:'.$fixture['groupId'],Clock::ms_to_mysql($fixture['referenceNow']-86400000),Clock::ms_to_mysql($fixture['referenceNow']+86400000));
    remove_filter('query',$capture,999);
    if (! is_string($captured)) { throw new RuntimeException('Production schedule SQL was not captured.'); }
    $plan = $wpdb->get_results('EXPLAIN '.$captured,ARRAY_A);
    $safePlan = array_map(static fn(array $row):array=>array_intersect_key($row,array_flip(array('select_type','type','possible_keys','key','key_len','rows','filtered','Extra'))),$plan);
    $tree = (string) $wpdb->get_var('EXPLAIN ANALYZE '.$captured); $operators=array();
    foreach (preg_split('/\r?\n/',$tree) as $line) {
        if (! preg_match('/actual time=([0-9.e+-]+)\.\.([0-9.e+-]+) rows=([0-9.e+-]+) loops=([0-9.e+-]+)/i',$line,$match)) { continue; }
        $operator = 'other'; foreach(array('Index range scan','Index lookup','Table scan','Filter','Sort') as $candidate){if(stripos($line,$candidate)!==false){$operator=$candidate;break;}}
        $operators[]=array('operator'=>$operator,'firstRowMs'=>(float)$match[1],'lastRowMs'=>(float)$match[2],'rows'=>(float)$match[3],'loops'=>(float)$match[4]);
    }
    return array('matchingRows'=>count($rows),'matchingIds'=>array_column($rows,'id'),'plan'=>$safePlan,'actualOperators'=>$operators,'scope'=>'Production SQL executed by EXPLAIN ANALYZE; only operator/row/cost metadata is retained, no SQL text or conditions.');
}
function dbopt_snapshot(): array {
    global $config;
    $db=new mysqli('127.0.0.1',$config['dbUser'],$config['dbPassword'],$config['dbName'],33917);$db->set_charset('utf8mb4');
    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    $excluded=array();$tables=array();$engines=array();
    try{
        foreach($db->query('SHOW FULL TABLES')->fetch_all(MYSQLI_NUM)as$table){
            if($table[1]!=='BASE TABLE'){continue;}$name=$table[0];dbopt_check(preg_match('/^[A-Za-z0-9_]+$/D',$name)===1,'Every snapshot table is a safe identifier');
            $columns=$db->query('SHOW COLUMNS FROM `'.$name.'`')->fetch_all(MYSQLI_ASSOC);$rowHashes=array();
            $rows=$db->query('SELECT * FROM `'.$name.'`',MYSQLI_USE_RESULT);
            while($row=$rows->fetch_assoc()){
                if($name==='ftreal_options'&&(in_array($row['option_name'],array('cron','doing_cron','auto_updater.lock','core_updater.lock','focus_tracker_schema_health','focus_tracker_schema_retry','focus_tracker_schema_maintenance_lock','focus_tracker_cleanup_status','focus_tracker_cleanup_progress','focus_tracker_cleanup_lock'),true)||str_starts_with($row['option_name'],'_transient_')||str_starts_with($row['option_name'],'_site_transient_'))){
                    $kind=str_starts_with($row['option_name'],'_site_transient_')?'_site_transient_*':(str_starts_with($row['option_name'],'_transient_')?'_transient_*':$row['option_name']);$excluded[$kind]=($excluded[$kind]??0)+1;continue;
                }
                ksort($row,SORT_STRING);$rowHashes[]=hash('sha256',serialize($row));
            }$rows->free();sort($rowHashes,SORT_STRING);$indexes=array();
            foreach($db->query('SHOW INDEX FROM `'.$name.'`')->fetch_all(MYSQLI_ASSOC)as$index){$key=$index['Key_name'].'|'.str_pad((string)$index['Seq_in_index'],4,'0',STR_PAD_LEFT);$indexes[$key]=array_intersect_key($index,array_flip(array('Key_name','Non_unique','Seq_in_index','Column_name','Sub_part','Index_type','Collation','Visible','Expression')));}ksort($indexes,SORT_STRING);
            $tables[$name]=array('rows'=>count($rowHashes),'rowsSha256'=>hash('sha256',implode("\n",$rowHashes)),'columnsSha256'=>hash('sha256',serialize($columns)),'indexesSha256'=>hash('sha256',serialize($indexes)));
            $status=$db->query("SHOW TABLE STATUS WHERE Name='".$db->real_escape_string($name)."'")->fetch_assoc();$engines[$name]=$status['Engine']??null;
        }
        $settings=array();foreach($db->query("SELECT option_name,option_value FROM ftreal_options WHERE option_name IN ('focus_tracker_settings','focus_tracker_student_participation_defaults','focus_tracker_statistics_coverage_since','ftreal_user_roles') ORDER BY option_name")->fetch_all(MYSQLI_ASSOC)as$row){$settings[$row['option_name']]=hash('sha256',$row['option_value']);}
        $db->commit();ksort($tables,SORT_STRING);ksort($engines,SORT_STRING);ksort($excluded,SORT_STRING);
        return array('tables'=>$tables,'engines'=>$engines,'settingsAndRolesSha256'=>$settings,'excludedRuntimeOptionCounts'=>$excluded,'scope'=>'Every database row, column and index definition is digested; only documented health/retry/cleanup/cron/upgrader/transient runtime options are excluded. No row content or per-row hashes are exported.');
    }catch(Throwable$error){$db->rollback();throw$error;}finally{$db->close();}
}
function dbopt_runtime_files(): array {
    $root=WP_PLUGIN_DIR.'/focus-tracker';$files=array();
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS))as$file){dbopt_check(!$file->isLink(),'The installed package has no symbolic links');if($file->isFile()){$files[str_replace('\\','/',substr($file->getPathname(),strlen($root)+1))]=hash_file('sha256',$file->getPathname());}}
    ksort($files,SORT_STRING);return$files;
}

try {
    dbopt_check(defined('OJ_PLATFORM_VERSION') && OJ_PLATFORM_VERSION === '2.5.5', 'The official Platform 2.5.5 runtime boots');
    dbopt_check(Installer::ready(), 'The installed Focus store is healthy');
    $fixturePath = $native . '/artifacts/fixture.private.json';
    if ($mode === 'warmup') {
        dbopt_save('warmup-'.FOCUS_TRACKER_VERSION,array('passed'=>true,'version'=>FOCUS_TRACKER_VERSION,'bootstrapQueries'=>$bootstrap));
    } elseif ($mode === 'prepare') {
        dbopt_check(FOCUS_TRACKER_VERSION === '1.1.1' && ! is_file($fixturePath), 'The baseline fixture starts once on official Focus 1.1.1');
        $container = dbopt_container(); $profiles = $container->get(UserProfileRepository::class); $groups=$container->get(GroupService::class); $classes=$container->get(ClassService::class);
        $run='dbopt'.bin2hex(random_bytes(4)); $accounts=array();
        foreach(array_merge(array('teacher','privacy'),array_map(static fn(int $i):string=>'student'.$i,range(1,60))) as $kind){
            $id=wp_insert_user(array('user_login'=>$run.'_'.$kind,'user_pass'=>wp_generate_password(32,true,false),'user_email'=>$run.'_'.$kind.'@example.invalid','display_name'=>'DB query '.$kind,'role'=>$kind==='teacher'?'oj_group_manager':'subscriber'));
            dbopt_check(is_int($id)&&$id>0,'Native WordPress creates a unique fixture account'); $profiles->create(new UserProfile($id,null,'2000-01-01',AccessStatus::Active,false));$accounts[$kind]=$id;
        }
        get_role('subscriber')->add_cap(AddonCapabilityKeyV1::wordpress('focus_tracker_view_self'),true);
        $teacher=new WP_User($accounts['teacher']);foreach(array('focus_tracker_view','focus_tracker_manage','focus_tracker_view_self')as$cap){$teacher->add_cap(AddonCapabilityKeyV1::wordpress($cap),true);}$teacher->add_cap('oj_create_classes',true);
        dbopt_check(!user_can($teacher,'manage_options'),'The fixture teacher is not a WordPress administrator');dbopt_user($teacher->ID);
        $group=$groups->save($teacher->ID,new GroupSaveCommand(0,'DB query group',$run.'-group','Disposable query comparison','private','manual',true,null,'normal',null));
        $class=$classes->save($teacher->ID,new ClassSaveCommand(0,'DB query class',$run.'-class','Disposable query comparison','private','private',true,null,null,null,null,array(),array(),array()));
        $students=array_values(array_filter($accounts,static fn(int $id,string $key):bool=>str_starts_with($key,'student'),ARRAY_FILTER_USE_BOTH));
        foreach($students as$id){$groups->membership($teacher->ID,$group,'add',$id);$classes->membership($teacher->ID,$class,'approve',$id);}
        foreach($students as$id){$user=new WP_User($id);$cap=AddonCapabilityKeyV1::wordpress('focus_tracker_participate');dbopt_check(user_can($user,$cap)&&!array_key_exists($cap,$user->caps),'All sixty students inherit participation without direct grants');}
        $now=intdiv(Clock::now_ms(),1000)*1000;
        $fixture=array('run'=>$run,'teacher'=>$teacher->ID,'students'=>$students,'privacyStudent'=>$accounts['privacy'],'groupId'=>$group,'classId'=>$class,'referenceNow'=>$now,'anchorDate'=>(new DateTimeImmutable('@'.intdiv($now,1000)))->setTimezone(wp_timezone())->format('Y-m-d'));
        $fixture['groupActivity']=dbopt_activity($teacher->ID,'group','group:'.$group,'DB query active group',$now-30000,$now+3600000);
        $fixture['classActivity']=dbopt_activity($teacher->ID,'class','class:'.$class,'DB query active class',$now-30000,$now+3600000);
        $fixture['scoresActivity']=dbopt_activity($teacher->ID,'group','group:'.$group,'DB query historic scores',$now-120000,$now-60000);
        foreach($students as$index=>$id){dbopt_user($id);$packet=dbopt_packet($fixture,$id,$run.'-source-'.$index);$result=dbopt_ok(dbopt_rest('POST','/events',$packet));dbopt_check(!empty($result['acknowledged'])&&$result['stored']>0,'Each learner has committed genuine source events');}
        foreach(array('group'=>$group,'class'=>$class)as$type=>$id){
            for($offset=0;$offset<5000;$offset+=250){$tuples=array();for($i=$offset;$i<$offset+250;$i++){$start=Clock::ms_to_mysql($now-(10+$i)*86400000);$end=Clock::ms_to_mysql($now-(10+$i)*86400000+3600000);$tuples[]=$wpdb->prepare("(%s,%d,%s,%s,NULL,%s,%s,8,6,6,'closed',%s,%s)",'DB query expired fixture',$teacher->ID,$type,$type.':'.$id,$start,$end,$start,$end);}
                dbopt_check($wpdb->query('INSERT INTO '.Installer::activities_table().' (name,owner_user_id,context_type,context_id,problem_ids,starts_at,ends_at,weight_focus,weight_selfreg,weight_activity,status,created_at,updated_at) VALUES '.implode(',',$tuples))===250,'Old schedule fixture batches commit without per-row writes');}
        }
        file_put_contents($fixturePath,json_encode($fixture,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
        dbopt_save('prepare',array('passed'=>true,'checks'=>$checks,'students'=>60,'historicalSchedules'=>10000,'focusVersion'=>FOCUS_TRACKER_VERSION,'scope'=>'Actual WordPress profiles, roles, services and internal signed REST. No cookies, HTTP rendering, judge execution, or student-focus inference is claimed. Credentials are never retained in this report.'));
    } elseif($mode==='duplicate-stable') {
        dbopt_check(FOCUS_TRACKER_VERSION==='1.1.2','The stable replay probe uses final source');
        $fixture=json_decode((string)file_get_contents($fixturePath),true,512,JSON_THROW_ON_ERROR);$learner=$fixture['students'][0];dbopt_user($learner);$packet=dbopt_packet($fixture,$learner,$fixture['run'].'-source-0');
        for($warm=0;$warm<2;$warm++){dbopt_ok(dbopt_rest('POST','/events',$packet));}
        $profile=dbopt_measure('duplicate_stable',static fn()=>dbopt_ok(dbopt_rest('POST','/events',$packet)),static function(array$value):array{dbopt_check(!empty($value['acknowledged'])&&$value['stored']===0,'A stable duplicate stores no new observations');return array_intersect_key($value,array_flip(array('acknowledged','stored','policyDropped','participation_id','participation_ids')));});
        foreach(array('focus_tracker_participations','focus_tracker_activity_links','focus_tracker_events','focus_tracker_scores')as$table){dbopt_check(empty($profile['queries']['writesByTable'][$table]),'A stable duplicate performs no '.$table.' write');}
        dbopt_save('duplicate-stable-final',array('passed'=>true,'focusVersion'=>FOCUS_TRACKER_VERSION,'checks'=>$checks,'profile'=>$profile,'scope'=>'Two replay warmups populate the sampled diagnostic minute cache before measurement. Remaining option writes are the fresh privacy guard and request budget; no learning/event/link/score write is needed.'));
    } elseif($mode==='invalidation-spot') {
        dbopt_check(FOCUS_TRACKER_VERSION==='1.1.2','The cache invalidation probe uses final source');
        $fixture=json_decode((string)file_get_contents($fixturePath),true,512,JSON_THROW_ON_ERROR);$a=$fixture['students'][0];$b=$fixture['students'][1];$now=Clock::now_ms();
        $activity=dbopt_activity($fixture['teacher'],'group','group:'.$fixture['groupId'],'DB query isolated cache race',$now+172800000,$now+176400000);
        $metrics=array('total_active_seconds'=>0,'total_hidden_seconds'=>0,'focus_ratio'=>0,'blur_count'=>0,'avg_recovery_seconds'=>0,'activity_window_ratio'=>0,'score_focus'=>0,'score_selfreg'=>0,'score_activity'=>0,'score_total'=>0,'finalized'=>true,'cache_signature'=>hash('sha256','isolated-cache-race'));
        $primary=$wpdb;$writer=new wpdb($config['dbUser'],$config['dbPassword'],$config['dbName'],$config['dbHost']);$writer->set_prefix('ftreal_');
        dbopt_check($writer->get_var('SELECT @@server_uuid')===$identity['uuid']&&$writer->get_var('SELECT DATABASE()')===$config['dbName'],'The independent cache writer uses the owned Oracle server');$writer->query('SET SESSION innodb_lock_wait_timeout=2');
        $deadlocks=(int)$primary->get_row("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'",ARRAY_A)['Value'];$deletes=array();
        $capture=static function(string$sql)use($primary,&$deletes):string{if(($GLOBALS['wpdb']??null)===$primary&&preg_match('/^DELETE FROM '.preg_quote(Installer::scores_table(),'/').'\s+WHERE /i',$sql)){ $deletes[]=preg_match('/\bWHERE id = [0-9]+ AND user_id = [0-9]+ AND raw_events_expired = 0\s*$/D',$sql)===1;}return$sql;};
        add_filter('query',$capture,999,1);
        try{
            dbopt_check($primary->query('START TRANSACTION')!==false&&Privacy_Guard::lock($a),'The invalidating learner holds its real privacy lock');
            dbopt_check(\FocusTracker\Database\Score_Repository::invalidate_for_user_activities($a,array($activity)),'An absent score invalidates successfully');dbopt_check($deletes===array(),'An absent score causes no range or point DELETE');
            $writerReached=false;$writerError='';$stage='begin';$errno=null;$sqlstate=null;$inserted=false;
            $GLOBALS['wpdb']=$writer;
            try{
                if($writer->query('START TRANSACTION')===false){throw new RuntimeException('writer_begin');}$stage='score_upsert';$writerReached=true;
                // Isolate the cache repository's gap behavior. The primary
                // retains the real learner guard; this synthetic independent
                // writer deliberately tests only the production upsert, not
                // a second full authorization/privacy/controller operation.
                $inserted=\FocusTracker\Database\Score_Repository::upsert($activity,$b,$metrics);$writerError=$writer->last_error;
                if($inserted){$stage='commit';$inserted=$writer->query('COMMIT')!==false;}
            }catch(Throwable$error){$errno=$errno??(int)$error->getCode();$sqlstate=$sqlstate??($error instanceof mysqli_sql_exception?$error->getSqlState():null);$inserted=false;}
            finally{$writer->query('ROLLBACK');$GLOBALS['wpdb']=$primary;}
            if($inserted!==true){$kind='other';foreach(array('Lock wait timeout'=>'lock_timeout','Deadlock'=>'deadlock','Unknown column'=>'unknown_column','doesn\'t have a default'=>'missing_default','format'=>'format')as$needle=>$label){if(str_contains($writerError,$needle)){$kind=$label;break;}}dbopt_save('invalidation-writer-diagnostic',array('callbackReached'=>$writerReached,'stage'=>$stage,'errno'=>$errno,'sqlstate'=>$sqlstate,'errorKind'=>$kind,'writerDatabase'=>$writer->get_var('SELECT DATABASE()'),'writerIsolation'=>$writer->get_var('SELECT @@transaction_isolation'),'writerAutocommit'=>$writer->get_var('SELECT @@autocommit'),'differentLearners'=>$a!==$b,'writerPrefix'=>$writer->prefix,'writerOptions'=>$writer->options));}
            dbopt_check($inserted===true,'Another learner commits a final cache while the absent invalidation transaction remains open');dbopt_check($primary->query('COMMIT')!==false,'The empty invalidation transaction commits');
            dbopt_check(Privacy_Guard::run($a,static fn()=>\FocusTracker\Database\Score_Repository::upsert($activity,$a,$metrics))===true,'The existing-cache case has a real source row');
            dbopt_check($primary->query('START TRANSACTION')!==false&&Privacy_Guard::lock($a),'The existing-cache invalidation retains the same lock ordering');
            dbopt_check(\FocusTracker\Database\Score_Repository::invalidate_for_user_activities($a,array($activity)),'The existing score invalidates successfully');dbopt_check($primary->query('COMMIT')!==false,'The point invalidation commits');
            dbopt_check($deletes===array(true),'The only cache DELETE is an existing primary-key equality');
            dbopt_check(\FocusTracker\Database\Score_Repository::get($activity,$a)===null&&\FocusTracker\Database\Score_Repository::get($activity,$b)!==null,'The intended row is removed and the independent final cache survives');
        }finally{$GLOBALS['wpdb']=$primary;$primary->query('ROLLBACK');remove_filter('query',$capture,999);$writer->close();}
        $after=(int)$primary->get_row("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'",ARRAY_A)['Value'];dbopt_check($after===$deadlocks,'The narrow native invalidation/cache interleaving causes no global deadlock');
        dbopt_check(Privacy_Guard::run($b,static fn()=>\FocusTracker\Database\Score_Repository::invalidate_for_user_activities($b,array($activity)))===true,'The isolated final cache fixture is cleaned through the same production point-delete method');
        dbopt_save('invalidation-spot',array('passed'=>true,'focusVersion'=>FOCUS_TRACKER_VERSION,'checks'=>$checks,'globalDeadlockDelta'=>$after-$deadlocks,'primaryDeletes'=>count($deletes),'allDeletesUseExistingPrimaryKey'=>!in_array(false,$deletes,true),'secondaryWriterScope'=>'Production Score_Repository::upsert only; no secondary learner guard or HTTP/controller scope is claimed. An earlier optional same-process two-guard configuration timed out at the secondary privacy guard before reaching score upsert.','scope'=>'Narrow real Oracle repository interleaving: the primary holds its learner privacy lock and an absent invalidation transaction stays open while an independent WordPress DB connection commits a synthetic other-learner final cache. This is not a simultaneous HTTP score-controller load test.'));
    } elseif(in_array($mode,array('snapshot-prezip','snapshot-postzip'),true)) {
        dbopt_check(FOCUS_TRACKER_VERSION==='1.1.2','ZIP snapshots use the final source version');
        dbopt_check($mode!=='snapshot-prezip'||!is_file($native.'/artifacts/snapshot-prezip.json'),'The pre-ZIP preservation snapshot is immutable');
        $snapshot=dbopt_snapshot();$files=dbopt_runtime_files();$health=Installer::inspect_schema();dbopt_check(!empty($health['ready']),'The preserved schema is healthy');
        $report=array('passed'=>true,'focusVersion'=>FOCUS_TRACKER_VERSION,'snapshot'=>$snapshot,'installedFiles'=>$files,'checks'=>$checks);
        if($mode==='snapshot-postzip'){
            $before=json_decode((string)file_get_contents($native.'/artifacts/snapshot-prezip.json'),true,512,JSON_THROW_ON_ERROR);
            foreach(array('tables','engines','settingsAndRolesSha256')as$key){dbopt_check($snapshot[$key]===$before['snapshot'][$key],'The final ZIP preserves '.$key.' exactly');}
            $zipPath=realpath($workspace.'/_releases/focus-tracker/1.1.2/focus-tracker-1.1.2.zip');dbopt_check(is_string($zipPath),'The immutable final ZIP exists');$zip=new ZipArchive();dbopt_check($zip->open($zipPath)===true,'The final package opens for byte comparison');$expected=array();
            for($i=0;$i<$zip->numFiles;$i++){$name=$zip->getNameIndex($i);dbopt_check(is_string($name)&&preg_match('#^focus-tracker/[A-Za-z0-9_./-]*$#D',$name)===1&&!str_contains($name,'..')&&!str_contains($name,'//'),'Every final ZIP entry stays in its safe package root');if(str_ends_with($name,'/')){continue;}$key=substr($name,strlen('focus-tracker/'));dbopt_check(!isset($expected[$key]),'Every final ZIP file has one path');$bytes=$zip->getFromIndex($i);dbopt_check(is_string($bytes),'Every final ZIP file is readable');$expected[$key]=hash('sha256',$bytes);}$zip->close();ksort($expected,SORT_STRING);
            dbopt_check($files===$expected,'Every installed file exactly matches the final official ZIP without extra files');$report['packageSha256']=hash_file('sha256',$zipPath);$report['packageFiles']=count($expected);
        }$report['checks']=$checks;dbopt_save($mode,$report);
    } elseif($mode==='install-final-zip') {
        $zip=realpath($workspace.'/_releases/focus-tracker/1.1.2/focus-tracker-1.1.2.zip');dbopt_check(is_string($zip)&&dirname($zip)===realpath($workspace.'/_releases/focus-tracker/1.1.2'),'Only the immutable final release package can replace the dedicated plugin');
        $checksum=(string)file_get_contents($zip.'.sha256');dbopt_check(preg_match('/^([a-f0-9]{64})\b/i',trim($checksum),$hash)===1&&hash_equals(strtolower($hash[1]),hash_file('sha256',$zip)),'The final package matches its immutable SHA-256 sidecar');
        $destination=realpath(WP_PLUGIN_DIR.'/focus-tracker');dbopt_check($destination===realpath($config['wpPath'].'/wp-content/plugins/focus-tracker')&&wp_get_environment_type()==='local','The official uploader target is this disposable local install');
        $admin=get_user_by('login',$config['admin']['login']??$config['admin']['username']);dbopt_check($admin instanceof WP_User&&user_can($admin,'update_plugins'),'Only the disposable WordPress administrator performs the ZIP replacement');wp_set_current_user($admin->ID);
        require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';$skin=new Automatic_Upgrader_Skin();$upgrader=new Plugin_Upgrader($skin);$result=$upgrader->install($zip,array('overwrite_package'=>true,'clear_update_cache'=>false));dbopt_check($result===true,'The real WordPress Plugin_Upgrader replaces the final ZIP');
        dbopt_save('final-zip-install',array('passed'=>true,'version'=>'1.1.2','checks'=>$checks,'packageSha256'=>hash_file('sha256',$zip),'scope'=>'Actual WordPress Plugin_Upgrader overwrite of the dedicated local plugin; post-install bytes and database snapshots are checked in a new request.'));
    } elseif($mode==='final-health') {
        dbopt_check(FOCUS_TRACKER_VERSION==='1.1.2','The final health probe uses the final source version');
        $table=Installer::activities_table();$health=Installer::inspect_schema();
        dbopt_check(!empty($health['ready']),'Oracle schema inspection accepts the migrated final schema');
        $index=$wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name='context_end_window'",ARRAY_A);
        usort($index,static fn(array$a,array$b):int=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        dbopt_check(array_column($index,'Column_name')===array('context_type','context_id','ends_at','starts_at'),'The migrated lookup index has the exact intended column order');
        $dropped=false;
        try{
            dbopt_check($wpdb->query("ALTER TABLE {$table} DROP KEY context_end_window")!==false,'The isolated health-negative fixture removes only the new lookup index');$dropped=true;
            $missing=Installer::inspect_schema();
            dbopt_check(empty($missing['ready'])&&in_array('activities:end_window_index',$missing['issues'],true),'Oracle health inspection rejects a missing required end-window index');
            dbopt_check(Installer::install(),'The real final installer repairs the missing lookup index');
            $dropped=false;
        }finally{
            if($dropped&&$wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name='context_end_window'",ARRAY_A)===array()){
                if($wpdb->query("ALTER TABLE {$table} ADD KEY context_end_window (context_type,context_id,ends_at,starts_at)")===false){throw new RuntimeException('The owned lookup index could not be restored.');}
            }
        }
        $repaired=Installer::inspect_schema();dbopt_check(!empty($repaired['ready'])&&Installer::ready(),'The repaired final Oracle schema is durably ready');
        $fixture=json_decode((string)file_get_contents($fixturePath),true,512,JSON_THROW_ON_ERROR);$explain=dbopt_explain($fixture);
        dbopt_check(($explain['plan'][0]['key']??null)==='context_end_window','The final production schedule query chooses the new index');
        dbopt_save('final-health',array('passed'=>true,'focusVersion'=>FOCUS_TRACKER_VERSION,'checks'=>$checks,'indexColumns'=>array_column($index,'Column_name'),'missingIndexIssueObserved'=>true,'restoredReady'=>true,'scheduleExplain'=>$explain));
    } elseif($mode==='spot') {
        dbopt_check(!is_file($native.'/artifacts/spot-'.FOCUS_TRACKER_VERSION.'.json'),'Each source version gets its own native spot evidence');
        $fixture=json_decode((string)file_get_contents($fixturePath),true,512,JSON_THROW_ON_ERROR);
        $teacher=$fixture['teacher'];$other=$fixture['students'][0];
        $beforeOther=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Installer::participations_table().' WHERE user_id=%d',$other));
        $container=dbopt_container();$groups=$container->get(GroupService::class);dbopt_user($teacher);
        $run=$fixture['run'].'-spot-'.bin2hex(random_bytes(3));
        $learner=wp_insert_user(array('user_login'=>$run.'_privacy','user_pass'=>wp_generate_password(32,true,false),'user_email'=>$run.'@example.invalid','display_name'=>'DB query isolated privacy learner','role'=>'subscriber'));
        dbopt_check(is_int($learner)&&$learner>0,'The privacy spot uses a fresh isolated fixture learner');
        $container->get(UserProfileRepository::class)->create(new UserProfile($learner,null,'2000-01-01',AccessStatus::Active,false));
        $group=$groups->save($teacher,new GroupSaveCommand(0,'DB query privacy spot',$run,'Disposable native protection spot','private','manual',true,null,'normal',null));
        $groups->membership($teacher,$group,'add',$learner);$spot=$fixture;$spot['groupId']=$group;$spot['referenceNow']=intdiv(Clock::now_ms(),1000)*1000;
        $activity=dbopt_activity($teacher,'group','group:'.$group,'DB query privacy spot',$spot['referenceNow']-120000,$spot['referenceNow']+600000);
        dbopt_user($learner);$old=dbopt_packet($spot,$learner,$run.'-before-erasure');
        $first=dbopt_ok(dbopt_rest('POST','/events',$old));dbopt_check(!empty($first['acknowledged'])&&$first['stored']>0,'The isolated learner commits observations before erasure');
        $email=(string)get_userdata($learner)->user_email;$erased=null;
        for($page=1;$page<=20;$page++){$erased=Personal_Data::erase_data($email,$page);dbopt_check(is_array($erased),'The real WordPress eraser succeeds');if(!empty($erased['done'])){break;}}
        dbopt_check(is_array($erased)&&!empty($erased['done']),'The isolated learner erasure completes');
        dbopt_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Installer::participations_table().' WHERE user_id=%d',$learner))===0,'Erasure removes the isolated participation rows');
        $replay=dbopt_ok(dbopt_rest('POST','/events',$old));dbopt_check(!empty($replay['acknowledged'])&&$replay['stored']===0&&$replay['policyDropped']===4,'Erased outbox observations cannot recreate evidence');
        $cutoff=Privacy_Guard::cutoff($learner);dbopt_check($cutoff>0&&$cutoff<PHP_INT_MAX,'Erasure retains a valid inclusive watermark');
        usleep(100000);$freshStart=Clock::now_ms()-20;$freshSpot=$spot;$freshSpot['referenceNow']=$freshStart+110000;
        $fresh=dbopt_packet($freshSpot,$learner,$run.'-after-erasure');$fresh['is_final']=false;$fresh['events']=array_slice($fresh['events'],0,2);$fresh['events'][1]['occurred_at']=$freshStart+1;
        dbopt_check($freshStart>$cutoff,'New observations begin strictly after the erasure watermark');
        $freshResult=dbopt_ok(dbopt_rest('POST','/events',$fresh));dbopt_check($freshResult['stored']>0&&$freshResult['policyDropped']===0,'The same account may resume new learning after erasure');
        dbopt_user($teacher);dbopt_ok(dbopt_rest('DELETE','/activities/'.$activity));
        // Activity columns store whole seconds. Straddle an exact second
        // boundary after the erasure watermark, rather than assuming a
        // subsecond end survives the actual Oracle schema.
        usleep(2200000);$raceEnd=intdiv(Clock::now_ms(),1000)*1000;$raceStart=$raceEnd-500;
        dbopt_check($raceStart>$cutoff,'The race observations begin after the privacy watermark');
        $raceActivity=dbopt_activity($teacher,'group','group:'.$group,'DB query race spot',$raceStart-2000,$raceStart+60000);
        $raceSpot=$spot;$raceSpot['referenceNow']=$raceStart+110000;$race=dbopt_packet($raceSpot,$learner,$run.'-race');$race['is_final']=true;
        $race['events']=array_slice($race['events'],0,3);$race['events'][1]['occurred_at']=$raceStart+100;$race['events'][2]['occurred_at']=$raceEnd+200;
        usleep(250000);dbopt_user($learner);
        $second=new mysqli('127.0.0.1',$config['dbUser'],$config['dbPassword'],$config['dbName'],33917);
        $secondIdentity=$second->query('SELECT @@server_uuid AS uuid,DATABASE() AS databaseName')->fetch_assoc();
        dbopt_check($secondIdentity['uuid']===$identity['uuid']&&$secondIdentity['databaseName']===$config['dbName'],'The interleaving writer uses the exact owned Oracle database');
        $mutated=false;$table=Installer::activities_table();
        $raceHook=static function(string$sql)use(&$mutated,$second,$table,$raceActivity,$raceEnd,$teacher,$group):string{
            if(!$mutated&&str_contains($sql,'FROM '.$table)&&str_contains($sql,'LOCK IN SHARE MODE')){
                // A separate committed connection changes only this disposable
                // schedule after the optimistic read and before the real
                // production shared-lock read. This is a precise DB race
                // interleaving, not a teacher HTTP/controller test.
                $statement=$second->prepare('UPDATE '.$table.' SET ends_at=?,updated_at=? WHERE id=? AND owner_user_id=? AND context_id=? AND name=?');
                $end=Clock::ms_to_mysql($raceEnd);$updated=Clock::ms_to_mysql(Clock::now_ms());$context='group:'.$group;$name='DB query race spot';
                $statement->bind_param('ssiiss',$end,$updated,$raceActivity,$teacher,$context,$name);$statement->execute();
                if($statement->affected_rows!==1){throw new RuntimeException('The isolated schedule race target changed.');}$statement->close();$mutated=true;
            }return$sql;
        };
        add_filter('query',$raceHook,999,1);try{$raceResult=dbopt_ok(dbopt_rest('POST','/events',$race));}finally{remove_filter('query',$raceHook,999);$second->close();}
        dbopt_save('spot-race-observation',array('focusVersion'=>FOCUS_TRACKER_VERSION,'counts'=>array_intersect_key($raceResult,array_flip(array('acknowledged','stored','policyDropped'))),'endsAtMs'=>$raceEnd,'eventTimes'=>array_column($race['events'],'occurred_at'),'storedEnd'=>Activity_Repository::find($raceActivity)['ends_at']));
        dbopt_check($mutated,'The schedule shortens between optimistic and locked reads');
        dbopt_check(!empty($raceResult['acknowledged'])&&$raceResult['policyDropped']===1,'The locked schedule preserves valid events and acknowledges the newly excluded event');
        $ids=array_map('intval',$raceResult['participation_ids']);dbopt_check(count($ids)===1,'The schedule race creates only one permitted physical segment');
        $sequences=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT sequence_number FROM '.Installer::events_table().' WHERE participation_id=%d AND sequence_number>0 AND sequence_number<2147483648 ORDER BY sequence_number',$ids[0])));
        dbopt_check($sequences===array(1,2),'Only the two pre-end observations reach the event table');
        $deadline=$wpdb->get_var($wpdb->prepare('SELECT ended_at_ms FROM '.Installer::participations_table().' WHERE id=%d',$ids[0]));
        dbopt_check((int)$deadline===$raceEnd,'The stored participation deadline obeys the newly committed end');
        $gateSpot=$spot;$gateSpot['referenceNow']=Clock::now_ms();
        $state=dbopt_ok(dbopt_rest('POST','/tracking/state',dbopt_gate_body($gateSpot,$learner,'group')));dbopt_check($state['active']===false,'The next real tracking gate stops after the shortened end');
        dbopt_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Installer::participations_table().' WHERE user_id=%d',$other))===$beforeOther,'The isolated privacy and schedule spots leave the main learner evidence unchanged');
        dbopt_save('spot-'.FOCUS_TRACKER_VERSION,array('passed'=>true,'focusVersion'=>FOCUS_TRACKER_VERSION,'oracleVersion'=>$server['version'],'checks'=>$checks,'race'=>array('interleavingApplied'=>$mutated,'retainedSequences'=>$sequences,'policyDropped'=>$raceResult['policyDropped']),'scope'=>'Actual WordPress eraser and signed internal REST; precise separate-connection schedule interleaving before the production shared-lock read. This is not an HTTP teacher-controller or load test. Main sixty-person fixtures are unchanged.'));
    } elseif(in_array($mode,array('gate-baseline','gate-candidate'),true)) {
        dbopt_check(FOCUS_TRACKER_VERSION === '1.1.1', 'The scoped gate proof retains the released version header');
        dbopt_check($mode !== 'gate-baseline' || ! is_file($native.'/artifacts/gate-baseline.json'), 'The stable gate baseline cannot be overwritten');
        $fixture=json_decode((string)file_get_contents($fixturePath),true,512,JSON_THROW_ON_ERROR);$profiles=array();$learner=$fixture['students'][0];
        foreach(array('group','class','general')as$type){
            dbopt_user($learner);$body=dbopt_gate_body($fixture,$learner,$type);
            // A sampled diagnostic claim invalidates its option cache. Prime
            // again after that claim so the comparison is independent of the
            // minute in which the first warm request happens.
            for($warm=0;$warm<2;$warm++){dbopt_ok(dbopt_rest('POST','/tracking/state',$body));}
            $profiles['gate_'.$type]=dbopt_measure('gate_'.$type,static fn()=>dbopt_ok(dbopt_rest('POST','/tracking/state',$body)),static function(array$value):array{
                dbopt_check($value['validUntilMs']<=$value['serverNowMs']+10000,'The scoped gate proof keeps the ten-second approval limit');
                return array_intersect_key($value,array_flip(array('active','windows','activityIds','contextType','contextId','reason')));
            });
        }
        $report=array('passed'=>true,'mode'=>$mode,'candidateOnly'=>$mode==='gate-candidate','focusVersion'=>FOCUS_TRACKER_VERSION,'profiles'=>$profiles,'checks'=>$checks,'scope'=>'A stable warmed gate comparison only. Context SELECTs count each membership/class SQL statement once, independently of option-cache sampling. No query text is saved.');
        if($mode==='gate-candidate'){
            $baseline=json_decode((string)file_get_contents($native.'/artifacts/gate-baseline.json'),true,512,JSON_THROW_ON_ERROR);
            foreach($profiles as$name=>$profile){
                $same=$profile['businessSha256']===$baseline['profiles'][$name]['businessSha256'];
                dbopt_check($same,'The scoped '.$name.' preserves its stable native baseline');
                $report['comparison'][$name]=array('beforeStatements'=>$baseline['profiles'][$name]['queries']['statements'],'afterStatements'=>$profile['queries']['statements'],'beforeContextSelects'=>$baseline['profiles'][$name]['queries']['contextSelects']??0,'afterContextSelects'=>$profile['queries']['contextSelects']??0,'businessIdentical'=>$same);
            }
        }
        $report['checks']=$checks;dbopt_save($mode,$report);
    } elseif($mode==='index-candidate') {
        $fixture=json_decode((string)file_get_contents($fixturePath),true,512,JSON_THROW_ON_ERROR);
        $table=Installer::activities_table();$key='context_end_window';$created=false;
        dbopt_check($wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name='{$key}'",ARRAY_A)===array(),'The temporary index candidate does not replace a preexisting index');
        $baseline=json_decode((string)file_get_contents($native.'/artifacts/baseline.json'),true,512,JSON_THROW_ON_ERROR);
        try {
            dbopt_check($wpdb->query("ALTER TABLE {$table} ADD KEY {$key} (context_type,context_id,ends_at,starts_at)")!==false,'The isolated candidate index is installed');$created=true;
            $wpdb->get_results('ANALYZE TABLE '.$table,ARRAY_A);
            $after=dbopt_explain($fixture);
            dbopt_check($after['matchingIds']===$baseline['scheduleExplain']['matchingIds'],'The candidate index preserves every matching schedule');
            $report=array('passed'=>true,'candidateOnly'=>true,'focusVersion'=>FOCUS_TRACKER_VERSION,'before'=>$baseline['scheduleExplain'],'after'=>$after,'scope'=>'Temporary index proof on the owned Oracle table. The index is removed again; this does not certify a source migration or an HTTP capacity result.');
        } finally { if($created && $wpdb->query("ALTER TABLE {$table} DROP KEY {$key}")===false){throw new RuntimeException('The temporary index could not be restored.');} }
        dbopt_check($wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name='{$key}'",ARRAY_A)===array(),'The temporary index is removed after the isolated proof');
        $report['originalIndexSetRestored']=true;$report['checks']=$checks;dbopt_save('index-candidate',$report);
    } elseif(in_array($mode,array('baseline','verify','candidate','bulk-baseline','bulk-candidate','statistics-baseline','statistics-candidate'),true)) {
        $bulk=in_array($mode,array('bulk-baseline','bulk-candidate'),true);
        $statistics=in_array($mode,array('statistics-baseline','statistics-candidate'),true);
        dbopt_check(!in_array($mode,array('baseline','bulk-baseline','statistics-baseline'),true) || ! is_file($native.'/artifacts/'.$mode.'.json'), 'The original native baseline cannot be overwritten');
        if($mode==='verify'){
            dbopt_check(FOCUS_TRACKER_VERSION==='1.1.2','The final native verification uses Focus 1.1.2');
            $manifest=json_decode((string)file_get_contents($native.'/artifacts/verify-source-manifest.json'),true,512,JSON_THROW_ON_ERROR);
            dbopt_check(($manifest['version']??'')==='1.1.2'&&!empty($manifest['files']),'The final source manifest identifies the whole candidate');
            $covered=array();
            foreach($manifest['files']as$entry){
                $relative=$entry['path']??'';dbopt_check(is_string($relative)&&preg_match('#^[A-Za-z0-9_./-]+$#D',$relative)===1&&!str_contains($relative,'..'),'The final source manifest stays inside the plugin');
                dbopt_check(!isset($covered[$relative]),'The final source manifest contains no duplicate files');$covered[$relative]=true;
                dbopt_check(hash_file('sha256',WP_PLUGIN_DIR.'/focus-tracker/'.$relative)===($entry['sha256']??null),'The installed final source matches its recorded manifest');
                dbopt_check(hash_file('sha256',$workspace.'/focus-tracker/'.$relative)===($entry['sha256']??null),'The final installed source matches the current workspace candidate');
            }
            $required=array('focus-tracker.php','plugin-package.php');$sourceRoot=str_replace('\\','/',(string)realpath($workspace.'/focus-tracker'));
            foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot.'/src',FilesystemIterator::SKIP_DOTS))as$file){if($file->isFile()&&$file->getExtension()==='php'){$required[]=substr(str_replace('\\','/',$file->getPathname()),strlen($sourceRoot)+1);}}
            dbopt_check(array_diff($required,array_keys($covered))===array(),'The final manifest covers every runtime PHP source file');
        }
        if($bulk||$statistics){dbopt_check(FOCUS_TRACKER_VERSION==='1.1.1','The component proof retains the released version header');}
        $fixture=json_decode((string)file_get_contents($fixturePath),true,512,JSON_THROW_ON_ERROR);$profiles=array();$learner=$fixture['students'][0];
        foreach(array('group','class','general')as$type){dbopt_user($learner);$body=dbopt_gate_body($fixture,$learner,$type);for($warm=0;$warm<2;$warm++){dbopt_ok(dbopt_rest('POST','/tracking/state',$body));}
            $profiles['gate_'.$type]=dbopt_measure('gate_'.$type,static fn()=>dbopt_ok(dbopt_rest('POST','/tracking/state',$body)),static function(array $value):array{dbopt_check($value['validUntilMs']<=$value['serverNowMs']+10000,'Gate approval never exceeds ten seconds');return array_intersect_key($value,array_flip(array('active','windows','activityIds','contextType','contextId','reason')));});}
        dbopt_user($learner);$packet=dbopt_packet($fixture,$learner,$fixture['run'].'-source-0');
        $profiles['duplicate_events']=dbopt_measure('duplicate_events',static fn()=>dbopt_ok(dbopt_rest('POST','/events',$packet)),static function(array $value):array{dbopt_check(!empty($value['acknowledged'])&&$value['stored']===0,'A complete retransmission is acknowledged without new observations');return array_intersect_key($value,array_flip(array('acknowledged','stored','policyDropped','participation_id','participation_ids')));});
        dbopt_user($fixture['teacher']);
        foreach(array('forced'=>true,'cached'=>false)as$kind=>$refresh){$profiles['scores_'.$kind]=dbopt_measure('scores_'.$kind,static fn()=>dbopt_ok(dbopt_rest('GET','/activities/'.$fixture['scoresActivity'].'/scores',array('refresh'=>$refresh))),static function(array $value):array{dbopt_check(count($value['rows'])===60,'The native teacher report includes exactly the sixty students');return$value;});}
        if($bulk){
            $active=Activity_Repository::find($fixture['groupActivity']);
            dbopt_check(is_array($active)&&Clock::mysql_to_ms($active['ends_at'])>Clock::now_ms(),'The provisional proof still has an active window');
            $profiles['scores_active_no_data']=dbopt_measure('scores_active_no_data',static fn()=>dbopt_ok(dbopt_rest('GET','/activities/'.$fixture['groupActivity'].'/scores')),static function(array$value):array{
                dbopt_check(count($value['rows'])===60,'The live no-data report includes exactly sixty students');
                foreach($value['rows']as$row){dbopt_check($row['dataStatus']==='no_data'&&$row['finalized']===false,'The live comparison has no observations and stays provisional');}
                return$value;
            });
        }
        dbopt_user($learner);
        foreach(array('first','repeat')as$kind){$profiles['monthly_'.$kind]=dbopt_measure('monthly_'.$kind,static fn()=>Privacy_Guard::run($learner,static fn()=>Statistics_Service::report($learner,'month',$fixture['anchorDate'],array('type'=>'self','id'=>''),$fixture['referenceNow'])),static function($value):array{dbopt_check(is_array($value),'A native monthly report succeeds');return$value;});}
        $explain=dbopt_explain($fixture);
        $report=array('passed'=>true,'mode'=>$mode,'focusVersion'=>FOCUS_TRACKER_VERSION,'platformVersion'=>OJ_PLATFORM_VERSION,'oracleVersion'=>$server['version'],'bootstrapQueries'=>$bootstrap,'profiles'=>$profiles,'scheduleExplain'=>$explain,'checks'=>$checks,'scope'=>'Query and DML statement counts only; native internal REST and fixed-time statistics. Bootstrap and operation counts are separate. This is not an HTTP capacity result. No SQL text, parameters, credentials, code or page tokens are exported.');
        if(in_array($mode,array('verify','candidate','bulk-candidate','statistics-candidate'),true)){
            $baseline=json_decode((string)file_get_contents($native.'/artifacts/'.($bulk?'bulk-baseline':($statistics?'statistics-baseline':'baseline')).'.json'),true,512,JSON_THROW_ON_ERROR);
            foreach($profiles as$name=>$profile){
                $same=$profile['businessSha256']===$baseline['profiles'][$name]['businessSha256'];
                $checks['Optimized '.$name.' preserves the baseline business result']=$same;
                $report['comparison'][$name]=array('beforeStatements'=>$baseline['profiles'][$name]['queries']['statements'],'afterStatements'=>$profile['queries']['statements'],'beforeDml'=>array_sum(array_intersect_key($baseline['profiles'][$name]['queries']['verbs'],array_flip(array('INSERT','UPDATE','DELETE')))),'afterDml'=>array_sum(array_intersect_key($profile['queries']['verbs'],array_flip(array('INSERT','UPDATE','DELETE')))),'businessIdentical'=>$same);
            }
            $report['checks']=$checks;$report['passed']=!in_array(false,$checks,true);
        }
        if($mode==='candidate'){$report['candidateOnly']=true;$report['candidateScope']='Only Context_Resolver and Activity_Window_Gate source files; released version header and all other runtime files are unchanged. These two source files are restored afterwards.';}
        if($mode==='bulk-candidate'){
            $report['candidateOnly']=true;$report['candidateScope']='Only Activity_Score_Service, Participation_Repository, Score_Repository, Scores_Controller and Privacy_Guard. No final release, gate optimization, invalidation change or Installer migration is claimed. All five runtime files are restored afterwards.';
            $report['sourceManifest']=json_decode((string)file_get_contents($native.'/artifacts/bulk-source-manifest.json'),true,512,JSON_THROW_ON_ERROR);
        }
        if($mode==='statistics-candidate'){
            $report['candidateOnly']=true;$report['candidateScope']='Only Statistics_Service and Statistics_Repository source files. Released version header, score/gate paths and schema remain unchanged. Both runtime files are restored afterwards.';
            $report['sourceManifest']=json_decode((string)file_get_contents($native.'/artifacts/statistics-source-manifest.json'),true,512,JSON_THROW_ON_ERROR);
        }
        dbopt_save($report['passed']?$mode:$mode.'-failed-'.gmdate('YmdHis'),$report);
        if(!$report['passed']){throw new RuntimeException('The optimized business result differs from the fixed native baseline.');}
    } else { throw new RuntimeException('Unknown native query mode.'); }
    echo json_encode(array('mode'=>$mode,'passed'=>true,'focusVersion'=>FOCUS_TRACKER_VERSION,'conditions'=>count($checks)),JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $error) {
    dbopt_save('failure-'.$mode,array('passed'=>false,'mode'=>$mode,'errorClass'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine(),'checks'=>$checks));
    fwrite(STDERR,'Native query comparison failed: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine()."\n");exit(1);
}
