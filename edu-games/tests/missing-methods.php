<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EduGames\Games\Modules\Missing_Values\Hands_Board as Game;
$checks=0;
function check(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rejects(callable $f): void {try{$f();}catch(InvalidArgumentException){check(true,'rejected');return;}throw new RuntimeException('Expected rejection');}
function submit(array $s,string $method,?array $ids=null): array {return Game::apply($s,array('type'=>'restore','method'=>$method,'ids'=>$ids??Game::source_ids($s)));}
foreach(Game::METHODS as $method=>$name){
    $s=Game::create('example-'.$method);$s['unranked']=false;
    $view=Game::view($s);
    check(!isset($view['truth'])&&!isset($view['family'])&&!isset($view['queue'])&&!isset($view['reason']),'source truth and next examples private');
    rejects(fn()=>Game::apply($s,array('type'=>'next')));
    rejects(fn()=>Game::apply($s,array('type'=>'restore','ids'=>Game::source_ids($s))));
    $good=submit($s,$method);
    check($good['phase']==='review'&&$good['feedback']['hits']===3&&$good['score']===65,$method.' works on three targets');
    check(count($good['feedback']['comparisons'])===count($s['methods']),$method.' compares all offered methods');
    $wrong=0;foreach($s['methods'] as $other)if($other!==$method){$bad=submit($s,$other);if($bad['feedback']['hits']<3){$wrong++;check($bad['score']===0&&$bad['combo']===0,'unsuitable method cannot gain points');}}
    check($wrong>0,'there is a meaningful alternative');
    rejects(fn()=>submit($good,$method));
    $next=Game::apply($good,array('type'=>'next'));check($next['phase']==='solve'&&!isset($next['feedback']),'explicit next only after feedback');
    $practice=submit(Game::create('example-'.$method),$method);check($practice['score']===0&&Game::view($practice)['remaining']===null,'practice is untimed and unranked');
}
foreach(array('mean'=>'median','median'=>'mean') as $right=>$wrong){
    $s=Game::create('example-'.$right);$s['unranked']=false;
    $a=submit($s,$right);$b=submit($s,$wrong);
    check($a['feedback']['hits']===3&&$b['feedback']['hits']===0,'mean and median genuinely differ');
    $s['score']=100;$bad=submit($s,$wrong);check($bad['score']===80,'wrong restoration deducts, not just partial credit');
    $eligible=Game::source_ids($s);$partial=array_slice($eligible,0,2);$bad=submit($s,$right,$partial);
    check(!$bad['feedback']['scopeOk']&&$bad['score']===80,'cherry-picked cohort cannot farm points');
    $bad=submit($s,$right,array_keys($s['rows']));check(!$bad['feedback']['scopeOk']&&$bad['score']===80,'unrelated data cannot farm points');
    rejects(fn()=>submit($s,$right,array($eligible[0],$eligible[0])));
}
$s=Game::create('example-mean');$s['unranked']=false;$base=$s['truth'][1];
foreach(Game::source_ids($s) as $j=>$i)$s['rows'][$i]['value']=$base+$j-2;
$a=submit($s,'mean');$b=submit($s,'median');check($a['score']===$b['score'],'equivalent results receive equivalent score');
check(array_column($a['feedback']['predictions'],'value')===array_column($b['feedback']['predictions'],'value'),'same estimate, not arbitrary method-name penalty');
$s=Game::create('board-01');$seen=array();$contexts=array();
for($i=0;$i<27;$i++){$seen[]=$s['family'];$contexts[]=$s['context'];$s=submit($s,$s['family']);$s=Game::apply($s,array('type'=>'next'));}
for($i=0;$i<3;$i++)check(count(array_unique(array_slice($seen,$i*9,9)))===9,'shuffled bag covers all methods');
check(count(array_unique($contexts))>=12,'varied contexts, not one fixed A cohort');
$s=Game::create('board-01');$s['deadline']=microtime(true)-1;$done=submit($s,$s['family']);check($done['status']==='won'&&$done['score']===0,'late restoration does not score');
$s=Game::create('board-01');$s['deadline']=microtime(true)+50;$review=submit($s,$s['family']);
check(Game::view($review)['timerPaused']&&$review['pausedRemaining']>49,'review pauses the remaining play time');
$review['deadline']=microtime(true)-10;
check(Game::view($review)['remaining']>49,'expired old deadline does not consume review time');
$next=Game::apply($review,array('type'=>'next'));
check($next['status']==='playing'&&!Game::view($next)['timerPaused']&&$next['deadline']>microtime(true)+49,'next resumes the saved play time');
check(!isset(Game::view($review)['pausedRemaining']),'pause internals remain private');
$done=Game::apply($review,array('type'=>'finish'));check($done['status']==='won','finish is valid while paused');
rejects(fn()=>Game::apply($done,array('type'=>'next')));
check(EduGames\Play\Catalog::base_version('missing-values')===Game::CONTENT_VERSION,'only missing game gets a new score version');
check(EduGames\Play\Catalog::base_version('outlier-detection')===EduGames\Play\Catalog::VERSION,'other game score versions preserved');
echo "$checks missing-method checks passed\n";
