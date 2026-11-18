<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EduGames\Arcade\Dance_Rules as D;
$checks=0;
function dance_check(bool $v,string $label):void{global $checks;$checks++;if(!$v)throw new RuntimeException($label);}
foreach(['standard','challenge'] as $difficulty){
    $routines=D::routines('composition-test',$difficulty);$s=D::create('test','composition-test',$difficulty,1000);$now=2000;
    dance_check(count($routines)===8&&count(array_unique(array_map('json_encode',$s['targets'])))===8,'eight distinct routines '.$difficulty);
    foreach($routines as $i=>$r){
        $program=$r['program'];$expanded=D::expand($program);$target=$s['targets'][$i];
        dance_check(count(array_filter($program,fn($b)=>$b['repeat']===1))>=1&&count(array_filter($program,fn($b)=>$b['repeat']>1))>=2,'sequential bridge and two loops '.$r['id']);
        dance_check($expanded['steps']===$target&&count($target)<=64&&$expanded['cost']<count($target),'actual expansion and command savings '.$r['id']);
        $periodic=false;for($p=1;$p<count($target);$p++)if(count($target)%$p===0&&array_merge(...array_fill(0,intdiv(count($target),$p),array_slice($target,0,$p)))===$target){$periodic=true;break;}
        dance_check(!$periodic,'not just one repeating body '.$r['id']);
        $run=D::apply($s,['type'=>'run','program'=>$program],$now);$flat=D::apply($s,['type'=>'run','program'=>[['commands'=>$target,'repeat'=>1]]],$now);$at=$run['run']['until'];
        $result=D::apply($run,['type'=>'commit'],$at);$flat=D::apply($flat,['type'=>'commit'],$at);
        dance_check($result['lastResult']['correct']&&$flat['lastResult']['correct']&&$result['score']>$flat['score'],'correct flat sequence earns less than compressed multi-loop solution '.$r['id']);
        $view=D::view($result,$at);dance_check(!isset($view['program'],$view['targets'],$view['titles'])&&$view['targetTitle']===$r['title'],'only active title and target disclosed');
        $s=$result;$now=$at+1000;if($i<7)$s=D::apply($s,['type'=>'next'],$now);
    }
    dance_check($s['status']==='finished'&&$s['correct']===8,'all mixed routines can finish '.$difficulty);
}
$legacy=D::create('old','seed','standard',1000);$legacy['ruleset']='dance-v1';$legacy['targets']=array_fill(0,8,['left','right','left','right']);unset($legacy['titles']);
$legacy=D::apply($legacy,['type'=>'run','program'=>[['commands'=>['left','right'],'repeat'=>2]]],2000);$legacy=D::apply($legacy,['type'=>'commit'],$legacy['run']['until']);
dance_check(D::view($legacy,5000)['ruleset']==='dance-v1'&&$legacy['correct']===1,'existing single-loop run continues without migration');
echo "$checks mixed dance routine, scoring and legacy compatibility checks passed\n";
