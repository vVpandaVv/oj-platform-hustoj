<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/play-solutions.php';
use Legacy_Play_Catalog as Catalog;
$checks=0;
function play_check(bool $condition,string $message): void {global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}
foreach(Catalog::GAMES as $game=>$class){
    foreach($class::levels() as $id=>$d){
        $s=$class::create($id);$before=$s;$moves=play_solution($game,$id);
        play_check(count($moves)>0,$game.' solution exists');
        foreach($moves as $m){$s=$class::apply($s,$m);play_check(is_array($class::view($s)),$game.' public view');}
        play_check($s['status']==='won'&&$s['score']>0,$game.' '.$id.' completes: '.json_encode($s['metrics']));
        try{$class::apply($s,end($moves));play_check(false,'finished mutation');}catch(InvalidArgumentException $e){play_check(true,'finished mutation rejected');}
        echo $game.' '.$id.' '.$s['score']."\n";
    }
}
$out=Catalog::game('outlier-detection');$s=$out::apply($out::create('board-01'),array('type'=>'run','steps'=>6));play_check($s['status']==='lost','blind raw reports fail');
$s=$out::create('board-02');foreach(array(0,1,2,3,4,5) as $i)$s=$out::apply($s,array('type'=>'policy','station'=>$i,'policy'=>'hold'));$s=$out::apply($s,array('type'=>'run','steps'=>6));play_check($s['score']===0&&$s['missed']>0,'hold everything loses real events');
$miss=Catalog::game('missing-values');$s=$miss::create('board-02');
foreach(array(11,12) as $i){$s=$miss::apply($s,array('type'=>'estimate','cell'=>$i,'low'=>1,'high'=>2));$s=$miss::apply($s,array('type'=>'walk','cell'=>$i));}
play_check($s['pos']===11&&$s['cells'][12]['value']===9,'imputation cannot rewrite world truth');
$harbor=Catalog::game('data-visualization');$s=$harbor::apply($harbor::create('board-01'),array('type'=>'handoff'));play_check(!isset($harbor::view($s)['source']),'handoff hides raw source');
$factory=Catalog::game('data-extraction');$s=$factory::create('board-01');for($i=0;$i<3;$i++)$s=$factory::apply($s,array('type'=>'run'));play_check($s['score']===0&&$s['wrong']>0,'shipping unfiltered private columns fails');
$gate=Catalog::game('ai-classifier-lab');$s=$gate::create('board-01');$s=$gate::apply($s,array('type'=>'gates','gates'=>array(array(0,0,10,10))));for($i=0;$i<4;$i++)$s=$gate::apply($s,array('type'=>'run'));play_check($s['fp']>0&&$s['score']<700,'admit everything not optimal');
$public=$gate::view($gate::create('board-01'));foreach($public['incoming'] as $p)play_check(!isset($p['good']),'incoming truth not leaked');
$ml=Catalog::game('ai-model-lab');$s=$ml::create('board-02');$s=$ml::apply($s,array('type'=>'train'));$bad=$ml::apply($s,array('type'=>'deploy'));
$good=$ml::create('board-02');foreach(play_solution('ai-model-lab','board-02') as $m)$good=$ml::apply($good,$m);
play_check($good['score']>$bad['score'],'chosen training samples change actual predictions');
$public=$ml::view($ml::create('board-01'));play_check(!isset($public['cards'][0]['truth'],$public['test'],$public['decks']),'ML truth and unseen tests withheld');
$s=$miss::create('board-01');$s['pos']=13;$s['fuel']=1;$s=$miss::apply($s,array('type'=>'walk','cell'=>14));play_check($s['status']==='lost'&&$s['pos']===13,'insufficient fuel cannot teleport to goal');
foreach(array('data-extraction'=>3,'ai-classifier-lab'=>4) as $game=>$steps){
    $class=Catalog::game($game);$a=$class::create('board-01');$b=$a;
    for($i=0;$i<$steps;$i++)$a=$class::apply($a,array('type'=>'run'));
    $b=$class::apply($b,array('type'=>'run','steps'=>$steps));
    play_check($a===$b,'automatic operation equals individual ticks '.$game);
}
$s=$ml::create('board-01');for($i=0;$i<3;$i++)$s=$ml::apply($s,array('type'=>'train'));
try{$ml::apply($s,array('type'=>'deck','cards'=>array(0,4,6,7)));play_check(false,'cannot strand a completed training deck');}catch(InvalidArgumentException $e){play_check(true,'training budget preserves deployable deck');}
$s=$factory::create('board-02');foreach(play_solution('data-extraction','board-01') as $m)$s=$factory::apply($s,$m);play_check($s['status']==='lost','one fixed factory cannot fulfill conflicting contracts');
$s=$harbor::create('board-02');foreach(play_solution('data-visualization','board-01') as $m)$s=$harbor::apply($s,$m);play_check($s['delivered']<24,'same totals do not justify same dispatch');
echo $checks." play domain checks passed\n";
