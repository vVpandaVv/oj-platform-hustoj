<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EduGames\Games\Modules\Data_Visualization\Hands_Board as Game;
$checks=0;
function check(bool $ok,string $text): void {global $checks;if(!$ok)throw new RuntimeException($text);$checks++;}
function rejects(callable $f): void {try{$f();}catch(InvalidArgumentException){check(true,'rejected');return;}throw new RuntimeException('Expected rejection');}
function sorted_bars(array $s,string $view): array {$bars=Game::bars($s,$view);usort($bars,fn($a,$b)=>$b['value']<=>$a['value']);return $bars;}
$s=Game::create('practice-bar');$s['unranked']=false;$seen=array();$pools=array();
for($i=0;$i<18;$i++){
    $winners=array();
    foreach(array('hour'=>12,'pair'=>6,'total'=>3) as $mode=>$size){
        $bars=sorted_bars($s,$mode);check(count($bars)===$size,'expected bars per time grouping');
        check(array_sum(array_column($bars,'value'))===array_sum(array_column($s['rows'],'value')),'grouping conserves all requests');
        check($bars[0]['value']>=$bars[1]['value'],'highest count includes valid ties');$winners[]=$bars[0]['region'];
    }
    $seen[]=$s['kind'];$pools[]=json_encode($s['rows']);
    $bars=sorted_bars($s,$s['kind']);$move=array('type'=>'dispatch','view'=>$s['kind'],'bar'=>$bars[0]['id']);
    $before=$s;$s=Game::apply($s,$move);check($s['feedback']['correct']&&$s['score']>$before['score'],'accurate dispatch earns score');
    check($s['phase']==='review'&&$s['rows']===$before['rows'],'result retains the same observations');
    check($s['feedback']['selected']['value']===array_sum(array_column($s['feedback']['selected']['parts'],'value')),'feedback includes actual source sum');
    rejects(fn()=>Game::apply($s,$move));
    $score=$s['score'];$s=Game::apply($s,array('type'=>'next'));check($s['score']===$score&&$s['phase']==='choose','next mission does not award extra points');
}
check(count(array_unique($pools))===18&&count(array_unique($seen))===3,'fresh observations across three mission types');
$s=Game::create('practice-bar');$s['unranked']=false;$bars=sorted_bars($s,'hour');$s['score']=200;$s['combo']=3;
$bad=Game::apply($s,array('type'=>'dispatch','view'=>'hour','bar'=>$bars[count($bars)-1]['id']));
check($bad['score']===100&&$bad['combo']===0&&!$bad['feedback']['correct'],'wrong dispatch deducts and resets streak');
check($bad['rescued']===$bars[count($bars)-1]['value'],'support count is not fabricated score');
rejects(fn()=>Game::apply($s,array('type'=>'next')));
rejects(fn()=>Game::apply($s,array('type'=>'dispatch','view'=>'total','bar'=>'동쪽|오전 전체')));
rejects(fn()=>Game::apply($s,array('type'=>'dispatch','view'=>'hour','bar'=>'없는 지역|09시')));
rejects(fn()=>Game::apply($s,array('type'=>'dispatch','view'=>'hour')));
$s['deadline']=microtime(true)-1;$late=Game::apply($s,array('type'=>'dispatch','view'=>'hour','bar'=>$bars[0]['id']));
check($late['status']==='won'&&$late['score']===200&&$late['turn']===0,'deadline prevents late scoring');
rejects(fn()=>Game::apply($late,array('type'=>'finish')));
$s=Game::create('practice-bar');$s['deadline']=microtime(true)-1;
check(Game::view($s)['remaining']===null&&Game::view($s)['unranked'],'practice has no game timer');
$bars=sorted_bars($s,'hour');$s=Game::apply($s,array('type'=>'dispatch','view'=>'hour','bar'=>$bars[0]['id']));
check($s['status']==='playing'&&$s['score']===0&&$s['completed']===1,'practice retains success without competitive points');
$s=Game::apply($s,array('type'=>'finish'));check($s['status']==='won','practice can finish alone');
check(EduGames\Play\Catalog::base_version('data-visualization')===Game::CONTENT_VERSION,'solo scores isolated from old role-play records');
echo "$checks solo rescue checks passed\n";
