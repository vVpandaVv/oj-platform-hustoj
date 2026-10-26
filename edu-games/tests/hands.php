<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/hands-solutions.php';
use EduGames\Play\Catalog;
$checks=0;
function check(bool $ok,string $text): void {global $checks;if(!$ok)throw new RuntimeException($text);$checks++;}
function reject(callable $f): void {try{$f();}catch(InvalidArgumentException){check(true,'rejected');return;}throw new RuntimeException('Expected rejection');}
foreach(Catalog::GAMES as $game=>$class){
    $s=$class::create('board-01');$v=$class::view($s);
    check(!isset($v['truth'])&&!isset($v['seed'])&&!isset($v['deadline']),'private state omitted');
    $again=$class::create('board-01');unset($again['deadline']);$copy=$s;unset($copy['deadline']);check($copy===$again,'same daily inputs');
    $s=$class::apply($s,hands_move($game,$s));check($s['status']==='playing','ongoing manipulation');
    $s=$class::apply($s,array('type'=>'finish'));check($s['status']==='won','explicit finish');
    reject(fn()=>$class::apply($s,array('type'=>'finish')));
    $s=$class::create('board-01');$s['deadline']=microtime(true)-1;$before=$s['turn'];$s=$class::apply($s,hands_move($game,$s));check($s['status']==='won'&&$s['turn']===$before,'late move cannot score');
}
$out=Catalog::game('outlier-detection');$s=$out::create('board-01');$sets=array();$none=false;$low=false;$high=false;
for($i=0;$i<90;$i++){
    $sets[]=json_encode($s['values']);$none=$none||!$s['truth'];foreach($s['truth'] as $id){$low=$low||$s['values'][$id]===min($s['values']);$high=$high||$s['values'][$id]===max($s['values']);}
    $s=$out::apply($s,hands_move('outlier-detection',$s));
}
check(count(array_unique($sets))>80&&$none&&$low&&$high,'new varied groups include high low and no outlier');
$s=$out::create('board-01');$bad=$out::apply($s,array('type'=>'scan','ids'=>range(0,6)));check($bad['score']===0,'selecting everything cannot win');
check($bad['feedback']['delta']===0&&$bad['feedback']['falsePositives']===6&&!$bad['feedback']['perfect'],'review feedback reports actual clamped score and over-selection');
$good=$out::apply($s,array('type'=>'scan','ids'=>$s['truth']));
check($good['feedback']['perfect']&&$good['feedback']['delta']===$good['score']-$s['score'],'successful review includes actual combo award');
check($good['feedback']['values']===$s['values']&&$good['feedback']['review']===$s['truth'],'feedback belongs to the completed group, not new observations');
reject(fn()=>$out::apply($s,array('type'=>'scan','ids'=>array(0,0))));
$missing=Catalog::game('missing-values');$s=$missing::create('board-01');
for($i=0;$i<9;$i++){$s=$missing::apply($s,hands_move('missing-values',$s));check($s['feedback']['hits']===3,'appropriate method reconstructs all targets');$s=$missing::apply($s,array('type'=>'next'));}
$factory=Catalog::game('data-extraction');$s=$factory::create('board-01');$g=$s['order'];$g['join']='and';$a=$factory::selected($s['items'],$g);$g['join']='or';$b=$factory::selected($s['items'],$g);check(count($a)<count($b),'AND and OR visibly differ');
$stack=Catalog::game('data-structure-lab');$s=$stack::create('board-01');$s['incoming']=array('A','B','C');$s['orders']=array('B','A','C');
for($i=0;$i<3;$i++)$s=$stack::apply($s,array('type'=>'push','lane'=>'stack'));
$s=$stack::apply($s,array('type'=>'pop','lane'=>'stack'));check($s['buffer']===array('C')&&$s['stack']===array('A','B'),'LIFO and temporary storage');
$s=$stack::apply($s,array('type'=>'pop','lane'=>'stack'));check($s['orders']===array('A','C')&&$s['score']>0,'correct shipment scores');
$s=$stack::apply($s,array('type'=>'return','lane'=>'queue','id'=>0));check($s['queue']===array('C')&&!$s['buffer'],'buffer returns via entrance');
$gate=Catalog::game('ai-classifier-lab');$s=$gate::create('board-01');$good=$gate::apply($s,array('type'=>'classify','line'=>array(64,26)));$bad=$gate::apply($s,array('type'=>'classify','line'=>array(0,0)));check($good['score']>$bad['score']&&$bad['feedback']['hits']===array(0,4),'one-class policy not rewarded');check($s['incoming']!==$good['incoming'],'fresh measured examples');
for($i=0;$i<3;$i++)$s=$gate::apply($s,array('type'=>'classify','line'=>array(64,26)));
// A small daily batch may fall where two boundaries agree. Compare a full profile cycle.
$staleHits=0;$adaptedHits=0;$profiles=array(array(64,26),array(24,72),array(82,55),array(42,18));
for($i=0;$i<12;$i++){
    $stale=$gate::apply($s,array('type'=>'classify','line'=>array(64,26)));
    $adapted=$gate::apply($s,array('type'=>'classify','line'=>$profiles[(int)floor($s['turn']/3)%4]));
    $staleHits+=array_sum($stale['feedback']['hits']);$adaptedHits+=array_sum($adapted['feedback']['hits']);$s=$adapted;
}
check($staleHits<$adaptedHits&&$adaptedHits===96,'unchanged gate not a universal winning policy across profiles');
$ai=Catalog::game('ai-model-lab');$s=$ai::create('digits-017');$s=$ai::apply($s,array('type'=>'label','id'=>0,'label'=>'1'));$v=$ai::view($s);check(count(array_filter($v['validation'],fn($p)=>$p['prediction']==='1'))===12,'learner label drives model');
$s=$ai::apply($s,array('type'=>'label','id'=>0,'label'=>'7'));check(count(array_filter($ai::view($s)['validation'],fn($p)=>$p['prediction']==='7'))===12,'relabel changes all nearest predictions');
for($i=1;$i<9;$i++)$s=$ai::apply($s,array('type'=>'label','id'=>$i,'label'=>'0'));reject(fn()=>$ai::apply($s,array('type'=>'label','id'=>9,'label'=>'0')));
check(!isset($v['candidates'][0]['label'])&&!isset($v['final']),'candidate truth and final evaluation private');
$data=$ai::data();$ids=array();foreach(array('candidates','validation','final') as $key)foreach($data[$key] as $p)$ids[]=$p['sourceId'];check(count($ids)===count(array_unique($ids)),'disjoint actual image splits');
$s=$ai::create('digits-017');$data=$ai::data($s['classes']);$counts=array('0'=>0,'1'=>0,'7'=>0);
foreach($data['candidates'] as $id=>$p)if($counts[$p['label']]<3){$s=$ai::apply($s,array('type'=>'label','id'=>$id,'label'=>$p['label']));$counts[$p['label']]++;}
$s=$ai::apply($s,array('type'=>'finish'));check($s['metrics']['correct']>70&&$s['score']>800,'representative correctly labeled memory generalizes');
echo "$checks hands-on domain checks passed\n";
