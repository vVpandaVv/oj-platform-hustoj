<?php
declare(strict_types=1);
require __DIR__.'/research.php';

use CodingPanda\OjResearch\Application\{ResearchService,PilotService};
use CodingPanda\OjResearch\Contract\{ResearchLearningGateway,PilotResources};
use CodingPanda\OjResearch\Domain\{PilotProtocol as P,PilotReport};
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Modules\AI\Application\FeedbackInputFactory;

$startChecks=$checks;
$store=new ResearchMemoryStore();$clock=new ResearchClock();$groups=new ResearchGroups();$features=new ResearchFeatures();$router=new ResearchRouter();
$learning=new class implements ResearchLearningGateway,PilotResources {
    public ResearchLearning $levels;public array $judged=[];
    public function __construct(){$this->levels=new ResearchLearning();}
    public function levelSnapshot(int $userId):array{return $this->levels->levelSnapshot($userId);}
    public function problemHashes(int $actorUserId,array $problemIds):array{return $this->levels->problemHashes($actorUserId,$problemIds);}
    public function submissions(int $userId,array $problemIds,string $from,string $until):array{return array_values(array_filter($this->judged[$userId]??[],static fn($x)=>in_array($x['problem_id'],$problemIds,true)));}
    public function guides():array{return array_combine(['beginner','intermediate','advanced','insufficient'],array_map(static fn($x)=>['key'=>$x.'-guide','revision'=>2,'instructions'=>$x.' 확인할 내용'],['beginner','intermediate','advanced','insufficient']));}
    public function problems(int $actor,array $ids):array{return array_combine($ids,array_map(static fn($id)=>['id'=>$id,'title'=>'문제 '.$id,'difficulty'=>2,'topics'=>['loop'],'hash'=>hash('sha256','problem')],$ids));}
};
$service=new ResearchService($store,$groups,$learning,new ResearchUsers(),new ResearchPermissions(),$features,$router,$clock);
$pilot=new PilotService($service,$store,$learning,$learning,$features,$router,$clock);$service->attachPilot($pilot);
$input=['title'=>'두 집단 시범 연구 <script>alert(1)</script>','groups'=>[1,2],'practice'=>[1000,1001],'pretest'=>[2000,2001],'posttest'=>[3000,3001],
    'lessons_count'=>1,'notes'=>['consent'=>'합성 시험 자료 동의','analysis'=>'사전 점수를 고려하여 분석'], 'request_limit'=>1];
