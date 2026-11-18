<?php
declare(strict_types=1);
require __DIR__.'/typing-support.php';
use EduGames\Typing\{Rules,Repository,Service};
$checks=0;
function wp_verify_nonce($nonce,$action){return $nonce==='valid'&&$action==='wp_rest';}
final class Typing_Delete_Request extends WP_REST_Request {
    public function __construct(private int $id,private string $nonce='valid'){parent::__construct('code-typing');}
    public function offsetGet(mixed $key): mixed {return match($key){'store'=>'typing','id'=>$this->id,default=>parent::offsetGet($key)};}
    public function get_header($name){return $this->nonce;}
}
function check(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rejects(callable $fn): void {try{$fn();}catch(InvalidArgumentException){check(true,'rejected');return;}throw new RuntimeException('Expected rejection');}
$sample=array(array('id'=>'tiny','title'=>'tiny','language'=>'python','content'=>"ab = 1\n    c"));
$s=Rules::create($sample,60,95,1000);
$spaces=$s;for($i=0;$i<100;$i++)$spaces=Rules::apply($spaces,array(array('start'=>$i,'delete'=>0,'text'=>' ','kind'=>'type','at'=>$i*50)),1000+$i*.05);
check(Rules::metrics($spaces,1060)['effectiveCpm']===0.0&&$spaces['completed']===0,'space hold cannot earn code speed or completions');
$recovered=Rules::apply($spaces,array(array('start'=>0,'delete'=>1,'text'=>'a','kind'=>'type','at'=>5100),array('start'=>3,'delete'=>1,'text'=>'=','kind'=>'type','at'=>5200)),1005.2);
check(Rules::metrics($recovered,1006)['validCharacters']===2&&Rules::metrics($recovered,1006)['effectiveCpm']>0,'valid nonspace positions count after space spam despite other mistakes');
$accuracy=Rules::metrics($recovered,1006)['inputAccuracy'];
for($i=0;$i<10;$i++)$recovered=Rules::apply($recovered,array(array('start'=>3,'delete'=>1,'text'=>'=','kind'=>'type','at'=>5300+$i)),1006);
check(Rules::metrics($recovered,1006)['validCharacters']===2&&Rules::metrics($recovered,1006)['inputAccuracy']===$accuracy,'repeated correct replacements inflate neither speed nor accuracy');
$recovered=Rules::apply($recovered,array(array('start'=>3,'delete'=>1,'text'=>'','kind'=>'delete','at'=>5400)),1006);
check(Rules::metrics($recovered,1006)['validCharacters']===1,'deleting a correct character removes its current speed credit');
$s=Rules::apply($s,array(array('start'=>0,'delete'=>0,'text'=>'x','kind'=>'type','at'=>0)),1000);
$s=Rules::apply($s,array(array('start'=>0,'delete'=>1,'text'=>'','kind'=>'delete','at'=>100)),1000.1);
$s=Rules::apply($s,array(array('start'=>0,'delete'=>0,'text'=>'a','kind'=>'type','at'=>200)),1000.2);
check(Rules::metrics($s,1001)['inputAccuracy']===50.0,'correcting a mistake does not erase it');
for($i=0;$i<20;$i++){$s=Rules::apply($s,array(array('start'=>0,'delete'=>1,'text'=>'a','kind'=>'type','at'=>300+$i)),1001);}
check(Rules::metrics($s,1001)['inputAccuracy']===50.0,'retyping a correct position cannot wash errors away');
$broken=Rules::create($sample,60,95,1000);$broken=Rules::apply($broken,array(array('start'=>0,'delete'=>0,'text'=>'xb = 1','kind'=>'type','at'=>500)),1001);
check(Rules::metrics($broken,1001)['validCharacters']===3,'a first-position typo does not discard later correct nonspace positions');
$legacyBroken=$broken;$legacyBroken['version']=Rules::LEGACY_VERSION;
check(Rules::metrics($legacyBroken,1001)['validCharacters']===0&&Rules::view($legacyBroken,1001)['version']===Rules::LEGACY_VERSION,'old sessions retain their prefix rule and version');
$s=Rules::create($sample,60,95,1000);
$at=0;foreach(Rules::chars($sample[0]['content']) as $char){$s=Rules::apply($s,array(array('start'=>count(Rules::chars($s['input'])),'delete'=>0,'text'=>$char,'kind'=>'type','at'=>$at+=100)),1000+$at/1000);}
check($s['completed']===1&&$s['sampleIndex']===1&&$s['input']==='','only exact complete code advances');
$finished=Rules::apply($s,array(),1060,true);$m=Rules::metrics($finished,1060);
check($m['validCharacters']===5&&$m['effectiveCpm']===5.0&&$m['inputAccuracy']===100.0,'whitespace excluded from speed and full duration denominator');
rejects(fn()=>Rules::apply($s,array(),1020,true));
rejects(fn()=>Rules::apply($s,array(array('start'=>0,'delete'=>0,'text'=>'a','kind'=>'type','at'=>60001)),1060));
rejects(fn()=>Rules::apply($s,array(array('start'=>50,'delete'=>0,'text'=>'a','kind'=>'type','at'=>2000)),1002));
rejects(fn()=>Rules::apply($s,array(array('start'=>0,'delete'=>0,'text'=>'aaa','kind'=>'tab','at'=>2000)),1002));
$late=Rules::apply($s,array(array('start'=>0,'delete'=>0,'text'=>'a','kind'=>'type','at'=>2000)),1063,true);
check($late['lateBatch']&&$late['input']==='','late transport preserves only confirmed work');
$stale=Rules::apply(Rules::create($sample,60,95,1000),array(array('start'=>0,'delete'=>0,'text'=>'a','kind'=>'type','at'=>0)),1020);
check(Rules::metrics($stale,1020)['verificationIssue']==='stale_batch'&&!Rules::metrics($stale,1020)['qualified'],'backdated bulk history requires review, not automatic ranking');

$wpdb=new Typing_Test_DB();typing_test_runtime();$GLOBALS['test_user_id']=1;$GLOBALS['caps']=array('edu_games_manage');
$catalog=Service::catalog();$ids=array_column(array_filter($catalog['samples'],fn($s)=>$s['language']==='python'),'id');
$config=array('title'=>'1·2반 코드 평가','language'=>'python','durationSeconds'=>60,'minAccuracy'=>95,'maxAttempts'=>1,'sampleIds'=>array_slice($ids,0,2),
    'contexts'=>array(array('type'=>'group','id'=>'g1','revision'=>'r1','label'=>'1반'),array('type'=>'group','id'=>'g2','revision'=>'r1','label'=>'2반')));
$created=Service::create_evaluation($config);check(!is_wp_error($created),'teacher can create evaluation');$id=$created['evaluation']['id'];
check($created['evaluation']['version']===Rules::VERSION,'new group evaluations freeze the corrected scoring version');
check($created['evaluation']['targetCount']===60,'two groups assigned once per student');
$bad=$config;$bad['contexts'][0]['id']='foreign';check(is_wp_error(Service::create_evaluation($bad)),'foreign group denied by platform roster');
$bad=$config;$bad['contexts'][0]['revision']='stale';check(is_wp_error(Service::create_evaluation($bad)),'stale roster rejected');
Service::set_status($id,'open');$GLOBALS['test_user_id']=7;$GLOBALS['caps']=array('edu_games_play');
$assigned=Service::assigned()['evaluations'];check(count($assigned)===1,'assigned student can discover evaluation');
check($assigned[0]['participation']['status']==='absent'&&$assigned[0]['result']===null&&$assigned[0]['participation']['attemptsRemaining']===1,'unattempted work has no fabricated zero result');
check(count(Service::preview($id)['samples'])===2,'assigned student loads the frozen evaluation code before the clock starts');
check(is_wp_error(Service::results($id)),'student cannot read class results');
$run=Service::start(array('evaluationId'=>$id));check(!is_wp_error($run)&&$run['attemptNo']===1,'assigned student starts');
$assigned=Service::assigned()['evaluations'][0];check($assigned['participation']['status']==='running'&&$assigned['participation']['activeAttemptNo']===1&&$assigned['result']===null,'active attendance is distinct from a finalized representative result');
check(Service::start(array('evaluationId'=>$id))['token']===$run['token'],'concurrent starts reuse active attempt');
$GLOBALS['test_user_id']=70;check(is_wp_error(Service::start(array('evaluationId'=>$id))),'outsider cannot join assigned evaluation');
check(is_wp_error(Service::preview($id)),'outsider cannot read frozen evaluation code');
check(is_wp_error(Service::update(array('token'=>$run['token']),true)),'other user cannot resume token');
$GLOBALS['test_user_id']=7;
$text=Rules::chars($run['samples'][0]['content'])[0];$request=array('token'=>$run['token'],'revision'=>0,'requestId'=>'typing-test-request-1','events'=>array(array('start'=>0,'delete'=>0,'text'=>$text,'kind'=>'type','at'=>0)),
    'score'=>9999999,'effectiveCpm'=>99999,'accuracy'=>100);
$saved=Service::update($request);check($saved['metrics']['validCharacters']<=1&&$saved['revision']===1,'server replays edits instead of trusting claimed metrics');
$duplicate=Service::update($request);check($duplicate['revision']===1&&$duplicate['metrics']['attempted']===$saved['metrics']['attempted'],'lost response retry idempotent');
check(is_wp_error(Service::update(array_replace($request,array('requestId'=>'typing-test-request-2')))),'stale revision rejected');
$row=Repository::run($run['token'],7);$state=Repository::state($row);$state['deadline']=microtime(true)-3;$state['startedAt']=$state['deadline']-60;
$wpdb->db->prepare('UPDATE wp_edu_games_typing_runs SET state_json=? WHERE id=?')->execute(array(json_encode($state),$row['id']));
$resumed=Service::update(array('token'=>$run['token']),true);check($resumed['status']==='finished','disconnected run finalizes on server');
$assigned=Service::assigned()['evaluations'][0];check($assigned['participation']['status']==='finished'&&$assigned['result']['id']===(int)$row['id']&&!$assigned['participation']['canStart'],'student sees their finalized result and exhausted attempts');
check(!isset($assigned['attempts'][0]['token'])&&!isset($assigned['result']['token'])&&!isset($assigned['result']['input']),'finished self-service summary exposes neither run tokens nor input journals');
check(is_wp_error(Service::start(array('evaluationId'=>$id))),'attempt limit cannot be bypassed by refreshing');
$GLOBALS['test_user_id']=2;$GLOBALS['caps']=array('edu_games_manage');check(is_wp_error(Service::results($id)),'other teacher cannot read evaluation');
$GLOBALS['test_user_id']=1;
$report=Service::results($id,2,20);check($report['pagination']['total']===60&&count($report['rows'])===20,'whole assigned roster paginated');
check($report['summary']['completed']===1&&$report['summary']['absent']===59,'nonparticipants retained in results');
check(count(Service::results($id,1,20,true)['rows'])===60,'authorized export includes all assigned students');
Service::set_status($id,'closed');$GLOBALS['test_user_id']=8;$GLOBALS['caps']=array('edu_games_play');check(is_wp_error(Service::start(array('evaluationId'=>$id))),'closed evaluation rejects new entry');
$assigned=Service::assigned()['evaluations'][0];check($assigned['participation']['status']==='absent'&&$assigned['result']===null&&!$assigned['participation']['canStart'],'closed nonparticipation is not presented as a completed result');
$solo=Service::start(array('language'=>'python','sampleId'=>$ids[0]));check(!is_wp_error($solo),'independent new solo assessment');
$wpdb->insert('wp_edu_games_scores',array('game_slug'=>'code-typing','score'=>999999,'user_id'=>8));
$board=Service::leaderboard($ids[0]);check($board['pagination']['total']===0,'legacy high scores and group evaluations do not enter new solo ranking');
$text=implode('',array_slice(Rules::chars($solo['samples'][0]['content']),0,4));
$solo=Service::update(array('token'=>$solo['token'],'revision'=>0,'requestId'=>'solo-verified-input','events'=>array(array('start'=>0,'delete'=>0,'text'=>$text,'kind'=>'type','at'=>0))));
$row=Repository::run($solo['token'],8);$state=Repository::state($row);$state['deadline']=microtime(true)-3;
$wpdb->db->prepare('UPDATE wp_edu_games_typing_runs SET state_json=?,deadline_at=? WHERE id=?')->execute(array(json_encode($state),gmdate('Y-m-d H:i:s',time()-5),$row['id']));
check(Service::leaderboard($ids[0])['pagination']['total']===1,'ranking read finalizes expired disconnected solo work at the last checkpoint');
$solo=Service::update(array('token'=>$solo['token']),true);
check(Service::leaderboard($ids[0])['pagination']['total']===1,'only qualified new solo results are ranked');
check(Service::leaderboard($ids[1])['pagination']['total']===0,'different first example is a separate benchmark');
$legacy=EduGames\Rest\Controllers\Games_Controller::record_score(new WP_REST_Request('code-typing',array()));check(is_wp_error($legacy)&&$legacy->data['status']===409,'old client cannot submit new unverifiable counts');
$GLOBALS['test_user_id']=1;$GLOBALS['caps']=array('edu_games_manage','manage_options');
$entry=Service::leaderboard($ids[0])['leaderboard'][0];check(str_contains($entry['deleteUrl'],'/records/typing/'),'new solo ranking keeps administrator moderation');
check(count(Service::results($id)['rows'])===20,'summary results do not need complete input journals');
$soloRow=Repository::run($solo['token'],8);$groupRow=Repository::run($run['token'],7);
$oldSolo=$soloRow;unset($oldSolo['id']);$oldSolo['user_id']=9;$oldSolo['token']=bin2hex(random_bytes(24));$oldSolo['version']=Rules::LEGACY_VERSION;$oldState=Repository::state($soloRow);$oldState['version']=Rules::LEGACY_VERSION;$oldSolo['state_json']=wp_json_encode($oldState);$oldSolo['metrics_json']=wp_json_encode(Rules::metrics($oldState,microtime(true)));$wpdb->insert(Repository::table('runs'),$oldSolo);$oldSoloId=$wpdb->insert_id;
check(Service::leaderboard($ids[0])['pagination']['total']===1&&Service::leaderboard($ids[0],1,20,Rules::LEGACY_VERSION)['pagination']['total']===1,'v2 and v3 solo rankings never mix and old ranking remains readable');
check(EduGames\Rest\Controllers\Ranking_Records_Controller::delete(new Typing_Delete_Request($oldSoloId))['deleted'],'old v2 solo ranking retains administrator moderation');
check(EduGames\Rest\Controllers\Ranking_Records_Controller::delete(new Typing_Delete_Request((int)$groupRow['id']))->data['status']===404,'public ranking deletion cannot remove a formal evaluation');
check(EduGames\Rest\Controllers\Ranking_Records_Controller::delete(new Typing_Delete_Request((int)$soloRow['id'],''))->data['status']===403,'new ranking deletion still requires a valid nonce');
check(EduGames\Rest\Controllers\Ranking_Records_Controller::delete(new Typing_Delete_Request((int)$soloRow['id']))['deleted'],'administrator can remove only the selected new solo result');
check((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_edu_games_scores')===1&&Repository::run($run['token'],7)!==null,'new ranking deletion preserves legacy records and formal evaluation results');
$GLOBALS['test_user_id']=1;$GLOBALS['caps']=array('edu_games_manage');$wpdb->fail=true;
check(is_wp_error(Service::evaluations()),'query failures visible');$wpdb->fail=false;
$oldEvaluation=Service::create_evaluation($config);$oldId=$oldEvaluation['evaluation']['id'];$oldConfig=json_decode(Repository::evaluation($oldId)['config_json'],true);$oldConfig['version']=Rules::LEGACY_VERSION;
$wpdb->db->prepare('UPDATE wp_edu_games_typing_evaluations SET config_json=? WHERE id=?')->execute(array(wp_json_encode($oldConfig),$oldId));Service::set_status($oldId,'open');
$GLOBALS['test_user_id']=9;$GLOBALS['caps']=array('edu_games_play');$oldRun=Service::start(array('evaluationId'=>$oldId));
check(!is_wp_error($oldRun)&&$oldRun['version']===Rules::LEGACY_VERSION,'previously created v2 evaluation can still be joined without changing rules');
$oldText='x'.Rules::chars($oldRun['samples'][0]['content'])[1];$oldRun=Service::update(array('token'=>$oldRun['token'],'revision'=>0,'requestId'=>'old-evaluation-input','events'=>array(array('start'=>0,'delete'=>0,'text'=>$oldText,'kind'=>'type','at'=>0))));
check($oldRun['metrics']['validCharacters']===0&&Service::update(array('token'=>$oldRun['token']),true)['version']===Rules::LEGACY_VERSION,'active legacy evaluation resumes and verifies under the frozen rule');
$GLOBALS['test_user_id']=1;$GLOBALS['caps']=array('edu_games_manage');$oldReport=Service::results($oldId);
check($oldReport['summary']['running']===1&&$oldReport['rows'][2]['version']===Rules::LEGACY_VERSION,'teacher results retain visibility of legacy evaluation attempts');
$filtered=Service::results($id,2,20,false,null,array('group'=>'2반'));
check($filtered['pagination']['total']===30&&count($filtered['rows'])===10&&$filtered['rows'][0]['userId']===57,'group filtering covers the full roster before pagination');
$filtered=Service::results($id,1,20,false,null,array('search'=>'66'));
check($filtered['pagination']['total']===1&&$filtered['rows'][0]['userId']===66,'search finds a student outside the original first page');
check(Service::results($id,1,20,false,null,array('status'=>'absent'))['pagination']['total']===59,'attendance filter includes every nonparticipant');
$filtered=Service::results($id,1,20,false,null,array('sort'=>'cpm','direction'=>'desc'));
check($filtered['rows'][0]['userId']===7&&$filtered['comparison']['finalCount']===1,'CPM sort and comparison use the entire assessment');
check(abs($filtered['comparison']['averageCpm']-$filtered['rows'][0]['effectiveCpm'])<.0001,'cohort CPM average uses finalized records only');
check(is_wp_error(Service::results($id,1,20,false,null,array('sort'=>'unsafe')))&&is_wp_error(Service::results($id,1,20,false,null,array('search'=>array()))),'result filter types and sort keys are validated');
check(count(Service::results($id,1,20,true,null,array('search'=>'66'))['rows'])===60,'full CSV export never silently excludes filtered-out participants');
check(Service::results($oldId)['comparison']['averageCpm']===null,'running-only work is excluded from finalized averages');
$multi=$config;$multi['maxAttempts']=2;$createdMulti=Service::create_evaluation($multi);$multiId=$createdMulti['evaluation']['id'];Service::set_status($multiId,'open');
$GLOBALS['test_user_id']=7;$GLOBALS['caps']=array('edu_games_play');$firstAttempt=Service::start(array('evaluationId'=>$multiId));
$firstAttempt=Service::update(array('token'=>$firstAttempt['token'],'revision'=>0,'requestId'=>'multi-typing-first','events'=>array(array('start'=>0,'delete'=>0,'text'=>Rules::chars($firstAttempt['samples'][0]['content'])[0],'kind'=>'type','at'=>0))));
$row=Repository::run($firstAttempt['token'],7);$state=Repository::state($row);$state['deadline']=microtime(true)-3;$wpdb->db->prepare('UPDATE wp_edu_games_typing_runs SET state_json=? WHERE id=?')->execute(array(wp_json_encode($state),$row['id']));Service::update(array('token'=>$firstAttempt['token']),true);
$secondAttempt=Service::start(array('evaluationId'=>$multiId));$GLOBALS['test_user_id']=1;$GLOBALS['caps']=array('edu_games_manage');$multiReport=Service::results($multiId);$student=$multiReport['rows'][0];
check($student['status']==='running'&&$student['resultStatus']==='finished'&&$student['activeAttemptNo']===2&&$student['attemptNo']===1,'a retake shows live attendance without replacing the finalized best record');
check($multiReport['summary']['running']===1&&$multiReport['summary']['completed']===0&&$multiReport['comparison']['finalCount']===1,'live attendance and finalized comparison counts remain distinct and accurate');
$GLOBALS['test_user_id']=7;$GLOBALS['caps']=array('edu_games_play');$assigned=Service::assigned()['evaluations'][0];
check($assigned['id']===$multiId&&$assigned['participation']['status']==='running'&&$assigned['result']['id']===$student['id']&&$assigned['result']['effectiveCpm']===$student['effectiveCpm'],'student and teacher share the same finalized representative during a retake');
check($assigned['participation']['attemptsRemaining']===0&&$assigned['participation']['activeAttemptNo']===2,'remaining starts are distinct from an active resumable attempt');
$target=Rules::chars($secondAttempt['samples'][0]['content']);$wrong=$target[0]==='x'?'z':'x';
Service::update(array('token'=>$secondAttempt['token'],'revision'=>0,'requestId'=>'multi-student-second','events'=>array(array('start'=>0,'delete'=>0,'text'=>$wrong.implode('',array_slice($target,1,3)),'kind'=>'type','at'=>0))));
$row=Repository::run($secondAttempt['token'],7);$state=Repository::state($row);$state['deadline']=microtime(true)-3;$wpdb->db->prepare('UPDATE wp_edu_games_typing_runs SET state_json=? WHERE id=?')->execute(array(wp_json_encode($state),$row['id']));Service::update(array('token'=>$secondAttempt['token']),true);
$assigned=Service::assigned()['evaluations'][0];
check($assigned['participation']['status']==='finished'&&$assigned['result']['attemptNo']===1&&count($assigned['attempts'])===2,'a faster unqualified retake preserves the qualified representative and both historical attempts');
$GLOBALS['test_user_id']=70;check(Service::assigned()['evaluations']===array(),'outsider cannot discover another student result through self-service summary');
echo "$checks verified typing domain/API/storage checks passed\n";
