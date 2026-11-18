<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require __DIR__.'/binary-team-support.php';
use EduGames\Arcade\{Vault_Rules as V,Dance_Rules as D};
use EduGames\Multiplayer\{Vault_Room as T,Room_Rules,Service,Failure};
$checks=0;
function ac_check(bool $value,string $label): void {global $checks;$checks++;if(!$value)throw new RuntimeException($label);}
function ac_fail(callable $fn,string $label): void {try{$fn();}catch(InvalidArgumentException|Failure $e){ac_check(true,$label);return;}throw new RuntimeException($label);}
function ac_actor(int $id,bool $manage=false): array {return ['id'=>$id,'name'=>'학생 '.$id,'manage'=>$manage,'admin'=>false];}
function ac_copy(array $s): array {return json_decode(json_encode($s,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);}
$all=edu_games_registry()->all(false);$catalog=edu_games_registry()->catalog(false);
ac_check(isset($catalog['cipher-vault'],$catalog['dance-bot'])&&count($catalog)===8,'eight playable game families');
foreach(EduGames\Games\Experience_Separation::MOVED as $id){ac_check(isset($all[$id])&&!isset($catalog[$id]),'historical module retained, catalog excludes '.$id);ac_check(!EduGames\Games\Game_Visibility::is_open($id),'retired scored play denied '.$id);}
ac_check(isset($all['character-code'])&&!isset($catalog['character-code']),'old character practice retained outside new catalog');
$s=V::create('test','fixed-seed','standard',1000);ac_check(count($s['vaults'])===24&&count(array_unique(array_column($s['vaults'],'text')))===24,'24 distinct vault tasks');
$s=V::apply($s,['type'=>'open','vault'=>0],1000);$text=$s['vaults'][0]['text'];$a=V::apply(ac_copy($s),['type'=>'answer','answer'=>$text],11000);$b=V::apply(ac_copy($s),['type'=>'answer','answer'=>$text],41000);
ac_check($a['score']===167&&$b['score']===133,'time differentiates exact same solution');
$wrong=V::apply(ac_copy($s),['type'=>'answer','answer'=>'WRONG'],2000);ac_check($wrong['wrong']===1&&$wrong['penaltyMs']===2000&&$wrong['score']===0,'wrong does not award points');
$closed=V::apply($wrong,['type'=>'close'],5000);$closed=V::apply($closed,['type'=>'open','vault'=>0],9000);ac_check($closed['vaults'][0]['startedAt']===1000&&V::elapsed($closed,11000)===12000,'reopening cannot reset time or wrong correction');
$view=V::view($closed,11000);ac_check(!isset($view['vaults'][0]['text'])&&!in_array($text,array_column($view['problem']['packets'],'value'),true),'decode answer is not published');
$run=V::create('test','finish-seed','standard',1000);$now=1000;
foreach($run['vaults'] as $id=>$v){$run=V::apply($run,['type'=>'open','vault'=>$id],$now);$now+=1000;if($v['mode']==='rebuild'){$run=V::apply($run,['type'=>'repair','code'=>V::codes($v)[$v['repairIndex']]],$now);$now+=500;} $run=V::apply($run,['type'=>'answer','answer'=>$v['mode']==='encode'?implode(' ',V::codes($v)):$v['text']],$now);$now+=500;}
ac_check($run['status']==='finished'&&$run['correct']===24&&$run['score']>3000,'all vault types can complete');
ac_fail(fn()=>V::apply($run,['type'=>'answer','answer'=>'anything'],99999),'completed vault cannot be scored twice');
ac_check(V::apply(V::create('test','stop','standard',1000),['type'=>'stop'],2000)['status']==='stopped','partial run has nonranked status');
$s=D::create('dance','seed','standard',1000);$target=$s['targets'][0];$program=D::routines('seed','standard')[0]['program'];$expanded=D::expand($program);ac_check($expanded['steps']===$target&&$expanded['cost']<count($target),'mixed sequence and loops expand exact actual steps');
ac_fail(fn()=>D::expand([['commands'=>['jump'],'repeat'=>999999]]),'unbounded repeat rejected');
$s=D::apply($s,['type'=>'run','program'=>$program],2000);ac_fail(fn()=>D::apply($s,['type'=>'commit'],2001),'cannot submit before animation execution time');$s=D::apply($s,['type'=>'commit'],$s['run']['until']);
ac_check($s['correct']===1&&$s['lastResult']['saved']>0&&$s['score']===$s['lastResult']['points'],'server scores actual successful program');
ac_check($s['awaitingNext']&&D::view($s,9000)['target']===$target,'completed result stays visible without next task disclosure');
ac_fail(fn()=>D::apply($s,['type'=>'commit'],99999),'duplicate commit not rewarded');$s=D::apply($s,['type'=>'next'],100000);ac_check(!$s['awaitingNext']&&$s['roundStartedAt']===100000,'next round clock begins at next reveal');
$s=D::apply($s,['type'=>'run','program'=>[['commands'=>['left'],'repeat'=>1]]],100100);$s=D::apply($s,['type'=>'commit'],100400);ac_check($s['wrong']===1&&$s['lastResult']['points']===0,'wrong dance receives zero points');
$host=ac_actor(100,true);$s=T::create('ABC234',$host,['capacity'=>6],1000,'team-seed');for($i=1;$i<=4;$i++)$s=T::command($s,ac_actor($i),['type'=>'join'],1000);for($i=1;$i<=4;$i++)$s=T::command($s,ac_actor($i),['type'=>'ready','ready'=>true],1000);$s=T::command($s,$host,['type'=>'start'],1000);$now=$s['startedAt']+1;
ac_check(count($s['boards'][1]['vaults'])===24&&$s['boards'][1]['vaults']===$s['boards'][2]['vaults'],'teams receive fair task set');
$s=T::command($s,ac_actor(1),['type'=>'open','vault'=>0],$now);$s=T::command($s,ac_actor(3),['type'=>'open','vault'=>1],$now);
ac_fail(fn()=>T::command($s,ac_actor(3),['type'=>'open','vault'=>0],$now+1),'peer cannot steal lease');
$view=T::view($s,ac_actor(2),$now,1);ac_check($view['board']['team']===2&&!isset($view['board']['vaults'][0]['text'])&&$view['board']['problem']===null,'student cannot observe enemy answers');
ac_fail(fn()=>T::command($s,ac_actor(2),['type'=>'item','item'=>'shuffle','targetTeam'=>1,'targetVault'=>0],$now+1),'active input protected from attack');
$s=T::command($s,ac_actor(2),['type'=>'item','item'=>'shuffle','targetTeam'=>1,'targetVault'=>2],$now+1);$s=T::command($s,ac_actor(3),['type'=>'open','vault'=>2],$now+2);$view=T::view($s,ac_actor(3),$now+2);
ac_check($view['board']['problem']['shuffled']&&!$view['board']['problem']['masked'],'shuffle and erased code are separate effects');
ac_fail(fn()=>T::command($s,ac_actor(3),['type'=>'repair','vault'=>2,'code'=>V::codes($s['boards'][1]['vaults'][2])[1]],$now+3),'cannot bypass order restoration with code repair');
while($s['boards'][1]['vaults'][2]['effect']){$order=$s['boards'][1]['vaults'][2]['order'];foreach($order as $i=>$p)if($i!==$p){$s=T::command($s,ac_actor(3),['type'=>'swap','vault'=>2,'first'=>$i,'second'=>array_search($i,$order,true)],++$now);break;}}
$text=$s['boards'][1]['vaults'][2]['text'];$s=T::command($s,ac_actor(3),['type'=>'answer','vault'=>2,'answer'=>$text],++$now);ac_check($s['boards'][1]['score']===100&&$s['players']['3']['correct']===1,'team awards base score and contribution');
$s=T::command($s,ac_actor(2),['type'=>'item','item'=>'erase','targetTeam'=>1,'targetVault'=>5],++$now);$s=T::command($s,ac_actor(1),['type'=>'open','vault'=>5],++$now);$v=$s['boards'][1]['vaults'][5];$s=T::command($s,ac_actor(1),['type'=>'repair','vault'=>5,'code'=>V::codes($v)[$v['repairIndex']]],++$now);ac_check(!$s['boards'][1]['vaults'][5]['effect'],'erased code recoverable from actual table');
$s['boards'][1]['coins']=5;$s=T::command($s,ac_actor(1),['type'=>'buy','item'=>'key'],++$now);$s=T::command($s,ac_actor(1),['type'=>'item','item'=>'key'],++$now);ac_check($s['boards'][1]['score']===150&&$s['boards'][1]['correct']===1&&$s['boards'][1]['stageCorrect']===2,'key earns half without direct-solve credit');
$s=T::command($s,$host,['type'=>'pause'],++$now);$remaining=$s['endsAt']-$now;$s=T::command($s,$host,['type'=>'resume'],$now+5000);ac_check($s['endsAt']-($now+5000)===$remaining,'pause shifts all clocks');$s=T::tick($s,$s['endsAt']+1);ac_check($s['summary']['game']==='cipher-vault'&&$s['summary']['ruleset']==='vault-team-v1','team result separately versioned');
$db=new EduGames\Tests\Binary_Memory_Repository();$service=new Service($db,Room_Rules::VAULT);$actor=ac_actor(9);$v=$service->create($actor,['op'=>'vault-practice-create','mode'=>'practice','participate'=>true],1000);$code=$v['code'];$v=$service->access($code,$actor,['type'=>'practice-start','round'=>1,'op'=>'vault-bot-start'],2000);$at=$v['startedAt'];for($i=1;$i<=60;$i++)$service->access($code,$actor,null,$at+$i*1000);$b=$db->rooms[$code]['boards'];ac_check($b[1]['correct']+$b[2]['correct']>0,'solo practice bots use vault commands');ac_check($b[1]['correct']+$b[2]['correct']<20,'practice bots bounded and slower than perfect solver');
echo $checks." arcade rules and vault team checks passed\n";