researchDenied(fn()=>$pilot->save(10,0,0,$input),'Student cannot create research');
researchDenied(fn()=>P::validate(array_replace($input,['posttest'=>[1000]])),'Practice and common tests are separate');
researchDenied(fn()=>P::validate(array_replace($input,['support'=>['pretest'=>['A'=>'level','B'=>'off']]])),'Pre-allocation test has common support');
$id=$pilot->save(1,0,0,$input);$rev=fn()=>$store->study($id)['revision'];
$move=fn($phase,$reason='')=>$pilot->transition(1,$id,$rev(),$phase,$reason);
$pilot->material(1,$id,$rev(),1000,['answer'=>'print(1)','explanation'=>'반복 범위','errors'=>'경계','version'=>'v1','reviewed'=>true]);
researchDenied(fn()=>$pilot->save(1,$id,0,$input),'Stale settings cannot overwrite');
$pilot->enroll(1,$id,$rev(),[10,11,12,13]);$frozen=$store->participants($id)[0]['baseline'];
researchAssert($store->study($id)['data']['guides']['intermediate']['revision']===2,'Selected existing guide version frozen');
researchDenied(fn()=>$pilot->save(1,$id,$rev(),$input),'Frozen protocol is immutable');
researchAssert(count($learning->levels->calls)===4,'Exactly one existing snapshot per enrollee');
$move('pretest');
researchDenied(fn()=>$pilot->bindSubmission(10,2000,100),'Explicit problem start required');
researchDenied(fn()=>$pilot->timer(99,$id,$rev(),2000,'start'),'Nonparticipant cannot start assessment');
researchDenied(fn()=>$pilot->timer(10,$id,$rev(),1000,'start'),'Practice is not a pretest');
$submissionNumber=100;
$submit=function(int $user,int $problem,int $code,int $seconds=30)use($pilot,$id,$rev,$clock,$learning,&$submissionNumber):int {
    $pilot->timer($user,$id,$rev(),$problem,'start');$clock->time=$clock->time->modify('+'.$seconds.' seconds');$n=++$submissionNumber;
    $pilot->bindSubmission($user,$problem,$n);
    $learning->judged[$user][$n]=['submission_id'=>$n,'problem_id'=>$problem,'result_code'=>$code,'pass_rate'=>$code===4?1:0.5,'processing'=>false,'language_id'=>0,'submitted_at'=>$clock->time->format('Y-m-d H:i:s')];return $n;
};
$submit(10,2000,6);$pilot->timer(10,$id,$rev(),2000,'pause');$clock->time=$clock->time->modify('+90 seconds');$n=$submit(10,2000,4,20);
$bound=array_values(array_filter($store->records($id),fn($x)=>$x['kind']==='pilot.submission'&&$x['data']['submission_id']===$n))[0];
researchAssert($bound['data']['elapsed_seconds']===50,'Pause excluded from active solve time');
$pilot->finish(10,$id,$rev());
researchDenied(fn()=>$pilot->bindSubmission(10,2001,999),'Final assessment completion prevents more submissions');
$submit(11,2000,6);$pilot->finish(11,$id,$rev());$pilot->finish(12,$id,$rev());
$pilot->collect(1,$id,$rev());
$report=fn()=>PilotReport::build($store->study($id),$store->participants($id),$store->records($id));
$r=$report();researchAssert((float)$r['rows'][0]['pretest_score']===50.0,'One accepted problem of two equals 50');
researchAssert($r['rows'][0]['pretest_seconds']===50,'First accepted submission uses active time');
researchAssert((float)$r['rows'][2]['pretest_score']===0.0&&$r['rows'][3]['pretest_score']===null,'Confirmed unattempted is zero; absent final confirmation is missing');
researchAssert((float)$r['rows'][0]['pretest_errors']===100.0,'First-attempt error denominator preserved');
$beforeEvents=count($store->records($id));$pilot->collect(1,$id,$rev());
researchAssert(count($store->records($id))===$beforeEvents+1,'Repeated synchronization does not duplicate judged outcomes');
$move('allocation_ready');$pilot->allocate(1,$id,$rev());$people=array_column($store->participants($id),null,'arm');
researchAssert(count(array_unique(array_column($store->participants($id),'arm')))===2,'Two groups allocated');
researchDenied(fn()=>$pilot->allocate(1,$id,$rev()),'Allocation cannot be rerolled');
$move('practice');$a=$people['A']['user_id'];$b=$people['B']['user_id'];
$aiId=$submit($a,1000,6);$otherId=$submit($b,1000,6);
$aiSubmission=fn($user,$sid)=>new AiFeedbackSubmission($sid,1000,$user,0,6,0,0,'2026-09-10 00:00:00','print(1)','','',true,false);
$context=$service->context($aiSubmission($a,$aiId));
researchAssert($context['policy']==='level_adaptive'&&$context['instructions']==='intermediate 확인할 내용','A uses its actual selected level guide');
researchAssert($context['learning_materials']['reference_code']==='print(1)','Only approved materials enter feedback context');
researchDenied(fn()=>$service->context($aiSubmission($b,$otherId)),'B AI disabled at server policy boundary');
researchAssert($pilot->recommendationIds($b)===[]&&count($pilot->recommendationIds($a))===2,'B recommendation disabled; A constrained to practice pool');
$inputFactory=(new ReflectionClass(FeedbackInputFactory::class))->newInstanceWithoutConstructor();
$modelInput=$inputFactory->research([],$context);
researchAssert($modelInput['learner_profile']['level']==='intermediate'&&isset($modelInput['reviewed_learning_materials']),'Model input has selected level and reviewed grounding');
$service->record($context,'generated',['submission_id'=>$aiId,'input_hash'=>'hash','content'=>[]]);
$newAi=$submit($a,1000,6);
researchDenied(fn()=>$service->context($aiSubmission($a,$newAi)),'Configured request limit enforced');
$move('paused','시험 일시 중단');
researchDenied(fn()=>$service->record($context,'delivered',['submission_id'=>$aiId,'input_hash'=>'hash']),'Delayed feedback blocked after pause');
researchDenied(fn()=>$pilot->bindSubmission($a,1000,999),'Paused study cannot submit');
researchDenied(fn()=>$service->assertAiAccess($a),'Addon generic AI blocked during active study');
$move('practice','시험 재개');$move('between_lessons');$move('posttest');
$submit(10,3000,4);$submit(10,3001,4);$pilot->finish(10,$id,$rev());
$pilot->survey(10,$id,$rev(),['5','4','3']);
researchDenied(fn()=>$pilot->survey(11,$id,$rev(),['6','4','3']),'Survey scale enforced');
$pilot->collect(1,$id,$rev());$r=$report();
researchAssert((float)$r['rows'][0]['gain']===50.0,'Matched pre/post change computed from real scores');
researchAssert((float)$r['rows'][0]['error_reduction']===100.0,'Error reduction formula uses baseline denominator');
researchAssert($r['rows'][0]['satisfaction']===4,'Survey mean is observed value');
researchAssert($r['rows'][1]['satisfaction']===null,'Survey nonresponse stays missing');
researchAssert($store->participants($id)[0]['baseline']===$frozen,'Existing level snapshot remains unchanged');
$md=PilotReport::markdown($store->study($id),$r);
researchAssert(str_contains($md,'교육자료 개발')&&str_contains($md,'표 5.')&&!str_contains($md,'학생10'),'Manuscript sections and pseudonymous result export');
$move('completed');$service->withdraw(1,$id,$rev(),$store->participants($id)[0]['id'],'시험 철회');
$r=$report();researchAssert(count($r['rows'])===3&&$r['withdrawn']===1,'Withdrawn data excluded from report');
$move('archived');researchAssert($store->activeForUser(11)===null,'Archive releases participant policies');
researchAssert($pilot->recommendationIds(11)===null,'Ordinary recommendations restored after archive');
$output=getenv('OJ_PILOT_UI_OUTPUT');
if(is_string($output)&&$output!==''){
    $urls=new class implements \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider{public function actionUrl():string{return '/wp-admin/admin-post.php';}public function ajaxUrl():string{return '/wp-admin/admin-ajax.php';}};
    $security=new class implements \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity{public function hiddenFields(string $key):string{return '<input type="hidden" name="action" value="'.$key.'">';}public function verify(string $key,\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $r):bool{return false;}};
    $ui=new \CodingPanda\OjResearch\Presentation\PilotPages($service,$pilot,$urls,$security);
    $snapshots=['index'=>$ui->index(1),'new'=>$ui->edit(1),'manage'=>$ui->show(1,$id),'report'=>$ui->report(1,$id)];
    foreach($snapshots as$name=>$html){file_put_contents($output.'/'.$name.'.html','<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="ui.css"><body class="ojp-app"><main class="ojp-public-main"><div class="ojp-shell">'.$html.'</div></main></body></html>');}
    copy(dirname(__DIR__,3).'/oj-platform/resources/ui/css/oj-ui-system.css',$output.'/ui.css');
}
echo 'Pilot research passed: '.($checks-$startChecks)." assertions; explicit sessions, two groups, server policies, observed report values\n";
