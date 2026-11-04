<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/binary-team-support.php';
use EduGames\Multiplayer\{Binary_Room as Room,Service,Failure,Wpdb_Repository};
use EduGames\Tests\Binary_Memory_Repository;

$checks=0;
function bt_check(bool $ok,string $message): void {global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function bt_fails(callable $fn,int $status,string $message): void {try{$fn();}catch(Failure $e){bt_check($e->status===$status,$message.' / '.$e->getMessage());return;}throw new RuntimeException('Expected failure: '.$message);}
function bt_actor(int $id,bool $manage=false): array{return array('id'=>$id,'name'=>'학생 '.$id,'manage'=>$manage,'admin'=>false);}
function bt_started(int $size=2,string $difficulty='standard'): array{
    $s=Room::create('ABC234',bt_actor(100,true),array('teams'=>2,'capacity'=>6,'difficulty'=>$difficulty),1000000,'fixed-seed');
    for($id=1;$id<=2*$size;$id++)$s=Room::command($s,bt_actor($id),array('type'=>'join'),1000000);
    for($id=1;$id<=2*$size;$id++)$s=Room::command($s,bt_actor($id),array('type'=>'ready','ready'=>true),1000000);
    return Room::command($s,bt_actor(100,true),array('type'=>'start'),1000000);
}
$old=PHP_INT_MAX;
for($size=2;$size<=6;$size++){
    $s=bt_started($size);$d=Room::difficulty($size,1);bt_check($d['spawnMs']<$old,'more members means faster initial spawn');$old=$d['spawnMs'];
    bt_check($d['spawnMs']<6200&&$d['inverseChance']>0.18,'team harder than personal opening');
    bt_check($s['boards'][1]['size']===$size,'roster freezes team difficulty');
    bt_check($s['boards'][1]['rows']===$s['boards'][2]['rows'],'identical fair puzzles');
    for($level=1;$level<=15;$level++)foreach(array('standard','challenge') as $kind){
        $p=Room::difficulty($size,$level,$kind);bt_check($p['spawnMs']>=1000&&$p['minimumExposureMs']===7000&&$p['bits']===8,'bounded difficulty');
    }
    $end=Room::tick($s,2000000);
    bt_check($end['status']==='finished','games end even without commands');
    bt_check($end['boards'][1]['endedAt']-$s['startedAt']>=7000,'opening cannot overflow instantly');
    bt_check(count($end['boards'][1]['rows'])===8,'eight real rows only');
}
$s=bt_started();$now=$s['startedAt']+100;$uid=1;$actor=bt_actor($uid);$team=$s['players']['1']['team'];$row=$s['boards'][$team]['rows'][0];
bt_fails(fn()=>Room::command($s,$actor,array('type'=>'start'),$now),403,'non-host cannot start');
bt_fails(fn()=>Room::command($s,bt_actor(9),array('type'=>'join'),$now),409,'late join denied');
bt_fails(fn()=>Room::command($s,$actor,array('type'=>'team','team'=>2),$now),403,'official match team changes require teacher');
bt_fails(fn()=>Room::command($s,$actor,array('type'=>'claim','row'=>$row['id'],'round'=>0),$now),409,'old round denied');
$s=Room::command($s,$actor,array('type'=>'claim','row'=>$row['id']),$now);
$peer=bt_actor(3);
bt_fails(fn()=>Room::command($s,$peer,array('type'=>'claim','row'=>$row['id']),$now+1),409,'live lease prevents collision');
$second=$s['boards'][$team]['rows'][1];$s=Room::command($s,$peer,array('type'=>'claim','row'=>$second['id']),$now+1);
bt_check(count(array_filter($s['boards'][$team]['rows'],fn($r)=>$r['owner']>0))===2,'different rows operate concurrently');
$s=Room::tick($s,$now+Room::LEASE_MS+1);
bt_check($s['boards'][$team]['rows'][0]['owner']===0,'disconnect releases leases');
$view=Room::view($s,bt_actor(1),$now+Room::LEASE_MS+1,2);
bt_check($view['board']['team']===1&&!isset($view['board']['rows'][0]['decimal'])&&!isset($view['seed'])&&!isset($view['boards']),'opponent board and answers not leaked');
bt_check(Room::view($s,bt_actor(100,true)+array('scope'=>'control','room'=>$s['code']),$now,2)['board']['team']===2,'teacher console can watch another team');
bt_fails(fn()=>Room::view($s,bt_actor(44),$now),403,'unjoined observer denied');
$s=bt_started();$now=$s['startedAt']+10;$rows=$s['boards'][1]['rows'];$row=$rows[0];
if($row['mode']==='bin-to-dec')$s=Room::command($s,$actor,array('type'=>'answer','row'=>$row['id'],'value'=>(string)$row['decimal']),$now);
else foreach(str_split(str_pad(decbin($row['decimal']),8,'0',STR_PAD_LEFT)) as $i=>$bit)if($bit==='1')$s=Room::command($s,$actor,array('type'=>'bit','row'=>$row['id'],'bit'=>$i,'value'=>1),$now);
bt_check($s['boards'][1]['correct']===1&&$s['players']['1']['correct']===1,'server score and contribution');
bt_check($s['boards'][2]['correct']===0,'other team unchanged');
$faster=bt_started();$slower=$faster;$sample=$faster['boards'][1]['rows'][0];
$solve=static function(array $state,int $at)use($sample,$actor):array{
    if($sample['mode']==='bin-to-dec')return Room::command($state,$actor,array('type'=>'answer','row'=>$sample['id'],'value'=>(string)$sample['decimal']),$at);
    foreach(str_split(str_pad(decbin($sample['decimal']),8,'0',STR_PAD_LEFT))as$i=>$bit)if($bit==='1')$state=Room::command($state,$actor,array('type'=>'bit','row'=>$sample['id'],'bit'=>$i,'value'=>1),$at);
    return $state;
};
$faster=$solve($faster,$faster['startedAt']+100);$slower=$solve($slower,$slower['startedAt']+3000);
bt_check($faster['boards'][1]['score']>$slower['boards'][1]['score'],'perfect answers still compete on solution speed');
$s=Room::command($s,bt_actor(100,true),array('type'=>'pause'),$now);$before=$s;
$s=Room::tick($s,$now+100000);bt_check($s===$before,'pause freezes all game time');
$s=Room::command($s,bt_actor(100,true),array('type'=>'resume'),$now+100000);
bt_check($s['startedAt']===$before['startedAt']+100000&&$s['endsAt']===$before['endsAt']+100000,'resume shifts full deadlines');
$s=Room::command($s,bt_actor(100,true),array('type'=>'stop'),$now+100100);
bt_check($s['summary']['durationMs']===110,'pause excluded from result duration');
$s=Room::command($s,bt_actor(100,true),array('type'=>'rematch'),$now+100200);
bt_check($s['round']===2&&!$s['players']['1']['ready']&&$s['boards']===array(),'rematch resets boards and readiness');
$bad=Room::create('BAD234',bt_actor(100),array(),1000000,'seed');
$bad=Room::command($bad,bt_actor(1),array('type'=>'join'),1000000);
bt_fails(fn()=>Room::command($bad,bt_actor(100),array('type'=>'start'),1000000),409,'cannot start unbalanced/unready teams');
bt_fails(fn()=>Room::create('BAD234',bt_actor(100),array('capacity'=>7),1000000,'seed'),422,'maximum capacity enforced');
$db=new Binary_Memory_Repository();$svc=new Service($db);$host=bt_actor(100,true);
$config=array('op'=>'create-operation-1','title'=>'실시간 검사');$v=$svc->create($host,$config,1000000);$code=$v['code'];
bt_check($svc->create($host,$config,1000001)['code']===$code&&count($db->rooms)===1,'create retry is idempotent');
for($id=1;$id<=4;$id++)$svc->access($code,bt_actor($id),array('type'=>'join','op'=>'join-user-'.$id),1000000);
for($id=1;$id<=4;$id++)$svc->access($code,bt_actor($id),array('type'=>'ready','ready'=>true,'op'=>'ready-user-'.$id,'round'=>1),1000000);
$svc->access($code,$host,array('type'=>'start','op'=>'start-operation','round'=>1),1000000);
$start=$db->rooms[$code]['startedAt'];$row=$db->rooms[$code]['boards'][1]['rows'][0];
$cmd=array('type'=>'claim','row'=>$row['id'],'op'=>'repeated-command','round'=>1);
$v=$svc->access($code,bt_actor(1),$cmd,$start+1);$rev=$v['revision'];
bt_check($svc->access($code,bt_actor(1),$cmd,$start+2)['revision']===$rev,'duplicate command does not replay');
$db->failPut=true;
bt_fails(fn()=>$svc->access($code,$host,array('type'=>'stop','op'=>'stop-operation','round'=>1),$start+100),503,'failed final state write is visible');
bt_check(count($db->results)===1,'result survives a final state write failure');$db->failPut=false;
$v=$svc->access($code,bt_actor(1),null,$start+200);
bt_check($v['status']==='finished'&&$v['summary']['durationMs']===100,'saved result freezes recovery at original time');
bt_check(count($svc->records(bt_actor(1),1)['items'])===1,'participant can read result');
bt_check(count($svc->records(bt_actor(9),1)['items'])===0,'outsider cannot list result');
bt_fails(fn()=>$svc->result($code.'-1',bt_actor(9)),403,'outsider cannot fetch result');
$db->rooms[$code]=bt_started();$db->rooms[$code]['code']=$code;$db->results=array();
$r=$svc->records(bt_actor(1),1,3000000);bt_check(count($r['items'])===1,'records settle games after everyone disconnects');
foreach(range(2,24) as $i){$r=$db->results[$code.'-1'];$r['id']=$code.'-'.$i;$r['finishedAt']+=$i;$db->save_result($r);}
$paged=$svc->records(bt_actor(1),2);bt_check($paged['pagination']['total']===24&&count($paged['items'])===4,'all match records are paginated');
// The real MySQL adapter must never execute without its advisory lock.
$GLOBALS['wpdb']=new class {public string $prefix='wp_';public int $answer=0,$released=0;public function prepare($s,...$a){return $s;}public function get_var($q){if(str_contains($q,'RELEASE_LOCK')){$this->released++;return 1;}return $this->answer;}};
$ran=false;$repo=new Wpdb_Repository();bt_fails(function()use($repo,&$ran){$repo->locked('room',function()use(&$ran){$ran=true;});},503,'failed advisory lock rejects mutation');
bt_check(!$ran,'lock failure cannot run critical section');$GLOBALS['wpdb']->answer=1;
try{$repo->locked('room',function(){throw new RuntimeException('test');});}catch(RuntimeException $e){}
bt_check($GLOBALS['wpdb']->released===1,'advisory lock released on errors');
$secret='binary-team-test-secret-0123456789';$ticket=\EduGames\Multiplayer\Ticket::issue(bt_actor(1),$secret,1000);
bt_check(\EduGames\Multiplayer\Ticket::read($ticket,$secret,1001)['id']===1,'lightweight ticket carries verified identity');
bt_fails(fn()=>\EduGames\Multiplayer\Ticket::read($ticket,$secret,1090),401,'expired ticket rejected');
bt_fails(fn()=>\EduGames\Multiplayer\Ticket::read($ticket,$secret.'other',1001),401,'other service secret rejected');
bt_fails(fn()=>\EduGames\Multiplayer\Ticket::read('x'.$ticket,$secret,1001),401,'ticket tampering rejected');
$claim=json_decode(base64_decode(strtr(explode('.',$ticket)[0],'-_','+/')),true);$claim['purpose']='oj-code-arena';
$body=rtrim(strtr(base64_encode(json_encode($claim)),'+/','-_'),'=');
bt_fails(fn()=>\EduGames\Multiplayer\Ticket::read($body.'.'.hash_hmac('sha256',$body,$secret),$secret,1001),401,'purpose separation even with a valid signature');
$socketStore=new Binary_Memory_Repository();$socketStore->rooms['ABC234']=bt_started();$at=$socketStore->rooms['ABC234']['startedAt']+1;
$sessions=array();foreach(array(1,3,2,9) as $i=>$uid){$sessions[str_repeat(chr(97+$i),24)]=array('ticket'=>\EduGames\Multiplayer\Ticket::issue(bt_actor($uid),$secret,(int)floor($at/1000)),'watch'=>2);}
$row=$socketStore->rooms['ABC234']['boards'][1]['rows'][0];$op=array('type'=>'claim','row'=>$row['id'],'round'=>1,'op'=>'cross-transport-operation');
$batch=array('app'=>'edu-games','channel'=>'binary-team','code'=>'ABC234','sessions'=>$sessions,'action'=>array('client'=>str_repeat('a',24),'command'=>$op));
$reply=\EduGames\Multiplayer\Socket_Relay::handle($socketStore,$batch,$secret,$at);
bt_check($reply['reply']['ok']&&$reply['views'][str_repeat('b',24)]['data']['board']['rows'][0]['owner']===1,'WebSocket broadcasts team-owned authoritative state');
bt_check($reply['views'][str_repeat('a',24)]['data']['board']['team']===1,'WebSocket cannot watch an opponent by changing watch');
bt_check($reply['views'][str_repeat('c',24)]['data']['board']['team']===2,'each socket gets a private board');
bt_check(($reply['views'][str_repeat('d',24)]['error']['status']??0)===403,'outsider socket cannot read room');
bt_check(!isset($reply['views'][str_repeat('a',24)]['data']['board']['rows'][0]['decimal']),'socket snapshots hide answers');
$revision=$socketStore->rooms['ABC234']['revision'];
(new Service($socketStore))->access('ABC234',bt_actor(1),$op,$at+1);
bt_check($socketStore->rooms['ABC234']['revision']===$revision,'HTTP retry after committed WebSocket command is deduplicated');
$batch['channel']='arena';bt_fails(fn()=>\EduGames\Multiplayer\Socket_Relay::handle($socketStore,$batch,$secret,$at),422,'other app channel rejected');
$raw='{"sync":true}';$stamp='1700000000';$signature=\EduGames\Multiplayer\Socket_Relay::signature('POST','/binary-team/sync',$stamp,$raw,$secret);
\EduGames\Multiplayer\Socket_Relay::verify($raw,$stamp,$signature,$secret,(int)$stamp);bt_check(true,'hub signature accepted');
bt_fails(fn()=>\EduGames\Multiplayer\Socket_Relay::verify($raw.' ',$stamp,$signature,$secret,(int)$stamp),401,'modified relay body denied');
bt_fails(fn()=>\EduGames\Multiplayer\Socket_Relay::verify($raw,$stamp,$signature,$secret,(int)$stamp+31),401,'stale hub signature denied');
bt_fails(fn()=>\EduGames\Multiplayer\Socket_Relay::verify($raw,$stamp,$signature,$secret.'other',(int)$stamp),401,'other app key denied');
echo "PASS: {$checks} binary team rules, fairness, permissions, leases, time, recovery and records checks.\n";
