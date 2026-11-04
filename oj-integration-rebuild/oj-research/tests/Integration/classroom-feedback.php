<?php
declare(strict_types=1);
// Exercise the actual OJ queue and worker with the classroom protocol, not only its context builder.
require __DIR__.'/research-feedback.php';

$startClassroomFeedback=$checks;
$model->after=null;$model->revision=1;$model->requests=[];
$classStore=new ResearchMemoryStore();$classLearning=new ResearchLearning();$classClock=new ResearchClock();
$classFeatures=new ResearchFeatures();
$classResearch=new \CodingPanda\OjResearch\Application\ResearchService($classStore,new ResearchGroups(),$classLearning,new ResearchUsers(),new ResearchPermissions(),$classFeatures,$model,$classClock);
$classResources=new class implements \CodingPanda\OjResearch\Contract\PilotResources {
    public function guides():array{return array_fill_keys(['beginner','intermediate','advanced','insufficient'],['key'=>'frozen-classroom-guide','revision'=>1,'instructions'=>'합성 수준별 지침: 해당 수준에 맞춰 설명한다.']);}
    public function problems(int $actor,array $ids):array{return array_combine($ids,array_map(static fn($id)=>['id'=>$id,'title'=>'합성 문제','hash'=>hash('sha256','problem')],$ids));}
};
$classPilot=new \CodingPanda\OjResearch\Application\PilotService($classResearch,$classStore,$classLearning,$classResources,$classFeatures,$model,$classClock);$classResearch->attachPilot($classPilot);
$commonPrompt='합성 공통 지침: 수준을 참조하지 말고 오류를 확인할 질문을 한 개 제시한다.';
$classId=$classPilot->save(1,0,0,['workflow'=>\CodingPanda\OjResearch\Domain\ClassroomProtocol::WORKFLOW,'title'=>'합성 공통 프롬프트 연결 시험','groups'=>[1],'practice'=>[1000],'design'=>'adaptation','notes'=>['consent'=>'합성 시험 절차'],'common_instructions'=>$commonPrompt]);
$classRevision=fn()=>$classStore->study($classId)['revision'];
$classPilot->enroll(1,$classId,$classRevision(),[10,11]);$classPilot->transition(1,$classId,$classRevision(),'practice');
$classPeople=array_column($classStore->participants($classId),null,'arm');
$classSubmissions=new AiFeedbackTestSubmissions(new \CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission(950,1000,$classPeople['B']['user_id'],0,6,0,0,'2026-09-10 00:00:00','print(1)','','',true,false));
$classFeedback=new AiFeedbackTestRepository();$classJobs=new AiFeedbackTestJobs();
$classFeedbackJobs=new \CodingPanda\OjPlatform\Modules\AI\Application\SubmissionFeedbackJobService($classSubmissions,$problems,$classFeedback,$guides,$settings,$inputs,$levelGuard,new AiFeedbackTestFeatures(),$classJobs,$model,$classResearch);
$classWorker=new \CodingPanda\OjPlatform\Modules\AI\Application\SubmissionAiFeedbackJobHandler(new ResearchUsers(),$classSubmissions,$problems,$unusedLevels,$guides,$settings,$inputs,new \CodingPanda\OjPlatform\Modules\AI\Application\FeedbackOutputValidator(),$classFeedback,new \CodingPanda\OjPlatform\Modules\AI\Application\SubmissionFeedbackReusePolicy(),$classFeedbackJobs,$model,$classClock);
$classRun=function(string $arm,int $sid)use($classPilot,$classId,$classRevision,$classPeople,$classSubmissions,$classFeedbackJobs,$classJobs,$classWorker){
    $user=$classPeople[$arm]['user_id'];$classPilot->timer($user,$classId,$classRevision(),1000,'start');$classPilot->bindSubmission($user,1000,$sid);
    $classSubmissions->submission=new \CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission($sid,1000,$user,0,6,0,0,'2026-09-10 00:00:00','print(1)','','',true,false);
    $classFeedbackJobs->enqueue($user,'user'.$user,$sid);$payload=$classJobs->call['targets'][0]->payload();
    $item=new \CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem(new \CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem(1,1,'submission_ai_feedback_generate','submission',(string)$sid,null,null,$payload['base_input_hash'],new \CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference('draft:v1:aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'),1,3,$user,str_repeat('a',64)),$payload);
    return $classWorker->handle($item);
};
researchAssert($classRun('B',950)->outcome()==='succeeded','Classroom common mode runs through real queue handler');
researchAssert($model->requests[0]['response_policy']['instructions']===$commonPrompt,'Saved common prompt reaches AiTaskRouter execute request verbatim');
researchAssert(!isset($model->requests[0]['learner_profile']),'Common prompt receives no learner-level profile');
$providerMessages=(new \CodingPanda\OjPlatform\Modules\Settings\Application\AiTaskPromptFactory())->messages(new \CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest('submission_feedback','1.0',$model->requests[0]));
$providerInput=json_decode($providerMessages[count($providerMessages)-1]['content'],true,512,JSON_THROW_ON_ERROR);
researchAssert($providerInput['input']['response_policy']['instructions']===$commonPrompt,'Provider message serialization preserves the common prompt');
researchAssert($classRun('A',951)->outcome()==='succeeded','Classroom adaptive mode runs through real queue handler');
researchAssert($model->requests[1]['response_policy']['instructions']==='합성 수준별 지침: 해당 수준에 맞춰 설명한다.'&&isset($model->requests[1]['learner_profile']),'Adaptive mode uses frozen level guide instead of common prompt');
researchAssert($classFeedback->find(950)!==null&&$classFeedback->find(951)!==null,'Both responses persist with their actual intervention input');
echo 'Classroom worker prompt passed: '.($checks-$startClassroomFeedback)." assertions; actual queue and request capture, no external AI call\n";
