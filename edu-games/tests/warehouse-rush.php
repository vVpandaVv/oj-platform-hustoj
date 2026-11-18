<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EduGames\Games\Modules\Data_Structure_Lab\Hands_Board as Game;
use EduGames\Play\Catalog;

$checks=0;
function wh_check(bool $ok,string $why): void {global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function wh_reject(callable $fn): void {try{$fn();}catch(InvalidArgumentException){wh_check(true,'rejected');return;}throw new RuntimeException('Expected rejection');}
function wh_inventory(array $s): void {
    $stored=array_merge($s['incoming'],$s['stack'],$s['queue'],$s['buffer']);
    $wanted=array_merge(...array_column($s['orders'],'items'));sort($stored);sort($wanted);
    wh_check($stored===$wanted,'every pending parcel exists exactly once');
    wh_check(count($stored)===count(array_unique($stored)),'unique parcel identities');
    wh_check(count($s['stack'])<=5&&count($s['queue'])<=5&&count($s['buffer'])<=2,'capacity preserved');
    $e=$s['earnings'];wh_check($s['score']===$e['delivery']+$e['efficiency']+$e['combo']+$e['contracts']+$e['express']-$e['cost'],'score ledger balances');
}
$s=Game::create('board-01');$again=Game::create('board-01');$a=$s;$b=$again;unset($a['deadline'],$b['deadline']);
wh_check($a===$b,'same daily starting challenge');
wh_check(str_starts_with(Catalog::version('data-structure-lab'),'warehouse-rush-v2-'),'new leaderboard season');
$v=Game::view($s);wh_check(!isset($v['handling'],$v['seed'],$v['deadline'],$v['orders'][0]['due']),'private counters and server clock omitted');
wh_check(count($s['orders'])===3&&count($s['incoming'])===9,'three concurrent contracts');wh_inventory($s);

// Test LIFO/FIFO on actual pending cargo, without asking the server to remove a chosen middle item.
$items=$s['orders'][0]['manifest'];[$a,$b,$c]=$items;
$s['incoming']=array_merge($items,array_values(array_diff($s['incoming'],$items)));
$s['orders'][0]['manifest']=$s['orders'][0]['items']=array($b,$a,$c);
foreach($items as $_)$s=Game::apply($s,array('type'=>'push','lane'=>'stack'));
$s=Game::apply($s,array('type'=>'pop','lane'=>'stack','order'=>$s['orders'][0]['id']));
wh_check($s['buffer']===array($c)&&$s['stack']===array($a,$b),'LIFO wrong target parks top only');
$s=Game::apply($s,array('type'=>'pop','lane'=>'stack','order'=>$s['orders'][0]['id']));
wh_check($s['orders'][0]['items']===array($a,$c)&&$s['completed']===1,'correct target advances only one contract');
$s=Game::apply($s,array('type'=>'return','lane'=>'queue','id'=>0));
wh_check($s['queue']===array($c)&&!$s['buffer'],'buffer enters queue at rear');
$s=Game::apply($s,array('type'=>'pop','lane'=>'stack','order'=>$s['orders'][0]['id']));
$other=$s['incoming'][0];$s=Game::apply($s,array('type'=>'push','lane'=>'stack'));
$oldOtherOrders=array_slice($s['orders'],1);
$s=Game::apply($s,array('type'=>'pop','lane'=>'queue','order'=>$s['orders'][0]['id']));
wh_check($s['contracts']===1&&$s['orders'][0]['id']===4,'only finished destination receives a new contract');
wh_check($s['stack']===array($other)&&array_slice($s['orders'],1)===$oldOtherOrders,'other stock and contracts persist');wh_inventory($s);
wh_reject(fn()=>Game::apply($s,array('type'=>'pop','lane'=>'stack','order'=>1)));

$s=Game::create('board-01');$wanted=$s['orders'][0]['items'][0];
$s['incoming']=array_merge(array($wanted),array_values(array_diff($s['incoming'],array($wanted))));
$direct=Game::apply($s,array('type'=>'push','lane'=>'queue'));
$detour=Game::apply($direct,array('type'=>'park','lane'=>'queue'));
$direct=Game::apply($direct,array('type'=>'pop','lane'=>'queue','order'=>1));
$detour=Game::apply($detour,array('type'=>'return','lane'=>'queue','id'=>0));
$detour=Game::apply($detour,array('type'=>'pop','lane'=>'queue','order'=>1));
wh_check($direct['completed']===$detour['completed']&&$direct['score']>$detour['score'],'same delivery count has different efficiency score');
wh_check($direct['efficient']===1&&$detour['efficient']===0,'only two-handling delivery earns efficiency reward');
$held=Game::apply(Game::apply($s,array('type'=>'push','lane'=>'stack')),array('type'=>'park','lane'=>'stack'));
$held=Game::apply($held,array('type'=>'ship-buffer','id'=>0,'order'=>1));wh_check(!$held['buffer']&&$held['completed']===1,'buffer can dispatch directly');

$express=Game::create('board-01');$expItems=$express['orders'][1]['items'];
$express['incoming']=array_merge($expItems,array_values(array_diff($express['incoming'],$expItems)));
$late=$express;$late['orders'][1]['due']=-1;
foreach(array('express','late') as $name){
    foreach($expItems as $_){$$name=Game::apply($$name,array('type'=>'push','lane'=>'queue'));$$name=Game::apply($$name,array('type'=>'pop','lane'=>'queue','order'=>2));}
}
wh_check($express['score']>$late['score']&&$late['contracts']===1,'late express remains deliverable but loses only timing reward');
$expired=$direct;$expired['deadline']=microtime(true)-1;$end=Game::apply($expired,array('type'=>'push','lane'=>'queue'));
wh_check($end['status']==='won'&&$end['score']===$direct['score']&&$end['moves']===$direct['moves'],'expiry saves without applying late move');
wh_check($end['metrics']['deliveries']===1&&isset($end['metrics']['earnings']),'final operational metrics');
wh_reject(fn()=>Game::apply($end,array('type'=>'finish')));

mt_srand(9021);$s=Game::create('board-01');
for($i=0;$i<800;$i++){
    $lane=mt_rand(0,1)?'stack':'queue';$order=$s['orders'][mt_rand(0,2)]['id'];
    $actions=array(array('type'=>'push','lane'=>$lane),array('type'=>'pop','lane'=>$lane,'order'=>$order),array('type'=>'park','lane'=>$lane),array('type'=>'reset'));
    if($s['buffer']){$id=mt_rand(0,count($s['buffer'])-1);$actions[]=array('type'=>'return','lane'=>$lane,'id'=>$id);$actions[]=array('type'=>'ship-buffer','id'=>$id,'order'=>$order);}
    try{$s=Game::apply($s,$actions[mt_rand(0,count($actions)-1)]);}catch(InvalidArgumentException){}
    wh_inventory($s);
}
echo "$checks warehouse rush domain checks passed\n";
