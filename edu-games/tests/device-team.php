<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require __DIR__.'/binary-team-support.php';
use EduGames\Multiplayer\{Device_Room as Room,Room_Rules,Service,Ticket,Socket_Relay,Failure};
use EduGames\Tests\Binary_Memory_Repository;
$checks=0;
function dt_check(bool $ok,string $m):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($m);}
function dt_actor(int $id,bool $manage=false):array{return array('id'=>$id,'name'=>'학생 '.$id,'manage'=>$manage,'admin'=>false);}
function dt_fail(callable $fn,int $status):void{try{$fn();}catch(Failure $e){dt_check($e->status===$status,$e->getMessage());return;}throw new RuntimeException('Expected rejection');}
function dt_started(int $size=2):array{
    $s=Room::create('ABC234',dt_actor(100,true),array('capacity'=>6),1700000000000,'device-seed');
    for($id=1;$id<=$size*2;$id++)$s=Room::command($s,dt_actor($id),array('type'=>'join'),1700000000000);
    for($id=1;$id<=$size*2;$id++)$s=Room::command($s,dt_actor($id),array('type'=>'ready','ready'=>true),1700000000000);
    return Room::command($s,dt_actor(100,true),array('type'=>'start'),1700000000000);
}
$catalog=Room::catalog();dt_check(count($catalog)>=64,'at least two nonrepeating maximum decks');
foreach($catalog as $d){$image=EDU_GAMES_PATH.'assets/device-matching/images/'.basename(parse_url($d['image'],PHP_URL_PATH));dt_check(is_file($image)&&filesize($image)>1000&&strlen($d['description'])>=30&&$d['sourceUrl']&&$d['license'],'local photo, explanation and attribution '.$d['id']);}
foreach(array(2=>24,3=>32,4=>40,5=>48,6=>56) as $size=>$cards){
    $s=dt_started($size);dt_check(count($s['boards'][1]['cards'])===$cards,'roster-sized board');
    dt_check($s['boards'][1]['cards']===$s['boards'][2]['cards'],'fair device set and layout');
    $v=Room::view($s,dt_actor(1),$s['startedAt']+1,2);
    dt_check($v['board']['team']===1&&!isset($v['board']['cards'][0]['face'])&&!isset($v['board']['cards'][0]['device'])&&!isset($v['deviceUsage']),'private cards and opponent view');
    dt_check(Room::view($s,dt_actor(100,true)+array('scope'=>'control','room'=>$s['code']),$s['startedAt']+1,2)['board']['team']===2,'teacher console can observe');
}
$s=dt_started();$now=$s['startedAt']+1;$cards=$s['boards'][1]['cards'];$first=$cards[0];$other=current(array_filter($cards,fn($c)=>$c['device']!==$first['device']));
$flip=static fn(array $state,int $uid,array $card,int $at)=>Room::command($state,dt_actor($uid),array('type'=>'flip','card'=>$card['id'],'stage'=>$state['boards'][1]['stage']),$at);
$s=$flip($s,1,$first,$now);$s=$flip($s,3,$other,$now+1);
dt_check(count($s['boards'][1]['turns'])===2,'teammates operate in parallel');
dt_fail(fn()=>$flip($s,3,$first,$now+2),409);
$miss=current(array_filter($cards,fn($c)=>$c['device']!==$first['device']&&$c['id']!==$other['id']));
$s=$flip($s,1,$miss,$now+2);dt_check($s['boards'][1]['wrong']===1&&$s['players']['1']['wrong']===1,'wrong pair recorded');
$s=Room::tick($s,$now+Room::REVEAL_MS+3);dt_check(!isset($s['boards'][1]['turns']['1'])&&$s['boards'][1]['cards'][0]['state']==='down','wrong pair closes on server');
$v=Room::view($s,dt_actor(1),$now+Room::REVEAL_MS+3);dt_check(!isset($v['board']['cards'][0]['face']),'closed face removed from payload');
$s=Room::tick($s,$now+Room::CLAIM_MS+3);dt_check(!$s['boards'][1]['turns'],'abandoned selections expire');
dt_fail(fn()=>Room::command($s,dt_actor(1),array('type'=>'flip','card'=>$first['id'],'stage'=>0),$now+13000),409);
$s=dt_started(6);$now=$s['startedAt']+10;
for($stage=1;$stage<=4;$stage++){
    $before=array_unique(array_column($s['boards'][1]['cards'],'device'));$pairs=array();foreach($s['boards'][1]['cards'] as $card)$pairs[$card['device']][]=$card;
    foreach($pairs as $pair){foreach($pair as $card){$s=$flip($s,1,$card,$now);$now+=100;}}
    dt_check($s['boards'][1]['clearAt']>0&&$s['boards'][1]['stageCorrect']===count($pairs),'cleared all pairs');
    $now=$s['boards'][1]['clearAt']+1;$s=Room::tick($s,$now);
    $after=array_unique(array_column($s['boards'][1]['cards'],'device'));
    dt_check(!array_intersect($before,$after),'no repeats from immediately previous stage');
    dt_check(count($s['boards'][1]['cards'])<=64,'bounded team board');
}
$s=Room::command($s,dt_actor(100,true),array('type'=>'pause'),$now);$remaining=$s['endsAt']-$now;
$s=Room::command($s,dt_actor(100,true),array('type'=>'resume'),$now+5000);dt_check($s['endsAt']-($now+5000)===$remaining,'pause excluded from duration');
$s=Room::tick($s,$s['endsAt']+1);dt_check($s['status']==='finished'&&$s['summary']['game']==='device-matching','finite timed game with separate result');
$usage=$s['deviceUsage'];$s=Room::command($s,dt_actor(100,true),array('type'=>'rematch'),$now+6000);dt_check($s['deviceUsage']===$usage&&$s['decks']===array(),'repeat prevention survives rematch');
$db=new Binary_Memory_Repository();$svc=new Service($db,Room_Rules::DEVICE);$host=dt_actor(100,true);
$v=$svc->create($host,array('op'=>'device-create-op','title'=>'장치 팀전'),1700000000000);$code=$v['code'];
dt_fail(fn()=>(new Service($db))->access($code,$host,null,1700000000001),404);
dt_check(!(new Service($db))->rooms($host,1700000000001)['items'],'game-specific room lists');
$db->rooms[$code]=dt_started();$db->rooms[$code]['code']=$code;
$row=$db->rooms[$code]['boards'][1]['cards'][0];$at=$db->rooms[$code]['startedAt']+1;$command=array('type'=>'flip','card'=>$row['id'],'stage'=>1,'round'=>1,'op'=>'same-device-flip');
$svc->access($code,dt_actor(1),$command,$at);$edits=$db->rooms[$code]['players']['1']['edits'];$svc->access($code,dt_actor(1),$command,$at+1);
dt_check($db->rooms[$code]['players']['1']['edits']===$edits,'retry never flips twice');
$secret=str_repeat('device-secret',4);$ticket=Ticket::issue(dt_actor(1),$secret,(int)floor($at/1000),Room_Rules::DEVICE);
dt_fail(fn()=>Ticket::read($ticket,$secret,(int)floor($at/1000)),401);
$batch=Socket_Relay::handle($db,array('app'=>'edu-games','channel'=>'device-team','code'=>$code,'sessions'=>array(str_repeat('a',24)=>array('ticket'=>$ticket,'watch'=>2))),$secret,$at+2);
dt_check($batch['views'][str_repeat('a',24)]['data']['board']['team']===1,'WebSocket card channel enforces private team board');
$svc->access($code,$host,array('type'=>'stop','round'=>1,'op'=>'stop-device-match'),$at+10);
dt_check(count($svc->records(dt_actor(1),1)['items'])===1&&!(new Service($db))->records(dt_actor(1),1)['items'],'separate paginated device records');
echo "PASS: {$checks} device team size, fairness, collaboration, hidden cards, recovery and repetition checks.\n";
