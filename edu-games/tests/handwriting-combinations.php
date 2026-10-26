<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EduGames\Games\Modules\AI_Model_Lab\Hands_Board as Game;
use EduGames\Play\Catalog;

$checks=0;
function check(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rejects(callable $f): void {try{$f();}catch(InvalidArgumentException){check(true,'rejected');return;}throw new RuntimeException('Expected rejection');}
$data=Game::data();$sources=array();
foreach(array('candidates'=>120,'validation'=>40,'final'=>300) as $key=>$count){
    check(count($data[$key])===$count,'balanced full source bank');
    foreach($data[$key] as $row){
        $sources[]=$row['sourceId'];
        check(count($row['pixels'])===64&&min($row['pixels'])>=0&&max($row['pixels'])<=16,'real 8x8 grayscale image');
    }
}
check(count(array_unique($sources))===460,'training, validation and final images never overlap');
$cycle=array();
for($day=0;$day<120;$day++){
    $classes=Game::daily_classes(gmdate('Y-m-d',strtotime('2026-09-13 UTC')+$day*86400));
    $cycle[]=implode('',$classes);$subset=Game::data($classes);
    foreach(array('candidates'=>12,'validation'=>4,'final'=>30) as $key=>$count){
        $counts=array_count_values(array_column($subset[$key],'label'));
        check(count($counts)===3&&count(array_filter($counts,fn($n)=>$n===$count))===3,'every combination has balanced splits');
    }
    foreach(array_merge($subset['validation'],$subset['final']) as $row){
        $sorted=$row['neighbors'];sort($sorted);
        check($sorted===range(0,35),'neighbor IDs remapped to selected combination only');
        $distances=array();foreach($subset['candidates'] as $id=>$candidate){
            $distances[$id]=0;foreach($row['pixels'] as $i=>$pixel)$distances[$id]+=($pixel-$candidate['pixels'][$i])**2;
        }
        $expected=range(0,35);usort($expected,fn($a,$b)=>($distances[$a]<=>$distances[$b])?:($a<=>$b));
        check($expected===$row['neighbors'],'local model matches pixel distances including ties');
    }
}
check(count(array_unique($cycle))===120,'all 120 combinations appear once per daily cycle');
check(count(Game::levels())===13,'daily challenge and twelve selectable combinations');
$covered=array();
foreach(Game::levels() as $level=>$description){
    $s=Game::create($level);$v=Game::view($s);$subset=Game::data($s['classes']);$covered=array_merge($covered,$s['classes']);
    check(count($v['candidates'])===36&&count($v['validation'])===12,'UI only receives chosen digits');
    check(!isset($v['candidates'][0]['label'])&&!isset($v['candidates'][0]['sourceId'])&&!isset($v['final']),'private answers stay on server');
    $first=$s['classes'][0];$second=$s['classes'][1];
    $s=Game::apply($s,array('type'=>'label','id'=>0,'label'=>$first));
    check(count(array_filter(Game::view($s)['validation'],fn($p)=>$p['prediction']===$first))===12,'learner labels drive predictions in every combination');
    $s=Game::apply($s,array('type'=>'label','id'=>0,'label'=>$second));
    check(count($s['labels'])===1&&count(array_filter(Game::view($s)['validation'],fn($p)=>$p['prediction']===$second))===12,'relabel updates model without consuming memory');
    $other=array_values(array_diff(array_map('strval',range(0,9)),$s['classes']))[0];
    rejects(fn()=>Game::apply($s,array('type'=>'label','id'=>0,'label'=>$other)));
    rejects(fn()=>Game::apply($s,array('type'=>'label','id'=>36,'label'=>$first)));
    $single=Game::apply($s,array('type'=>'finish'));
    check($single['metrics']['total']===90&&$single['score']===333,'one label cannot earn full score');
    $s=Game::create($level);$counts=array_fill_keys($s['classes'],0);
    foreach($subset['candidates'] as $id=>$row)if($counts[$row['label']]<3){
        $s=Game::apply($s,array('type'=>'label','id'=>$id,'label'=>$row['label']));$counts[$row['label']]++;
    }
    $unseen=array_values(array_diff(range(0,35),array_keys($s['labels'])))[0];
    rejects(fn()=>Game::apply($s,array('type'=>'label','id'=>$unseen,'label'=>$first)));
    $done=Game::apply($s,array('type'=>'finish'));
    check($done['score']>$single['score']&&count($done['metrics']['recall'])===3,'varied labeled memory outperforms one-label shortcut');
    rejects(fn()=>Game::apply($done,array('type'=>'finish')));
    $empty=Game::apply(Game::create($level),array('type'=>'finish'));check($empty['score']===0,'no memory means no prediction');
}
$covered=array_values(array_unique($covered));sort($covered);check($covered===array_map('strval',range(0,9)),'selectable combinations include every digit');
$s=Game::create('digits-069');$s['deadline']=microtime(true)-1;
check(Game::apply($s,array('type'=>'label','id'=>0,'label'=>'6'))['score']===0,'late teaching cannot affect final evaluation');
unset($s['schema']);rejects(fn()=>Game::apply($s,array('type'=>'finish')));
rejects(fn()=>Game::create('digits-999'));
check(Catalog::base_version('ai-model-lab')===Game::CONTENT_VERSION,'old handwriting scores remain separate');
check(Catalog::base_version('missing-values')===EduGames\Games\Modules\Missing_Values\Hands_Board::CONTENT_VERSION,'missing-values records unaffected');
echo "$checks handwriting combination checks passed\n";
