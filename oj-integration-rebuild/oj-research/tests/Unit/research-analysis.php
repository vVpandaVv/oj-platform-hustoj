<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
use CodingPanda\OjResearch\Domain\{PilotAnalysis,PilotProtocol,PilotRegression,PilotReport,PilotAnalysisReport};

$checks=0;
$assert=function(bool $value,string $message)use(&$checks):void{++$checks;if(!$value){throw new RuntimeException($message);}};
$near=function(float $actual,float $expected,string $message)use($assert):void{$assert(abs($actual-$expected)<1e-7,$message.': '.$actual.' vs '.$expected);};
$denied=function(callable $f,string $message)use($assert):void{try{$f();}catch(DomainException $e){$assert(true,$message);return;}$assert(false,$message);};
$oracle=json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/research-analysis.json'),true,512,JSON_THROW_ON_ERROR);
foreach($oracle['t_critical']as$df=>$v){$near(PilotRegression::critical((int)$df),$v,'Student t quantile df '.$df);}
$protocol=PilotProtocol::validate(['title'=>'합성 검증','groups'=>[1],'design'=>'adaptation']);
$editable=$protocol;$editable['survey_items']=implode("\n",$protocol['survey_items']);
$assert($protocol['arms']['B']['ai']==='common'&&$protocol['arms']['A']['recommendation']==='common','New adaptation preset isolates feedback');
$assert(PilotProtocol::validate(['title'=>'기존','groups'=>[1]])['arms']['B']['ai']==='off','Missing design preserves legacy defaults');
$feedback=PilotProtocol::validate(['title'=>'합성','groups'=>[1],'design'=>'feedback']);
$assert($feedback['arms']['B']['ai']==='off'&&$feedback['arms']['B']['recommendation']==='common','No-AI comparison has identical problem order');
$denied(fn()=>PilotProtocol::validate(array_replace_recursive($editable,['arms'=>['A'=>['recommendation'=>'personal']]])),'Preset prevents mixed recommendation intervention');
$denied(fn()=>PilotProtocol::validate(array_replace_recursive($editable,['support'=>['posttest'=>['B'=>'common']]])),'Preset prevents AI assisted outcome');
$denied(fn()=>PilotProtocol::validate(array_replace_recursive($editable,['arms'=>['B'=>['minutes'=>30]]])),'Preset prevents unequal exposure time');
$denied(fn()=>PilotProtocol::validate(array_replace($editable,['allocation'=>'class','covariance'=>'hc3'])),'Cluster assignment cannot use independent SE');
$assert(PilotProtocol::validate(array_replace_recursive($editable,['design'=>'custom','arms'=>['A'=>['recommendation'=>'personal']]]))['design']==='custom','Custom conditions remain configurable');
foreach($oracle['fixtures']as$fixture){
    $p=array_replace($protocol,['allocation'=>$fixture['allocation']]);$a=PilotAnalysis::build($p,$fixture['rows']);
    $assert($a['primary']['status']==='estimated',$fixture['name'].' primary estimated');
    foreach($fixture['expected']['primary']as$key=>$v){$near($a['primary']['effect'][$key],$v,$fixture['name'].' primary '.$key);}
    $near($a['primary']['standardized_difference'],$fixture['expected']['standardized_difference'],'Standardized adjusted difference');
    $assert($a['heterogeneity']['status']==='estimated','Interaction model estimated');
    foreach($fixture['expected']['levels']as$l=>$e){foreach($e as$key=>$v){$near($a['heterogeneity']['effects'][$l][$key],$v,$l.' '.$key);}}
    foreach($fixture['expected']['differences']as$i=>$d){foreach($d['effect']as$key=>$v){$near($a['heterogeneity']['differences'][$i]['effect'][$key],$v,'Interaction contrast '.$key);}}
    if($fixture['name']==='negative'){$assert(str_contains(PilotAnalysisReport::interpretation($a),'낮게'),'Negative result is never described as an improvement');}
    if($fixture['name']==='uncertain'){$assert(str_contains(PilotAnalysisReport::interpretation($a),'방향을 명확히 판단하기 어렵'),'Uncertain interval does not establish absence of effect');}
}
$rows=$oracle['fixtures'][0]['rows'];
$missing=$rows;$missing[0]['posttest_score']=null;$missing[0]['gain']=null;
$a=PilotAnalysis::build($protocol,$missing);$assert($a['flow']['A']['missing']===1&&$a['primary']['n']===count($rows)-1,'Missing scores not converted to zero or imputed');
$a=PilotAnalysis::build($protocol,[]);$assert($a['primary']['status']==='unavailable','Empty data do not produce effects');
$a=PilotAnalysis::build($protocol,array_slice($rows,0,4));$assert($a['primary']['status']==='unavailable','Tiny samples hold estimates');
$oneArm=array_map(static fn($r)=>array_replace($r,['arm'=>'A']),$rows);
$assert(PilotAnalysis::build($protocol,$oneArm)['primary']['status']==='unavailable','One arm cannot yield comparative effect');
$constant=array_map(static fn($r)=>array_replace($r,['pretest_score'=>50]),$rows);
$assert(PilotAnalysis::build($protocol,$constant)['primary']['status']==='unavailable','Singular model has explicit unavailable state');
$twoClasses=array_map(static fn($r)=>array_replace($r,['class_code'=>$r['arm']]),$rows);
$a=PilotAnalysis::build(array_replace($protocol,['allocation'=>'class']),$twoClasses);
$assert($a['primary']['status']==='unavailable'&&str_contains($a['primary']['reason'],'학급'),'One class per arm is not treated as independent pupils');
$smallLevel=$rows;$smallLevel[0]['level']='insufficient';
$a=PilotAnalysis::build($protocol,$smallLevel);$assert($a['heterogeneity']['status']==='unavailable','Sparse levels do not claim reliable interaction');
$assert($a['levels']['insufficient']['summary']['A']['n']===1,'Insufficient evidence level is not merged with beginner');
echo 'Research effect analysis passed: '.$checks.' assertions; independent '.$oracle['oracle']." reference, presets, missingness, cluster and degeneracy guards\n";
