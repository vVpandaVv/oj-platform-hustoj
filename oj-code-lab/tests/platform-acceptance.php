<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$work=realpath(getenv('OCL_PLATFORM_TEST_WORK'));
if (!$work || !str_starts_with(basename($work),'ocl-platform-acceptance-')) { throw new RuntimeException('Private runner work directory required'); }
$password=getenv('OCL_PLATFORM_TEST_PASSWORD');
if (!preg_match('/^[a-f0-9]{48}$/D',$password)) { throw new RuntimeException('Generated private test credentials required'); }
$report=['date'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y-m-d'),'passed'=>false,'scope'=>'Isolated real WordPress, MariaDB, full OJ Platform kernel/setup and real class/group/permission services; no gateway fixture or production access','checks'=>[],'limitations'=>['CLI invokes real WordPress authentication/REST dispatch; no browser cookie, proxy, login-page or cross-browser acceptance.','Local OJ Platform version may differ from the deployed platform; the exact version is recorded.']];
$stage='database';
register_shutdown_function(static function()use(&$report,&$stage):void {
 $last=error_get_last();if($last && in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)){$report['error']=['stage'=>$stage,'type'=>'fatal','message'=>$last['message']];}
 file_put_contents(getenv('OCL_PLATFORM_TEST_REPORT'),json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
});
set_exception_handler(static function(Throwable $error)use(&$report,&$stage):void {$report['error']=['stage'=>$stage,'type'=>get_class($error),'message'=>$error->getMessage()];fwrite(STDERR,'Acceptance failed at '.$stage.': '.$error->getMessage().PHP_EOL);exit(1);});
function accept(bool $ok,string $label):void {global $report;if(!$ok){throw new RuntimeException($label);} $report['checks'][]=$label;}
$dbName='ocl_platform_test_'.gmdate('YmdHis').'_'.bin2hex(random_bytes(3));
$db=new mysqli('127.0.0.1','root',$password,'',33388);$db->query('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db->close();
define('ABSPATH',rtrim(getenv('OCL_PLATFORM_TEST_WP'),'/\\').'/');
define('DB_NAME',$dbName);define('DB_HOST','127.0.0.1:33388');define('DB_USER','root');define('DB_PASSWORD',$password);define('DB_CHARSET','utf8mb4');define('DB_COLLATE','');
define('WP_CONTENT_DIR',$work.'/content');mkdir(WP_CONTENT_DIR,0700,true);
define('WP_PLUGIN_DIR',dirname(getenv('OCL_PLATFORM_TEST_PLUGIN')));
define('WP_INSTALLING',true);define('WP_DEBUG',false);define('DISABLE_WP_CRON',true);define('WP_HTTP_BLOCK_EXTERNAL',true);define('WP_HOME','http://127.0.0.1:33389');define('WP_SITEURL',WP_HOME);
foreach(['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $key){define($key,bin2hex(random_bytes(32)));}
$table_prefix='oclpa_';$_SERVER['HTTP_HOST']='127.0.0.1:33389';$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/';
$stage='wordpress';require ABSPATH.'wp-settings.php';require_once ABSPATH.'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail',static fn()=>false);
$adminPassword=bin2hex(random_bytes(16));wp_install('Disposable Platform Acceptance','acceptance_admin','admin@example.invalid',false,'',$adminPassword);wp_set_current_user(1);
$report['wordpress']=$wp_version;$report['php']=PHP_VERSION;$report['db']=$wpdb->db_version();
$stage='platform-kernel';$platform=getenv('OCL_PLATFORM_TEST_ROOT');require $platform.'/oj-platform.php';require getenv('OCL_PLATFORM_TEST_PLUGIN').'/oj-code-lab.php';
$report['platform']=OJ_PLATFORM_VERSION;$report['plugin']=OJ_CODE_LAB_VERSION;
$report['tested_php_sha256']=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(getenv('OCL_PLATFORM_TEST_PLUGIN'),FilesystemIterator::SKIP_DOTS)) as $f){if($f->isFile()&&$f->getExtension()==='php'&&!str_contains(str_replace('\\','/',$f->getPathname()),'/tests/')&&!str_contains(str_replace('\\','/',$f->getPathname()),'/scripts/')){$report['tested_php_sha256'][str_replace('\\','/',substr($f->getPathname(),strlen(getenv('OCL_PLATFORM_TEST_PLUGIN'))+1))]=hash_file('sha256',$f->getPathname());}}
$environment=\CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment::fromPluginFile(OJ_PLATFORM_FILE,OJ_PLATFORM_VERSION,'6.6','8.1');
$root=new \CodingPanda\OjPlatform\Kernel\Composition\CompositionRoot($environment,new \CodingPanda\OjPlatform\Kernel\WordPress\WordPressHookRegistrar(),new \CodingPanda\OjPlatform\Kernel\WordPress\WordPressErrorReporter());
$container=$root->build();$boot=$container->get(\CodingPanda\OjPlatform\Kernel\Boot\Kernel::class)->boot();
$catalog=$container->get(\CodingPanda\OjPlatform\Kernel\Addon\AcceptedAddonCatalog::class);
accept($catalog->find('oj-code-lab')?->isAccepted()===true,'full platform kernel accepts real Code Lab manifest');
accept(\OjCodeLab\Addon\Runtime_Bridge::contexts() instanceof \CodingPanda\OjPlatform\Kernel\Addon\RealAddonContextGateway,'runtime bridge receives actual Platform context gateway');
$stage='platform-setup';$setup=$container->get(\CodingPanda\OjPlatform\Kernel\Setup\SetupCoordinator::class);$setup->run();
accept($setup->inspect()->allowsRuntime(),'real SetupCoordinator installs all platform tables and permits runtime');
$report['platform_tables']=$setup->inspect()->targetTableCount();
oj_code_lab_activate();
$stage='normal-platform-bootstrap';do_action('plugins_loaded');
accept(has_action('admin_post_oj_class_membership_command')!==false,'normal platform boot registers real classroom management action');
accept(has_action('admin_post_oj_group_add_member')!==false,'normal platform boot registers real group management action');

$stage='real-auth-and-role-setup';
$users=$container->get(\CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway::class);
$roles=$container->get(\CodingPanda\OjPlatform\Modules\Auth\Application\UserRoleService::class);
$assignments=new \CodingPanda\OjPlatform\Modules\Permission\Infrastructure\WordPress\WordPressAddonCapabilityAssignmentGateway();
$identities=[];$passwords=[];
foreach(['learner_a','learner_b','teacher_a','teacher_b','operator'] as $login){$passwords[$login]=bin2hex(random_bytes(16));$identities[$login]=$users->createUser($login,$login.'@example.invalid',$passwords[$login],$login,'subscriber')->userId();}
[$learnerA,$learnerB,$teacherA,$teacherB,$operator]=array_values($identities);
$roles->assign(1,$teacherA,['oj_group_manager']);$roles->assign(1,$teacherB,['oj_group_manager']);$roles->assign(1,$operator,['oj_settings_manager','oj_class_manager']);
accept(in_array('oj_group_manager',$users->findById($teacherA)->roles(),true),'real UserRoleService persists educator role assignment');
accept($users->verifyCredentials('teacher_a','wrong-password')===null,'real WordPress authentication rejects wrong credentials');
accept($users->verifyCredentials('teacher_a',$passwords['teacher_a'])?->userId()===$teacherA,'real Platform user gateway authenticates a disposable teacher');
$users->establishSession($teacherA,false);accept(get_current_user_id()===$teacherA,'real Platform gateway establishes WordPress user session');
function as_actor(int $id):void {wp_set_current_user(0);if($id){clean_user_cache($id);}wp_set_current_user($id);}
function api(string $method,string $path,array $params=[]):WP_REST_Response {$r=new WP_REST_Request($method,'/oj-code-lab/v1'.$path);if($method==='GET'){$r->set_query_params($params);}else{$r->set_body_params($params);}return rest_do_request($r);}
function context_path(string $type,int $id,?int $learner=null):string {return '/contexts/'.$type.'/'.rawurlencode($type.':'.$id).'/notebooks'.($learner!==null?'/'.$learner:'');}
$use=\CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::wordpress('oj_code_lab_use');
$review=\CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::wordpress('oj_code_lab_review');
$settings=\CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::wordpress('oj_code_lab_settings');
as_actor(1);accept(api('GET','/contexts')->get_status()===403,'WordPress administrator is not automatically granted addon review');
as_actor($learnerA);accept(api('GET','/notebook')->get_status()===403,'subscriber cannot use addon before explicit role grant');
$assignments->grantToRole('subscriber',$use);$assignments->grantToRole('oj_group_manager',$review);$assignments->grantToRole('oj_settings_manager',$settings);
$container->get(\CodingPanda\OjPlatform\Modules\Permission\Application\PermissionInstaller::class)->synchronizeRoles();
accept(in_array('subscriber',$assignments->rolesGrantedTo($use),true)&&in_array('oj_group_manager',$assignments->rolesGrantedTo($review),true),'real role synchronization preserves explicit addon grants');
$stage='real-class-and-group-storage';
$classes=$container->get(\CodingPanda\OjPlatform\Modules\Classes\Application\ClassService::class);$groups=$container->get(\CodingPanda\OjPlatform\Modules\Groups\Application\GroupService::class);
function class_command(int $id,string $label,array $instructors):\CodingPanda\OjPlatform\Modules\Classes\Application\ClassSaveCommand {return new \CodingPanda\OjPlatform\Modules\Classes\Application\ClassSaveCommand($id,$label,strtolower(str_replace(' ','-',$label)),'','private','private',true,null,null,null,null,$instructors,[],[]);}
$classA=$classes->save(1,class_command(0,'Acceptance A',[$teacherA]));$classB=$classes->save(1,class_command(0,'Acceptance B',[$teacherB]));
$classes->membership(1,$classA,'approve',$learnerA);$classes->membership(1,$classB,'approve',$learnerB);
accept($classes->activeLearnerUserIds($classA)===[$learnerA]&&$classes->activeLearnerUserIds($classB)===[$learnerB],'real class services persist isolated classroom memberships');
function group_command(string $label):\CodingPanda\OjPlatform\Modules\Groups\Application\GroupSaveCommand {return new \CodingPanda\OjPlatform\Modules\Groups\Application\GroupSaveCommand(0,$label,strtolower(str_replace(' ','-',$label)),'','private','manual',true,null,'normal',null);}
$groupA=$groups->save(1,group_command('Acceptance Group A'));$groupB=$groups->save(1,group_command('Acceptance Group B'));
$groups->manager(1,$groupA,$teacherA,true);$groups->manager(1,$groupB,$teacherB,true);$groups->membership(1,$groupA,'add',$learnerA);$groups->membership(1,$groupB,'add',$learnerB);
accept($groups->activeLearnerUserIds($groupA)===[$learnerA]&&$groups->activeLearnerUserIds($groupB)===[$learnerB],'real group services persist isolated group memberships');
$stage='learner-and-educator-rest';
$noteA="  학습 메모\n<script>문자 그대로</script> & 한글  ";
$cellsA=[['id'=>'a','code'=>'print("learner A")','outputs'=>[]],['id'=>'note-a','type'=>'text','code'=>$noteA,'outputs'=>[]]];$cellsB=[['id'=>'b','code'=>'print("learner B")','outputs'=>[]]];
as_actor($learnerA);accept(api('PUT','/notebook',['formatVersion'=>2,'revision'=>0,'cells'=>$cellsA,'user_id'=>$learnerB])->get_status()===200,'learner A saves own notebook through real REST');
as_actor($learnerB);accept(api('GET','/notebook')->get_data()['cells']===[],'forged save target does not write learner B notebook');
accept(api('PUT','/notebook',['formatVersion'=>2,'revision'=>0,'cells'=>$cellsB])->get_status()===200,'learner B saves an independent notebook');
as_actor($learnerA);accept(api('GET','/notebook')->get_data()['cells'][0]['code']===$cellsA[0]['code'],'learner notebook read remains isolated by authenticated user');
accept(api('GET',context_path('class',$classA))->get_status()===403,'learner cannot access teacher roster despite class membership');
as_actor($teacherA);$a=api('GET',context_path('class',$classA));accept($a->get_status()===200&&array_column($a->get_data()['rows'],'userId')===[$learnerA],'assigned teacher receives only own class learner summary');
accept(api('GET',context_path('class',$classA,$learnerA))->get_status()===200,'assigned teacher reads own learner notebook through actual context gateway');
$teacherBook=api('GET',context_path('class',$classA,$learnerA))->get_data();accept($teacherBook['cells'][1]['type']==='text' && $teacherBook['cells'][1]['code']===$noteA && $teacherBook['cells'][1]['outputs']===[],'real assigned teacher reads mixed text type and literal body');
accept(api('GET',context_path('class',$classB))->get_status()===403,'teacher is denied another actual class');
accept(api('GET',context_path('class',$classA,$learnerB))->get_status()===403,'teacher cannot forge learner ID from another class');
accept(api('GET',context_path('group',$groupA,$learnerA))->get_status()===200,'assigned group manager reads current member notebook');
accept(api('GET',context_path('group',$groupB))->get_status()===403,'group manager is denied unrelated group');
$contexts=api('GET','/contexts')->get_data();
accept(count($contexts)===2,'actual teaching context list contains only assigned class and group');
$stage='membership-revocation-and-transfer';
$classes->membership(1,$classA,'remove',$learnerA);as_actor($teacherA);
accept(api('GET',context_path('class',$classA,$learnerA))->get_status()===403,'class membership removal immediately revokes teacher detail access');
accept(api('GET',context_path('class',$classA))->get_data()['rows']===[],'removed learner disappears from actual classroom roster');
$classes->membership(1,$classB,'approve',$learnerA);as_actor($teacherB);
accept(api('GET',context_path('class',$classB,$learnerA))->get_status()===200,'destination class teacher can read transferred learner');
as_actor($teacherA);accept(api('GET',context_path('class',$classB,$learnerA))->get_status()===403,'prior teacher cannot follow learner to unassigned class');
as_actor($learnerA);accept(api('GET','/notebook')->get_data()['cells'][0]['code']===$cellsA[0]['code'],'class transfer preserves learner owned notebook');
accept(api('GET','/notebook')->get_data()['cells'][1]['code']===$noteA,'class transfer preserves the learner text note');
$groups->membership(1,$groupA,'remove',$learnerA);as_actor($teacherA);
accept(api('GET',context_path('group',$groupA,$learnerA))->get_status()===403,'group membership removal immediately revokes manager detail access');
$groups->membership(1,$groupB,'add',$learnerA);as_actor($teacherB);
accept(api('GET',context_path('group',$groupB,$learnerA))->get_status()===200,'destination group manager reads transferred member');
$stage='staff-and-role-revocation';
$classes->save(1,class_command($classB,'Acceptance B',[]));as_actor($teacherB);
accept(api('GET',context_path('class',$classB))->get_status()===403,'removing assigned class instructor revokes context access despite review capability');
$groups->manager(1,$groupB,$teacherB,false);as_actor($teacherB);
accept(api('GET',context_path('group',$groupB))->get_status()===403,'removing group manager revokes context access despite review capability');
$assignments->revokeFromRole('oj_group_manager',$review);as_actor($teacherA);
accept(api('GET',context_path('class',$classA))->get_status()===403,'revoking addon review from role denies a still assigned teacher');
$assignments->grantToRole('oj_group_manager',$review);as_actor($teacherA);
accept(api('GET',context_path('class',$classA))->get_status()===200,'explicit addon role grant restoration restores authorized review');
$roles->assign(1,$teacherA,[]);as_actor($teacherA);
accept(api('GET',context_path('class',$classA))->get_status()===403,'actual UserRoleService removal revokes educator access');
$denied=false;try{$roles->assign($learnerA,$learnerB,['oj_class_manager']);}catch(DomainException){$denied=true;}
accept($denied,'learner cannot assign privileged platform role to another user');
$assignments->revokeFromRole('subscriber',$use);as_actor($learnerA);
accept(api('PUT','/notebook',['formatVersion'=>2,'revision'=>1,'cells'=>[]])->get_status()===403,'revoked learner use permission blocks saving existing notebook');
accept(\OjCodeLab\Database\Notebook_Repository::find_for_user($learnerA)['cells'][0]['code']===$cellsA[0]['code'],'permission denied save preserves stored notebook');
accept(\OjCodeLab\Database\Notebook_Repository::find_for_user($learnerA)['cells'][1]['code']===$noteA,'revoked permission cannot erase the learner text note');
$stage='operator-settings-permissions';
as_actor($operator);accept(api('GET','/settings/kaggle')->get_status()===200,'explicit operator addon settings role can read configuration status');
as_actor($learnerB);accept(api('GET','/settings/kaggle')->get_status()===403,'learner cannot read operator settings');
$roles->assign(1,$operator,[]);as_actor($operator);
accept(api('GET','/settings/kaggle')->get_status()===403,'actual operator role removal revokes settings access');
$report['platform_module_count']=count($boot->moduleIds());
$report['runtime_fixture']=false;$report['source_gateway_class']=get_class(\OjCodeLab\Addon\Runtime_Bridge::contexts());
$report['checks_count']=count($report['checks']);
$report['passed']=true;
echo count($report['checks'])." real platform acceptance checks passed.\n";
