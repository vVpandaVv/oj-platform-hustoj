<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EduGames\Boards\Catalog;
use EduGames\Boards\Rules;
$checks=0;
function check_strategy(bool $ok,string $message): void {global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
foreach(Catalog::GAMES as $game=>$class){
    foreach($class::levels() as $id=>$d){$s=$class::create($id);foreach($class::solution($id) as $m){$old=$s;$s=$class::apply($s,$m);check_strategy($s['spent']>=$old['spent']&&$s['spent']<=$s['budget'],$game.' resource conservation');check_strategy(!isset($class::view($s)['lastRequest']),$game.' request privacy');}check_strategy($s['status']==='won',"$game/$id solvable: ".json_encode($s,JSON_UNESCAPED_UNICODE));check_strategy(Rules::score($s)>0&&Rules::score($s)<=1000,'score range');try{$class::apply($s,array('type'=>'finish'));throw new RuntimeException('Finished state mutable');}catch(InvalidArgumentException $e){$checks++;}}
    foreach(array_keys($class::levels()) as $id){$s=$class::create($id);foreach($class::solution($id) as $m)$s=json_decode(json_encode($class::apply($s,$m)),true);check_strategy(Rules::score($s)===1000,"$game/$id perfect score reachable after JSON persistence");}
    echo "$game: ".count($class::levels())." board solutions passed\n";
}
function rejects_strategy(callable $fn): void {try{$fn();}catch(InvalidArgumentException $e){check_strategy(true,'rejected move');return;}throw new RuntimeException('Invalid move accepted');}
function play_strategy(string $game,string $level,array $moves): array {$c=Catalog::game($game);$s=$c::create($level);foreach($moves as $m)$s=$c::apply($s,$m);return $s;}
foreach(Catalog::GAMES as $game=>$c){
    $s=$c::create('board-01');rejects_strategy(fn()=>$c::apply($s,array('type'=>'hack','score'=>99999)));
    $move=$c::solution('board-01')[0];$s['budget']=1;
    if($game==='outlier-detection'){$s=$c::apply($s,array('type'=>'investigate'));$move=array('type'=>'inspect','record'=>3,'evidence'=>'unit');}
    try{$s=$c::apply($s,$move);check_strategy($s['status']==='lost','resource exhaustion ends run');}catch(InvalidArgumentException $e){check_strategy($s['spent']===0,'unaffordable action atomic');}
    $public=$c::view($c::create('board-01'));foreach(array('target','actual','bad','experiments','solution','test','lastRequest') as $key)check_strategy(!isset($public[$key]),'private data not exposed');
}
$c=Catalog::game('data-extraction');$s=$c::apply($c::create('board-01'),array('type'=>'load','slot'=>0));
$wrong=$c::apply($s,array('type'=>'ship','slot'=>0,'order'=>0));check_strategy($wrong['slots']===$s['slots']&&$wrong['mistakes']===1,'wrong shipment preserves box');
$copy=$c::apply($s,array('type'=>'copy','slot'=>0,'to'=>1));$copy=$c::apply($copy,array('type'=>'filter','slot'=>0,'field'=>'value','value'=>3));check_strategy(count($copy['slots'][1]['rows'])===6,'copy independent');
$copy=$c::apply($copy,array('type'=>'project','slot'=>0));rejects_strategy(fn()=>$c::apply($copy,array('type'=>'filter','slot'=>0,'field'=>'value','value'=>3)));
rejects_strategy(fn()=>$c::apply($s,array('type'=>'load','slot'=>'1')));
$c=Catalog::game('missing-values');$s=$c::create('board-01');rejects_strategy(fn()=>$c::apply($s,array('type'=>'repair','node'=>1,'tool'=>'interpolate')));rejects_strategy(fn()=>$c::apply($s,array('type'=>'advance')));
$s=play_strategy('missing-values','board-02',array_slice($c::solution('board-02'),0,4));rejects_strategy(fn()=>$c::apply($s,array('type'=>'repair','node'=>2,'tool'=>'mode')));
foreach(array('interpolate','carry','carry') as $i=>$tool)$s=$c::apply($s,array('type'=>'repair','node'=>$i,'tool'=>$tool));$s=$c::apply($s,array('type'=>'advance'));check_strategy($s['status']==='lost','unsafe cheap estimates lose');
$s=play_strategy('missing-values','board-03',array_slice($c::solution('board-03'),0,4));check_strategy($c::view($s)['nodes'][0]['values'][0]===null,'blackout missing previous sample');rejects_strategy(fn()=>$c::apply($s,array('type'=>'repair','node'=>0,'tool'=>'interpolate')));
$s=$c::create('board-01');foreach($c::view($s)['nodes'] as $node)check_strategy(!array_key_exists('actual',$node),'future actual value stays private');
foreach(array('interpolate','carry','mode') as $i=>$tool)$s=$c::apply($s,array('type'=>'repair','node'=>$i,'tool'=>$tool));
check_strategy($c::view($s)['trace']===array(),'no observation feedback before committing day');
$s=$c::apply($s,array('type'=>'advance'));$day=$c::view($s)['trace'][0];
check_strategy(count($day['checks'])===3&&array_sum(array_column($day['checks'],'loss'))===$day['damage'],'daily comparison explains each trust loss');
foreach($day['checks'] as $i=>$check)check_strategy(array_key_exists('actual',$check)&&$check['value']===$day['values'][$i],'completed day compares chosen and actual values');
require __DIR__.'/outlier-investigation.php';
$c=Catalog::game('data-visualization');$moves=$c::solution('board-03');foreach($moves as &$m)if($m['type']==='place')$m['slot']=intdiv($m['slot'],2);unset($m);array_unshift($moves,array('type'=>'configure','key'=>'binWidth','value'=>4));check_strategy(play_strategy('data-visualization','board-03',$moves)['status']==='won','alternate valid bin width');
$moves=$c::solution('board-02');array_unshift($moves,array('type'=>'configure','key'=>'kind','value'=>'line'));check_strategy(play_strategy('data-visualization','board-02',$moves)['status']==='won','line alternative accepted');array_unshift($moves,array('type'=>'configure','key'=>'baseline','value'=>2));check_strategy(play_strategy('data-visualization','board-02',$moves)['status']==='lost','clipped line rejected');
$s=$c::apply($c::create('board-01'),array('type'=>'place','row'=>0,'slot'=>0));rejects_strategy(fn()=>$c::apply($s,array('type'=>'place','row'=>0,'slot'=>1)));
$c=Catalog::game('ai-classifier-lab');$s=play_strategy('ai-classifier-lab','board-01',array_slice($c::solution('board-01'),0,-1));$s=$c::apply($s,array('type'=>'boundary','slot'=>0,'axis'=>'x','op'=>'gte','cut'=>6));check_strategy($s['validation']===null,'edited boundary invalidates validation');check_strategy($c::apply($s,array('type'=>'finish'))['status']==='lost','no stale validation credit');
$s=$c::apply($c::create('board-01'),$c::solution('board-01')[0]);for($i=0;$i<3;$i++)$s=$c::apply($s,array('type'=>'validate'));rejects_strategy(fn()=>$c::apply($s,array('type'=>'validate')));
check_strategy($c::predict(array('x'=>5,'y'=>0),array(array('axis'=>'x','op'=>'gte','cut'=>5)))===1&&$c::predict(array('x'=>5,'y'=>0),array(array('axis'=>'x','op'=>'lt','cut'=>5)))===0,'boundary inclusion exact');
$c=Catalog::game('ai-model-lab');$s=$c::create('board-01');rejects_strategy(fn()=>$c::apply($s,array('type'=>'train','model'=>'tree')));rejects_strategy(fn()=>$c::apply($s,array('type'=>'review','batch'=>0)));
$s=play_strategy('ai-model-lab','board-01',array_slice($c::solution('board-01'),0,-1));$batch=array_search(1,$s['batches'],true);$s=$c::apply($s,array('type'=>'review','batch'=>$batch));check_strategy($s['trained']===null&&!$s['validated'],'changed data invalidates model');
$s=play_strategy('ai-model-lab','board-01',array(array('type'=>'collect','batch'=>0),array('type'=>'review','batch'=>0),array('type'=>'train','model'=>'linear')));check_strategy($c::view($s)['singleClass']===true,'single class fallback disclosed');
foreach($c::bank()['missions'] as $mission){
    check_strategy(count($mission['experiments'])===78,'78 experiments per mission');
    foreach($mission['experiments'] as $e)foreach(array('training','validation','test') as $split){
        $m=$e[$split];$a=$m['matrix'];$n=array_sum($a[0])+array_sum($a[1]);
        check_strategy($n===$m['count'],'confusion matrix count');
        check_strategy(abs(100*($a[0][0]+$a[1][1])/$n-$m['accuracy'])<=0.005000001,'accuracy derives from confusion matrix');
    }
}
require __DIR__.'/strategy-expansion.php';
require __DIR__.'/strategy-api.php';
echo "PASS: $checks strategy mechanics, experiment metrics and API checks (DB doubles).\n";
