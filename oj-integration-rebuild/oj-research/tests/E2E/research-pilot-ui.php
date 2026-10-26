<?php
declare(strict_types=1);
require dirname(__DIR__).'/Unit/research-pilot.php';
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\{ActionUrlProvider,ActionSecurity,HttpRequest};
use CodingPanda\OjResearch\Presentation\{PilotPages,PilotActions};
$uiChecks=$checks;
$urls=new class implements ActionUrlProvider {public function actionUrl():string{return '/wp-admin/admin-post.php';}public function ajaxUrl():string{return '/wp-admin/admin-ajax.php';}};
$security=new class implements ActionSecurity {public function hiddenFields(string $key):string{return '<input name="action" value="'.$key.'"><input name="_wpnonce" value="test">';}public function verify(string $key,HttpRequest $r):bool{return false;}};
$ui=new PilotPages($service,$pilot,$urls,$security);$actions=new PilotActions($service,$pilot);
$management=$ui->show(1,$id);$paper=$ui->report(1,$id);
researchAssert(str_contains($management,'지금 할 일')&&str_contains($management,'ojp-research-card'),'Management shows current action and cards');
researchAssert(!str_contains($management,'표 5.')&&!str_contains($management,'교육자료 개발 및 데이터 구축'),'Manuscript tables are not the management view');
researchAssert(str_contains($paper,'교육자료 개발 및 데이터 구축')&&str_contains($paper,'수업 관리로 돌아가기'),'Report has manuscript sections and a return to operations');
researchAssert(str_contains($paper,'&lt;script&gt;')&&!str_contains($paper,'<script>'),'Report title escaped');
researchDenied(fn()=>$ui->report(10,$id),'Student cannot read report or roster');
foreach(['markdown','html','csv','json']as$format){
    $r=$actions->handle(1,new HttpRequest('POST','/wp-admin/admin-post.php',[],['operation'=>'pilot.export','study_id'=>$id,'revision'=>$rev(),'format'=>$format]));
    researchAssert($r->status()===200&&strlen($r->body())>200,'Actual export body generated for '.$format);
    researchAssert(!str_contains($r->body(),'학생10')&&!str_contains($r->body(),'user10'),'No student identity in '.$format);
}
$r=$actions->handle(1,new HttpRequest('POST','/wp-admin/admin-post.php',[],['operation'=>'pilot.export','study_id'=>$id,'format'=>'csv']));
researchAssert(str_contains($r->body(),'pretest_score')&&str_contains($r->body(),'posttest_score')&&str_contains($r->body(),'error_reduction'),'CSV includes actual analysis columns');
researchDenied(fn()=>$actions->handle(10,new HttpRequest('POST','/wp-admin/admin-post.php',[],['operation'=>'pilot.export','study_id'=>$id,'format'=>'csv'])),'Forged export actor cannot access report');
researchAssert(str_contains($ui->edit(1),'어떤 차이를 확인할까요?')&&str_contains($ui->edit(1),'value="adaptation" selected'),'New management UI defaults to adaptation comparison');
researchAssert(str_contains($paper,'분석 보류')&&str_contains($paper,'수준 간 피드백 효과 차이'),'Sparse data report keeps inferential results unavailable');
researchAssert(!str_contains($management,'표 9.')&&str_contains($management,'비교집단과 얼마나 달라졌나요?'),'Management has effect cards while paper keeps detailed tables');
$json=$actions->handle(1,new HttpRequest('POST','/',[],['operation'=>'pilot.export','study_id'=>$id,'format'=>'json']));
$evidence=json_decode($json->body(),true,512,JSON_THROW_ON_ERROR);
researchAssert(isset($evidence['analysis']['version'],$evidence['rows'][0]['class_code'])&&!isset($evidence['rows'][0]['user_id']),'Evidence has results and pseudonymous classes');

// Exercise new arms through the actual submission gate, not just form defaults.
$adaptInput=array_replace($input,['title'=>'합성 맞춤 비교 검증','design'=>'adaptation']);
$adaptId=$pilot->save(1,0,0,$adaptInput);$adaptRev=fn()=>$store->study($adaptId)['revision'];
$pilot->enroll(1,$adaptId,$adaptRev(),[10,11,12,13]);
foreach(['pretest','allocation_ready']as$phase){$pilot->transition(1,$adaptId,$adaptRev(),$phase);}
$pilot->allocate(1,$adaptId,$adaptRev());$pilot->transition(1,$adaptId,$adaptRev(),'practice');
$adaptPeople=array_column($store->participants($adaptId),null,'arm');
foreach(['A','B']as$i=>$arm){$user=$adaptPeople[$arm]['user_id'];$sid=9100+$i;
    $pilot->timer($user,$adaptId,$adaptRev(),1000,'start');$pilot->bindSubmission($user,1000,$sid);
    $ctx=$service->context($aiSubmission($user,$sid));$payload=$inputFactory->research(['learner_profile'=>['level'=>'advanced']],$ctx);
    researchAssert($arm==='A'?isset($payload['learner_profile']):!isset($payload['learner_profile']),'Only adaptive arm transmits existing level data');
    researchAssert($pilot->recommendationIds($user)===[1000,1001],'Both arms receive the same problem order');
}
$pilot->transition(1,$adaptId,$adaptRev(),'between_lessons');$pilot->transition(1,$adaptId,$adaptRev(),'posttest');
$user=$adaptPeople['B']['user_id'];$pilot->timer($user,$adaptId,$adaptRev(),3000,'start');$pilot->bindSubmission($user,3000,9200);
researchDenied(fn()=>$service->context(new \CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission(9200,3000,$user,0,6,0,0,'2026-09-13 00:00:00','print(1)','','',true,false)),'No-AI posttest enforced for common-AI comparison students');
echo 'Pilot report UI passed: '.($checks-$uiChecks)." assertions; separate operations/report, escaped text and authorized exports\n";
