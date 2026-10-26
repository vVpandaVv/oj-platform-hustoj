<?php
declare(strict_types=1);
// Destructive only inside the explicitly isolated test database, never an operational site.
if (PHP_SAPI !== 'cli') { exit(1); }
$config=json_decode(file_get_contents(getenv('OCL_TEST_CONFIG')),true,512,JSON_THROW_ON_ERROR);
$port=(int)(getenv('OCL_REHEARSAL_DB_PORT')?:33368);
if($config['host']!=='127.0.0.1' || !in_array($port,array(33368,33388),true) || (int)$config['port']!==$port){throw new RuntimeException('Explicit isolated loopback DB required');}
$phase=$argv[1]??'';$statePath=getenv('OCL_REHEARSAL_STATE');
$state=is_file($statePath)?json_decode(file_get_contents($statePath),true,512,JSON_THROW_ON_ERROR):array();
if($phase==='install' || $phase==='fresh-install'){
    if($state){throw new RuntimeException('State already exists');}
    $state['database']='ocl_test_ops_'.gmdate('YmdHis').'_'.bin2hex(random_bytes(3));
    $db=new mysqli('127.0.0.1',$config['user'],$config['password'],'',$port);
    $db->query('CREATE DATABASE '.$state['database'].' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db->close();
    file_put_contents($statePath,json_encode($state));
}
if(!preg_match('/^ocl_test_ops_[0-9]{14}_[a-f0-9]{6}$/D',$state['database']??'')){throw new RuntimeException('Unexpected rehearsal database');}
define('ABSPATH',rtrim(getenv('OCL_TEST_WP'),'/\\').'/');
define('DB_NAME',$state['database']);define('DB_HOST','127.0.0.1:'.$port);define('DB_USER',$config['user']);define('DB_PASSWORD',$config['password']);
define('DB_CHARSET','utf8mb4');define('DB_COLLATE','');define('WP_INSTALLING',true);define('WP_DEBUG',false);define('DISABLE_WP_CRON',true);define('WP_HTTP_BLOCK_EXTERNAL',true);
define('WP_HOME','http://127.0.0.1:'.($port+1));define('WP_SITEURL',WP_HOME);
foreach(array('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY') as $key){define($key,hash('sha256',$config['password'].$key));}
$table_prefix='oclops_';$_SERVER['HTTP_HOST']='127.0.0.1:'.($port+1);$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/';
require ABSPATH.'wp-settings.php';
add_filter('pre_wp_mail',static fn()=>false);
set_exception_handler(static function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().' at line '.$e->getLine().PHP_EOL);exit(1);});
if($phase==='install' || $phase==='fresh-install'){require_once ABSPATH.'wp-admin/includes/upgrade.php';wp_install('Code Lab restore rehearsal','ops_admin','ops@example.invalid',false,'',wp_generate_password(32));}
$platform=getenv('OCL_TEST_PLATFORM');require_once $platform.'/src/Kernel/Autoloader.php';\CodingPanda\OjPlatform\Kernel\Autoloader::register($platform);
define('OJ_PLATFORM_VERSION','2.0.0-dev.99');define('OJ_PLATFORM_ADDON_API_VERSION','1.1.0');
$plugin=rtrim(getenv('OCL_TEST_PLUGIN'),'/\\');require $plugin.'/oj-code-lab.php';
use OjCodeLab\Database\Installer;
use OjCodeLab\Database\Notebook_Repository as Books;
use OjCodeLab\Database\Dataset_Repository as Datasets;
use OjCodeLab\Datasets\Kaggle_Settings;
$checks=array();
function must(bool $ok,string $label):void{global $checks;if(!$ok){throw new RuntimeException('FAIL '.$label);}$checks[]=$label;}
function snapshot():array{
    global $state,$wpdb;
    $book=Books::find_for_user((int)$state['student']);must(!is_wp_error($book),'notebook read succeeds');
    $datasets=Datasets::all();$uploads=$wpdb->get_results('SELECT upload_key,SHA2(content,256) AS content_hash FROM '.Installer::uploads_table().' ORDER BY upload_key',ARRAY_A);
    return array('cells'=>hash('sha256',(string)$wpdb->get_var($wpdb->prepare('SELECT cells FROM '.Installer::notebooks_table().' WHERE user_id=%d',(int)$state['student']))),
      'cell_types'=>array_map(static fn($cell)=>$cell['type']??'code',$book['cells']),'body_hashes'=>array_map(static fn($cell)=>hash('sha256',$cell['code']??''),$book['cells']),'datasets'=>hash('sha256',wp_json_encode($datasets)),'uploads'=>hash('sha256',wp_json_encode($uploads)),
      'key_readable'=>Kaggle_Settings::key()==='fixture-rehearsal-key','sample_count'=>count($datasets),'schema'=>get_option('oj_code_lab_db_version'),
      'credential_option_hash'=>hash('sha256',wp_json_encode(get_option('oj_code_lab_kaggle_credentials',null))));
}
wp_set_current_user(1);
if($phase==='fresh-install'){
    $expected=json_decode(getenv('OCL_REHEARSAL_FRESH_SAMPLE_KEYS'),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($expected) || !$expected || array_filter($expected,static fn($key)=>!is_string($key)||preg_match('/^[a-z0-9_]+$/D',$key)!==1)){throw new RuntimeException('Explicit expected fresh sample keys required');}
    sort($expected);oj_code_lab_activate();
    must(Installer::tables_exist() && get_option('oj_code_lab_db_version')==='2','fresh new ZIP creates verified schema 2');
    $rows=Datasets::all();$keys=array_column($rows,'sampleKey');sort($keys);
    must($keys===$expected,'fresh new ZIP seeds exactly the approved sample keys');
    must(count($rows)===count($expected),'fresh new ZIP has no unapproved legacy sample rows');
    foreach($rows as $row){
        $content=\OjCodeLab\Datasets\Upload_Store::read(Datasets::upload_key($row['id']));
        must(is_string($content) && hash('sha256',$content)===hash_file('sha256',$plugin.'/resources/sample-datasets/'.$row['fileName']),'fresh sample content matches packaged '.$row['sampleKey']);
    }
    $ids=array_column($rows,'id');oj_code_lab_activate();
    must(array_column(Datasets::all(),'id')===$ids,'repeat activation preserves approved fresh sample without duplicates');
}elseif($phase==='install'){
    oj_code_lab_activate();must((int)$wpdb->get_var("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('oclops_oj_code_lab_notebooks','oclops_oj_code_lab_datasets','oclops_oj_code_lab_dataset_cache','oclops_oj_code_lab_uploads')")===4,'old ZIP installs four tables');$oldSamples=count(glob($plugin.'/resources/sample-datasets/*.csv'));must($oldSamples>0 && count(Datasets::all())===$oldSamples,'old ZIP seeds exactly its bundled samples');
    $state['student']=wp_insert_user(array('user_login'=>'ops_learner','user_pass'=>wp_generate_password(),'user_email'=>'learner@example.invalid','role'=>'subscriber'));
    $cells=array(array('id'=>'saved','code'=>'print("복원 검증")','outputs'=>array(array('type'=>'stream','text'=>'복원 검증'))));
    if(defined(Books::class.'::FORMAT_VERSION') && Books::FORMAT_VERSION>=2){
        $cells[]=array('id'=>'existing-note','type'=>'text','code'=>"  RC6에서 기록한 메모\n<script>문자 그대로</script> & **풀이**\n다음 줄  ",'outputs'=>array());
    }
    // Both the legacy two-argument save API and RC1's revision API are supported.
    $saveMethod=new ReflectionMethod(Books::class,'save_for_user');
    $result=$saveMethod->getNumberOfParameters()>=3
        ? Books::save_for_user($state['student'],$cells,0)
        : Books::save_for_user($state['student'],$cells);
    must(!is_wp_error($result),'old ZIP saves notebook');
    if(count($cells)>1){$saved=Books::find_for_user($state['student']);must($saved['cells'][1]['type']==='text' && $saved['cells'][1]['code']===$cells[1]['code'],'old mixed-format release stores the existing note before upgrade');}
    Kaggle_Settings::save('fixture_user','fixture-rehearsal-key');
    Datasets::delete(Datasets::all()[0]['id']);$state['before']=snapshot();must($state['before']['key_readable'],'old ciphertext decrypts');must($state['before']['sample_count']===$oldSamples-1,'intentional sample removal recorded');
}elseif($phase==='upgrade'){
    oj_code_lab_activate();$after=snapshot();
    foreach(array('cells','cell_types','body_hashes','datasets','uploads','key_readable','sample_count') as $key){must($after[$key]===$state['before'][$key],'upgrade preserves '.$key);}
    must(get_option('oj_code_lab_db_version')==='2','upgrade reaches schema 2');
    $book=Books::find_for_user((int)$state['student']);must($book['revision']===1,'old notebook receives initial revision');
    if(in_array('text',$state['before']['cell_types'],true)){must($book['cells'][1]['type']==='text' && hash('sha256',$book['cells'][1]['code'])===$state['before']['body_hashes'][1],'upgrade preserves the pre-existing mixed-format note unchanged');}
    $nextCells=array(array('id'=>'after','code'=>'print("업데이트 후")','outputs'=>array()));
    if(defined(Books::class.'::FORMAT_VERSION') && Books::FORMAT_VERSION>=2){
        must($book['formatVersion']===2 && $book['cells'][0]['type']==='code','upgrade exposes legacy notebook as code without rewriting stored bytes');
        $nextCells[]=array('id'=>'note','type'=>'text','code'=>"  한글 복원 기록\n<script>문자 그대로</script> & **정리**\n다음 줄  ",'outputs'=>array(array('type'=>'error','text'=>'ignored')));
    }
    must(is_array(Books::save_for_user($state['student'],$nextCells,1)),'new ZIP accepts revision save');
    if(count($nextCells)>1){
        $mixed=Books::find_for_user($state['student']);
        must($mixed['cells'][1]['type']==='text' && $mixed['cells'][1]['code']===$nextCells[1]['code'] && $mixed['cells'][1]['outputs']===array(),'mixed text literal content persists before backup without outputs');
        $summary=Books::summaries_for_users(array($state['student']));must($summary[$state['student']]['cellCount']===2 && !$summary[$state['student']]['hasError'],'mixed summary is retained before backup');
    }
    Kaggle_Settings::save('fixture_user','fixture-rehearsal-key');must(str_starts_with(get_option('oj_code_lab_kaggle_credentials')['key'],'v2:'),'credential update writes authenticated cipher');
    \OjCodeLab\Jobs\Dataset_Cache_Cleanup::maybe_schedule();$state['after']=snapshot();
}elseif($phase==='verify-old' || $phase==='verify-new'){
    $expected=$state[$phase==='verify-old'?'before':'after'];$actual=snapshot();
    foreach($expected as $key=>$value){must($actual[$key]===$value,'restored '.$key.' matches backup');}
    // The snapshot compares the original credential option hash, including its
    // absence in legacy releases and the existing combined option in RC1.
    if($phase==='verify-new'){must((bool)wp_next_scheduled('oj_code_lab_cache_cleanup'),'new backup restores scheduled cleanup');}
}else{throw new RuntimeException('Unknown phase');}
file_put_contents($statePath,json_encode($state,JSON_PRETTY_PRINT));
echo wp_json_encode(array('phase'=>$phase,'plugin'=>OJ_CODE_LAB_VERSION,'wordpress'=>$wp_version,'php'=>PHP_VERSION,'db'=>$wpdb->db_version(),'checks'=>$checks),JSON_PRETTY_PRINT).PHP_EOL;
