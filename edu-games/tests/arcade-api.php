<?php
declare(strict_types=1);
define('ARCADE_TEST_LIBRARY',true);require __DIR__.'/arcade-fixture.php';
use EduGames\Arcade\{Repository as R,Vault_Rules as V,Dance_Rules as D};
use EduGames\Rest\Controllers\Arcade_Controller as C;
$GLOBALS['wpdb']=$db=arcade_test_database(':memory:');$checks=0;
function api_check(bool $v,string $m):void{global $checks;$checks++;if(!$v)throw new RuntimeException($m);}
function api_call(string $action,array $body=[],string $game='cipher-vault'):mixed{return C::run($game,$action,new Arcade_Test_Request($body));}
function api_move(array $state,array $move,string $op):mixed{return api_call('command',['token'=>$state['token'],'revision'=>$state['revision'],'requestId'=>$op,'move'=>$move]);}
$start=api_call('start')->data;api_check(strlen($start['token'])===48&&$start['revision']===0,'persisted private run');
$open=api_move($start,['type'=>'open','vault'=>0],'request-open-001')->data;
$row=R::find('cipher-vault',7,$start['token']);$private=json_decode($row['state_json'],true);$answer=$private['vaults'][0]['text'];
api_check(!str_contains(json_encode($open),$answer),'answer redacted in public view');
$submit=api_move($open,['type'=>'answer','answer'=>$answer],'request-answer-001')->data;api_check($submit['correct']===1&&$submit['score']>0,'server computes and saves score');
$duplicate=api_move($open,['type'=>'answer','answer'=>$answer],'request-answer-001')->data;api_check($duplicate['revision']===$submit['revision']&&$duplicate['score']===$submit['score'],'lost response retry cannot duplicate reward');
$stale=api_move($open,['type'=>'open','vault'=>1],'request-stale-001');api_check($stale instanceof WP_Error&&$stale->data['status']===409,'stale revision rejected');
$GLOBALS['test_user_id']=8;$other=api_call('resume',['token'=>$start['token']]);api_check($other instanceof WP_Error&&$other->data['status']===404,'another student cannot resume private run');$GLOBALS['test_user_id']=7;
$db->fail=true;$failed=api_move($submit,['type'=>'open','vault'=>1],'request-failure-001');$db->fail=false;api_check($failed instanceof WP_Error&&$failed->data['status']===503,'write failure reported, no fake success');
$again=api_move($submit,['type'=>'open','vault'=>1],'request-failure-001')->data;api_check($again['revision']===$submit['revision']+1,'same request safe after failed write');
$stop=api_move($again,['type'=>'stop'],'request-stop-001')->data;api_check($stop['status']==='stopped','partial progress retained');api_check(api_call('ranking')->data['pagination']['total']===0,'partial run excluded from finish ranking');
$now=(int)round(microtime(true)*1000);$completed=V::create('x','complete','standard',$now-60000);$at=$now-59000;
foreach($completed['vaults'] as $id=>$v){$completed=V::apply($completed,['type'=>'open','vault'=>$id],$at);if($v['mode']==='rebuild')$completed=V::apply($completed,['type'=>'repair','code'=>V::codes($v)[$v['repairIndex']]],$at+100);$completed=V::apply($completed,['type'=>'answer','answer'=>$v['mode']==='encode'?implode(' ',V::codes($v)):$v['text']],$at+1000);$at+=1500;}
for($user=1;$user<=31;$user++){$row=R::create('cipher-vault',$user,'standard',$now);$s=$completed;$s['score']=4000+$user;R::save($row,$s,$now);}
$rank=api_call('ranking',['page'=>2,'perPage'=>20])->data;api_check($rank['pagination']['total']===31&&count($rank['leaderboard'])===11,'all participants paginated');api_check($rank['leaderboard'][0]['rank']===21,'global rank preserved across pages');
$own=array_values(array_filter(api_call('ranking',['perPage'=>100])->data['leaderboard'],fn($r)=>$r['userId']===7))[0];api_check($own['rank']===25,'personal rank canonical');
$row=R::create('cipher-vault',7,'standard',$now);$s=$completed;$s['score']=9999;R::save($row,$s,$now);$rank=api_call('ranking')->data;api_check($rank['pagination']['total']===31&&$rank['overview']['personal']['score']===9999,'best attempt only in ranking, all attempts retained');
$id=(int)$row['id'];$denied=C::remove('cipher-vault',new Arcade_Test_Request([],'DELETE',$id));api_check($denied instanceof WP_Error&&$denied->data['status']===403,'student cannot delete score');
$GLOBALS['caps']=['edu_games_play','manage_options'];$wrongGame=C::remove('dance-bot',new Arcade_Test_Request([],'DELETE',$id));api_check($wrongGame instanceof WP_Error&&$wrongGame->data['status']===404,'delete binds game and id');
$delete=C::remove('cipher-vault',new Arcade_Test_Request([],'DELETE',$id));api_check($delete->data['deleted']===true,'administrator deletes finished score');api_check(api_call('ranking')->data['overview']['personal']['score']===4007,'older best reappears after deletion');
$running=R::create('dance-bot',7,'standard',$now);$denied=C::remove('dance-bot',new Arcade_Test_Request([],'DELETE',(int)$running['id']));api_check($denied instanceof WP_Error&&$denied->data['status']===404,'unfinished run cannot be deleted as a score');
$report=EduGames\Reports\Report::read([7,8,99],'cipher-vault','verified-arcade','','',1,20);api_check(is_array($report)&&$report['students'][0]['best']===4007.0&&$report['students'][2]['attempts']===0,'teacher compares verified completion and nonparticipants');
$db->query('CREATE TABLE wp_edu_games_scores (id INTEGER,user_id INTEGER,game_slug TEXT,score INTEGER,rounds_played INTEGER,correct_count INTEGER,duration_seconds INTEGER,accuracy REAL,meta TEXT,created_at TEXT)');
$db->query("INSERT INTO wp_edu_games_scores VALUES (1,7,'outlier-detection',120,3,2,30,66.67,'{}','2020-01-01 00:00:00')");
$stats=EduGames\Statistics\Player_Statistics_Service::for_user(7);api_check(count($stats['games'])===2&&$stats['summary']['attempts']===2,'statistics includes new finished game and preserved historical activity');
$row=R::create('dance-bot',7,'standard',$now);$s=D::create($row['pack'],'mixed','standard',$now-10000);$s['status']='finished';$s['score']=900;$s['correct']=8;$s['finishedAt']=$now;R::save($row,$s,$now);
$old=R::create('dance-bot',7,'standard',$now);$s['ruleset']='dance-v1';$s['score']=500;$s['pack']='dance-v1-standard-2020-01-01';$old=R::save($old,$s,$now);$db->query($db->prepare('UPDATE '.R::table().' SET pack=%s WHERE id=%d',$s['pack'],(int)$old['id']));
$current=EduGames\Reports\Report::read([7],'dance-bot','verified-arcade','','',1,20);$legacy=EduGames\Reports\Report::read([7],'dance-bot','verified-arcade-v1','','',1,20);
api_check($current['students'][0]['attempts']===2&&$current['students'][0]['completed']===1&&$current['students'][0]['best']===900.0,'new dance report excludes old scoring pack');
api_check($legacy['students'][0]['attempts']===1&&$legacy['students'][0]['best']===500.0,'old dance report remains available');
api_check(api_call('ranking',[],'dance-bot')->data['leaderboard'][0]['score']===900,'daily mixed dance ranking has no legacy score');
api_check(api_call('records',[],'dance-bot')->data['pagination']['total']===3,'new and old dance attempts stay in personal history');
$GLOBALS['options']['edu_games_closed_games']=['cipher-vault'];api_check(!C::allowed('cipher-vault'),'closed game denies new endpoints');
foreach(EduGames\Games\Experience_Separation::MOVED as $id)api_check(!EduGames\Games\Game_Visibility::is_open($id),'moved scoring disabled '.$id);
echo "$checks arcade API, persistence, ranking and report checks passed\n";
