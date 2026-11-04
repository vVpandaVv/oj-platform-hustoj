<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/binary-team-support.php';
use EduGames\Multiplayer\{Room_Rules as R,Room_Policy,Ticket,Service,Failure};
use EduGames\Tests\Binary_Memory_Repository;
$checks=0;
function check_ops(bool $ok,string $message): void {global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function denied_ops(callable $fn,int $status,string $message): void {try{$fn();}catch(Failure $e){check_ops($e->status===$status,$message.' / '.$e->getMessage());return;}throw new RuntimeException('Expected rejection: '.$message);}
function actor_ops(int $id,bool $teacher=false): array {return array('id'=>$id,'name'=>'참가자 '.$id,'manage'=>$teacher,'admin'=>false);}
$teacher=actor_ops(100,true);$student=actor_ops(1);$now=1000000;
foreach(array(R::BINARY,R::DEVICE) as $game){
    denied_ops(fn()=>R::create('ABC234',$student,array('mode'=>'official'),$now,'seed',$game),403,'students cannot create official match');
    $s=R::create('ABC234',$teacher,array('mode'=>'official','assignment'=>'manual','capacity'=>6),$now,'seed',$game);
    for($id=1;$id<=4;$id++)$s=R::command($s,actor_ops($id),array('type'=>'join'),$now);
    check_ops(array_column($s['players'],'team')===array(0,0,0,0),'manual joins wait unassigned');
    denied_ops(fn()=>R::command($s,$student,array('type'=>'team','team'=>1),$now),403,'student cannot self assign official match');
    denied_ops(fn()=>R::command($s,$student,array('type'=>'start'),$now),403,'student cannot start official match');
    denied_ops(fn()=>R::command($s,$teacher,array('type'=>'bot-fill'),$now),403,'official matches never allow bots');
    denied_ops(fn()=>R::command($s,$teacher,array('type'=>'bot-level','pace'=>'slow'),$now),403,'official matches never change bot profiles');
    denied_ops(fn()=>R::command($s,$teacher,array('type'=>'start'),$now),409,'unassigned roster cannot start');
    $signatures=array();for($n=0;$n<24;$n++){$s=R::command($s,$teacher,array('type'=>'balance'),$now);$signatures[]=implode('',array_column($s['players'],'team'));check_ops(count(array_filter($s['players'],fn($p)=>$p['team']===1))===2,'random teams remain even');}
    check_ops(count(array_unique($signatures))>1,'random assignment is not repeated insertion order');
    $s=R::command($s,$teacher,array('type'=>'team','user'=>1,'team'=>0),$now);
    check_ops($s['players'][1]['team']===0,'teacher can return a student to waiting');
    $s=R::command($s,$teacher,array('type'=>'balance'),$now);
    foreach(range(1,4) as $id)$s=R::command($s,actor_ops($id),array('type'=>'ready','ready'=>true),$now);
    $s=R::command($s,$teacher,array('type'=>'start'),$now);
    $boardActor=$teacher+array('scope'=>'board','room'=>'ABC234');$control=$teacher+array('scope'=>'control','room'=>'ABC234');
    $v=R::view($s,$boardActor,$now);check_ops($v['overview']&&$v['readOnly']&&!$v['host']&&$v['board']===null&&count($v['teams'])===2,'projector has all teams and no controls');
    $raw=json_encode($v);foreach(array('decimal','prompt','bits','device','face','seed','botState','decks') as $private)check_ops(!str_contains($raw,'"'.$private.'":'),'projector redacts '.$private);
    check_ops(R::view($s,$control,$now)['host'],'teacher control overview can operate');
    denied_ops(fn()=>R::command($s,$boardActor,array('type'=>'pause'),$now),403,'projector cannot mutate');
    denied_ops(fn()=>R::view($s,$teacher+array('scope'=>'board','room'=>'ZZZ234'),$now),403,'projector bound to one room');
    denied_ops(fn()=>R::view($s,actor_ops(2,true)+array('scope'=>'control','room'=>'ABC234'),$now),403,'other teacher cannot control participant room');
    $secret=str_repeat('k',40);$ticket=Ticket::issue($boardActor,$secret,1000,$game);$decoded=Ticket::read($ticket,$secret,1001,$game);
    check_ops($decoded['scope']==='board'&&$decoded['room']==='ABC234','signed scope survives relay ticket');
    denied_ops(fn()=>Ticket::issue($student+array('scope'=>'board','room'=>'ABC234'),$secret,1000,$game),403,'student cannot obtain projector ticket');
    $practice=R::create('BCD234',$student,array('participate'=>true,'botPace'=>'fast'),$now,'bot-seed',$game);
    $practice=R::command($practice,$student,array('type'=>'bot-fill'),$now);
    check_ops(count($practice['players'])===4&&count(array_filter($practice['players'],fn($p)=>!empty($p['bot'])))===3,'solo practice fills both sides to equal two');
    check_ops(Room_Policy::mode($practice)==='practice','student rooms are practice');
    $practice=R::command($practice,$student,array('type'=>'ready','ready'=>true),$now);
    $practice=R::command($practice,$student,array('type'=>'start'),$now);$start=$practice['startedAt'];
    for($at=$start;$at<=$start+12000;$at+=250)$practice=R::tick($practice,$at);
    check_ops(array_sum(array_column($practice['players'],'edits'))>0,'bots operate real boards over time');
    check_ops(!str_contains(json_encode(R::view($practice,$student,$at)),'"memory":'),'bot memory stays private');
    if($game===R::DEVICE){foreach($practice['botState'] as $state)check_ops(count($state['memory'])<=24,'card bot memory is bounded');}
    else{
        $human=R::create('CDE234',$student,array('participate'=>true),$now,'claim-seed',$game);$human=R::command($human,$student,array('type'=>'bot-fill'),$now);$human=R::command($human,$student,array('type'=>'ready','ready'=>true),$now);$human=R::command($human,$student,array('type'=>'start'),$now);
        $row=$human['boards'][1]['rows'][0]['id'];$human=R::command($human,$student,array('type'=>'claim','row'=>$row),$human['startedAt']+100);
        $human=R::tick($human,$human['startedAt']+3000);check_ops($human['boards'][1]['rows'][0]['owner']===1,'bots respect human leases');
    }
    $practice=R::command($practice,$student,array('type'=>'pause'),$start+12200);$paused=$practice;
    check_ops(R::tick($practice,$start+13200)===$paused,'paused bots do not act');
    $practice=R::command($practice,$student,array('type'=>'resume'),$start+14200);
    $practice=R::command($practice,$student,array('type'=>'stop'),$start+15200);
    check_ops($practice['summary']['mode']==='practice'&&$practice['summary']['hasBots'],'bot results labeled practice');
    $store=new Binary_Memory_Repository();$store->save_result($practice['summary']);$s=R::command($s,$teacher,array('type'=>'stop'),$start+16000);$store->save_result($s['summary']);
    for($i=2;$i<=25;$i++){$copy=$practice['summary'];$copy['id']='BCD234-'.$i;$store->save_result($copy);}
    $service=new Service($store,$game);
    $admin=array_replace($teacher,array('admin'=>true));$formal=$service->records($admin,1,null,'official');$training=$service->records($admin,2,null,'practice');
    check_ops($formal['pagination']['total']===1&&count($formal['items'])===1,'official filter applies before pagination');
    check_ops($training['pagination']['total']===25&&count($training['items'])===5,'practice records paginate independently');
    $store->create($s);$before=$store->get('ABC234');
    denied_ops(fn()=>$service->access('ABC234',$boardActor,array('type'=>'rematch','round'=>1,'op'=>'board-forged-op'),$start+17000),403,'service blocks readonly signed mutation');
    check_ops($store->get('ABC234')===$before,'readonly rejected command never writes');

    $db=new Binary_Memory_Repository();$svc=new Service($db,$game);
    $solo=$svc->create($teacher,array('mode'=>'practice','assignment'=>'manual','capacity'=>2,'op'=>'solo-observer-room'),$now);
    $code=$solo['code'];
    $svc->access($code,$teacher,array('type'=>'bot-fill','round'=>1,'op'=>'solo-fill-all-bots'),$now);
    $solo=$svc->access($code,$teacher,array('type'=>'practice-start','round'=>1,'op'=>'solo-start-with-bots'),$now);
    check_ops($solo['status']==='playing'&&$solo['myTeam']>0&&count($solo['players'])===4,'solo shortcut joins observer even when bots filled capacity');
    check_ops(count(array_filter($solo['players'],fn($p)=>!empty($p['bot'])))===3,'solo shortcut preserves one human and three bots');
    check_ops($solo['board']!==null,'solo shortcut initializes a playable board');
    $solo=$svc->access($code,$teacher,null,$solo['startedAt']+4000);
    $close=array('type'=>'close','round'=>1,'op'=>'solo-close-live-room');
    denied_ops(fn()=>$svc->access($code,actor_ops(2),$close,$solo['startedAt']+4100),403,'other user cannot delete a room');
    denied_ops(fn()=>$svc->access($code,$teacher+array('scope'=>'board','room'=>$code),$close,$solo['startedAt']+4100),403,'projector cannot delete a room');
    $db->failResult=true;
    denied_ops(fn()=>$svc->access($code,$teacher,$close,$solo['startedAt']+4200),503,'failed result save prevents deletion');
    check_ops($db->get($code)!==null,'room survives result write failure');$db->failResult=false;$db->failDelete=true;
    denied_ops(fn()=>$svc->access($code,$teacher,$close,$solo['startedAt']+4300),503,'failed room delete is reported');
    check_ops($db->result($code.'-1')!==null&&$db->get($code)!==null,'result safely saved before room deletion');$db->failDelete=false;
    $left=$svc->access($code,$teacher,$close,$solo['startedAt']+4400);
    check_ops($left['left']&&$left['closed']&&$db->get($code)===null,'host closes and physically deletes room');
    check_ops($db->result($code.'-1')['finishedAt']===$solo['startedAt']+4300,'retry preserves original saved result');
    check_ops($svc->access($code,$teacher,$close,$solo['startedAt']+4500)['left'],'lost close response can be retried');
    check_ops(count($svc->rooms($teacher,$now)['items'])===0,'closed room disappears from room list');
    check_ops(count($svc->records($teacher,1)['items'])===1,'closed room result remains readable');

    $lobby=$svc->create($student,array('participate'=>true,'op'=>'host-leave-lobby-room'),$now);$code=$lobby['code'];
    check_ops($lobby['config']['botPace']==='slow','new rooms default to beginner bots');
    $svc->access($code,actor_ops(2),array('type'=>'join','op'=>'guest-joins-lobby'),$now);
    denied_ops(fn()=>$svc->access($code,actor_ops(2),array('type'=>'bot-level','pace'=>'fast','round'=>1,'op'=>'guest-change-bot-level'),$now),403,'only host changes bot level');
    denied_ops(fn()=>$svc->access($code,$student,array('type'=>'bot-level','pace'=>'invalid','round'=>1,'op'=>'invalid-bot-level'),$now),422,'unknown bot level rejected');
    check_ops($svc->access($code,$student,array('type'=>'bot-level','pace'=>'normal','round'=>1,'op'=>'host-change-bot-level'),$now)['config']['botPace']==='normal','existing lobby can change bot level without recreation');
    $leave=array('type'=>'leave','round'=>1,'op'=>'guest-leaves-lobby');
    check_ops($svc->access($code,actor_ops(2),$leave,$now)['left']&&$db->get($code)!==null,'guest leave does not delete host room');
    $svc->access($code,actor_ops(2),array('type'=>'join','op'=>'guest-rejoins-lobby'),$now);
    denied_ops(fn()=>$svc->access($code,$student,array('type'=>'practice-start','round'=>1,'op'=>'not-solo-start-room'),$now),409,'solo shortcut cannot force other humans ready');
    check_ops($db->get($code)['status']==='lobby','failed solo shortcut is atomic');
    $svc->access($code,$student,array('type'=>'leave','round'=>1,'op'=>'host-leaves-lobby'),$now);
    check_ops($db->get($code)===null&&$db->result($code.'-1')===null,'host leaving lobby deletes room without fabricating results');
    denied_ops(fn()=>$svc->access($code,actor_ops(2),null,$now),404,'remaining guests cannot continue deleted lobby');
    $formal=$svc->create($teacher,array('mode'=>'official','op'=>'console-only-formal'),$now);$code=$formal['code'];
    check_ops(!$formal['host'],'formal management controls do not appear on public player view');
    $owner=$teacher+array('scope'=>'control','room'=>$code);
    check_ops($svc->access($code,$owner,null,$now)['host'],'console retains formal controls');
    denied_ops(fn()=>$svc->access($code,$owner,array('type'=>'practice-start','round'=>1,'op'=>'formal-solo-denied'),$now),403,'formal matches cannot start with bots');
    $db->rooms[$code]['updatedAt']=$now-90000000;
    check_ops($svc->access($code,$owner,array('type'=>'close','round'=>1,'op'=>'expired-room-close'),$now)['closed'],'host can clean up an expired room');
}
echo "PASS: $checks team operations, permissions, assignment, projector privacy, bots and separated records checks.\n";
