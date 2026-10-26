<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EduGames\Games\Modules\Data_Visualization\Hands_Board as Game;
use EduGames\Games\Modules\Data_Visualization\Visualization_Scenes as Scenes;
$checks=0;
function check(bool $ok,string $why): void {global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function reject(callable $f): void {try{$f();}catch(InvalidArgumentException){check(true,'rejected');return;}throw new RuntimeException('Expected rejection');}
function solution(array $s): array {
    if($s['scene']==='pie'){$sum=array_sum(array_column($s['rows'],'value'));return array('type'=>'dispatch','allocation'=>array_map(fn($r)=>(int)round($s['supply']*$r['value']/$sum),$s['rows']));}
    $choices=array_values(array_filter(Scenes::choices($s),fn($c)=>$c['eligible']??true));usort($choices,fn($a,$b)=>$b['value']<=>$a['value']);
    return array('type'=>'dispatch','choice'=>$choices[0]['id']);
}
$fixtures=array();
foreach(array('line','pie','histogram','scatter') as $scene){
    $s=Game::create('practice-'.$scene);$s['unranked']=false;$seen=array();$winners=array();$conditions=array();$shortcuts=array();
    for($i=0;$i<120;$i++){
        if($i<16)$fixtures[]=array('state'=>Game::view($s),'choices'=>Scenes::choices($s),'bins'=>$scene==='histogram'?Scenes::bins($s):array());
        check($s['scene']===$scene,'dedicated practice retains requested chart');$seen[]=json_encode($s['rows']);$move=solution($s);
        if($scene==='pie'){
            check(array_sum($move['allocation'])===$s['supply'],'exact integral allocation');
            $winners[]=implode(',',$move['allocation']);$conditions[]=$s['supply'];
            $wrong=$move;$wrong['allocation'][0]--; $wrong['allocation'][1]++;
            reject(fn()=>Game::apply($s,array('type'=>'dispatch','allocation'=>array(0,0,0))));
            reject(fn()=>Game::apply($s,array('type'=>'dispatch','allocation'=>array(-1,1,12))));
            reject(fn()=>Game::apply($s,array('type'=>'dispatch','allocation'=>array(4.0,4,4))));
        }else{
            $choices=Scenes::choices($s);
            $best=current(array_filter($choices,fn($c)=>$c['id']===$move['choice']));$winners[]=$best['id'];
            if($scene==='line'){
                $ends=array_column($s['rows'],'value');$shortcuts[]=($best['after']===max($ends));
                $starts=array_map(fn($region)=>current(array_filter($s['rows'],fn($r)=>$r['region']===$region))['value'],array('동쪽','중앙','서쪽'));
                $conditions[]=array_search($best['region'],array('동쪽','중앙','서쪽'),true)===array_search(min($starts),$starts,true)?'lowest':'other';
            }
            elseif($scene==='histogram'){
                $bins=Scenes::bins($s);check(array_sum(array_column($bins,'count'))===count($s['rows']),'histogram conserves each measurement exactly once');
                $conditions[]=$s['window'];$tall=array_search(max(array_column($bins,'count')),array_column($bins,'count'),true);
                $start=max(0,min((int)round($tall-($s['window']-1)/2),count($choices)-1));$shortcuts[]=$choices[$start]['value']===$best['value'];
            }else {
                $eligible=array_filter($choices,fn($c)=>$c['eligible']);check(count($eligible)>=3&&count($eligible)<=6,'several feasible and infeasible devices');
                $conditions[]=$s['scatterGoal'];$shortcuts[]=$best['id']==='relay-'.((int)floor($s['budget']/10)-1);
            }
            $misses=array_values(array_filter($choices,fn($c)=>!($c['eligible']??true)||$c['value']<$best['value']));
            $wrong=array('type'=>'dispatch','choice'=>$misses[0]['id']);
            reject(fn()=>Game::apply($s,array('type'=>'dispatch','choice'=>'not-a-point')));
        }
        $bad=Game::apply($s,$wrong);check(!$bad['feedback']['correct']&&$bad['score']<= $s['score'],$scene.' '.$i.': inferior outcome never earns points');
        $before=$s;$s=Game::apply($s,$move);check($s['feedback']['correct']&&$s['score']>$before['score'],'valid scene strategy scores');
        check($s['rows']===$before['rows']&&$s['phase']==='review','same data retained for comparison');reject(fn()=>Game::apply($s,$move));
        $score=$s['score'];$s=Game::apply($s,array('type'=>'next'));check($s['score']===$score,'next has no score');
    }
    check(count(array_unique($seen))>=110,$scene.' has varied scene samples');
    check(count(array_unique($conditions))>=2,'varied constraints or starting ranks');
    check(count(array_unique($winners))>=($scene==='pie'?40:($scene==='line'?10:($scene==='scatter'?8:5))),'answers spread across labels and positions');
    if($shortcuts)check(count(array_filter($shortcuts))<100,$scene.' shortcuts are not reliable');
    $practice=Game::create('practice-'.$scene);$done=Game::apply($practice,solution($practice));check($done['score']===0&&Game::view($done)['remaining']===null,'untimed practice is unranked');
    $late=Game::create('practice-'.$scene);$late['unranked']=false;$late['deadline']=microtime(true)-1;
    check(Game::apply($late,solution($late))['status']==='won','deadline applies to all scene actions');
}
$s=Game::create('board-01');$kinds=array();
for($i=0;$i<14;$i++){
    $kinds[]=$s['kind'];
    if($s['scene']==='bar'){$bars=Game::bars($s,$s['kind']);$move=array('type'=>'dispatch','view'=>$s['kind'],'bar'=>$bars[0]['id']);}else $move=solution($s);
    $s=Game::apply($s,$move);$s=Game::apply($s,array('type'=>'next'));
}
foreach(array_chunk($kinds,7) as $bag){sort($bag);check($bag===array('histogram','hour','line','pair','pie','scatter','total'),'each shuffled pack contains every mission');}
check(array_slice($kinds,0,7)!==array_slice($kinds,7),'new pack order changes');
$s=Game::create('practice-line');$s['unranked']=false;$s['deadline']=microtime(true)+20;
$s=Game::apply($s,solution($s));$remaining=Game::view($s)['remaining'];$s['deadline']=microtime(true)-100;
check(Game::view($s)['remaining']===$remaining&&Game::view($s)['timerPaused'],'review remains paused beyond old deadline');
$next=Game::apply($s,array('type'=>'next'));
check($next['status']==='playing'&&!Game::view($next)['timerPaused']&&abs(Game::view($next)['remaining']-$remaining)<0.1,'next restores only unused active time');
check(Game::apply($s,array('type'=>'finish'))['status']==='won','finish works while paused');
check($s['sceneStats']['line']===array('attempts'=>1,'success'=>1),'per-chart results are retained');
if(($argv[1]??'')==='--fixtures'){echo json_encode($fixtures,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
echo "$checks visualization scene checks passed\n";
