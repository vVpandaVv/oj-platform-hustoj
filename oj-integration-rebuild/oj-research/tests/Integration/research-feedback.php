<?php
declare(strict_types=1);

// Reuse established fixtures and run their original assertions as regression prerequisites.
require dirname(__DIR__, 3) . '/oj-platform/tests/Unit/ai-feedback.php';
require dirname(__DIR__) . '/Unit/research.php';

use CodingPanda\OjPlatform\Modules\AI\Application\{AiFeedbackSettingsService, FeedbackInputFactory, FeedbackOutputValidator, LearnerLevelService, SubmissionAiFeedbackJobHandler, SubmissionFeedbackJobService, SubmissionFeedbackReusePolicy};
use CodingPanda\OjPlatform\Modules\AI\Contract\LearnerLevelRefresher;
use CodingPanda\OjPlatform\Modules\AI\Domain\LearnerLevelProfile;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\{ClaimedJobItem, FormDraftReference, JobWorkItem};
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblem;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjResearch\Application\ResearchService;
use CodingPanda\OjPlatform\Shared\Ai\{AiTaskRouter, AiTaskAssignmentSnapshot, AiTaskRequest, AiTaskResponse};

$assertionsBefore = $checks;
$store = new ResearchMemoryStore(); $groups = new ResearchGroups(); $clock = new ResearchClock(); $learning = new ResearchLearning();
$model = new class implements AiTaskRouter {
    public array $requests = []; public ?Closure $after = null; public int $revision = 1;
    public function canRoute(string $taskType, bool $requiresSubmissionSource = false): bool { return true; }
    public function snapshot(string $taskType, bool $requiresSubmissionSource = false): AiTaskAssignmentSnapshot { return new AiTaskAssignmentSnapshot($taskType,$this->revision,'research.model',null,str_repeat('a',64)); }
    public function execute(AiTaskAssignmentSnapshot $assignment, AiTaskRequest $request): AiTaskResponse {
        $this->requests[] = $request->input();
        if ($this->after !== null) { ($this->after)(); }
        return new AiTaskResponse(['result_understanding'=>'경계 조건을 확인하세요.','step_hints'=>['입력을 한 단계씩 확인하세요.'],'review_question'=>'반복 횟수는 얼마인가요?'],'research.model','test','test-model',str_repeat('a',64));
    }
};
$service = new ResearchService($store,$groups,$learning,new ResearchUsers(),new ResearchPermissions(),new ResearchFeatures(),$model,$clock);
$studyId = $service->create(1,$input);
$rev = fn()=> $store->study($studyId)['revision'];
$move = fn(string $next)=> $service->transition(1,$studyId,$rev(),$next);
$service->enroll(1,$studyId,$rev(),[10,11]); $move('pretest'); $move('allocation_ready');
foreach($store->participants($studyId) as $p) { $service->missing(1,$studyId,$rev(),$p['id'],'pretest','absent'); }
$service->allocate(1,$studyId,$rev()); $move('practice'); $move('feedback');
$people = array_column($store->participants($studyId),null,'arm');
$problem = new LearningProblem(1000,'반복문','수열을 출력하세요.','정수','수열','',1,['basic'],20,30,true,hash('sha256','problem'));
$submissions = new AiFeedbackTestSubmissions(new AiFeedbackSubmission(900,1000,$people['B']['user_id'],0,6,0,0,'2026-09-10 00:00:00','print(1)','','',true,false));
$feedback = new AiFeedbackTestRepository(); $jobs = new AiFeedbackTestJobs(); $guides = new AiFeedbackTestGuides();
$settings = new AiFeedbackSettingsService(new AiFeedbackTestSettings(['enabled'=>true,'result_scope'=>'all','active_guides'=>[]]));
$levelGuard = new class implements LearnerLevelRefresher {
    public function refresh(int $userId): LearnerLevelProfile { throw new RuntimeException('Research must reuse frozen original level; never recalculate here'); }
};
$inputs = new FeedbackInputFactory(); $problems = new AiFeedbackTestProblems($problem);
$feedbackJobs = new SubmissionFeedbackJobService($submissions,$problems,$feedback,$guides,$settings,$inputs,$levelGuard,new AiFeedbackTestFeatures(),$jobs,$model,$service);
// This legacy constructor dependency is unused: profile access goes through feedbackJobs and the throwing guard above.
$unusedLevels = (new ReflectionClass(LearnerLevelService::class))->newInstanceWithoutConstructor();
$worker = new SubmissionAiFeedbackJobHandler(new ResearchUsers(),$submissions,$problems,$unusedLevels,$guides,$settings,$inputs,new FeedbackOutputValidator(),$feedback,new SubmissionFeedbackReusePolicy(),$feedbackJobs,$model,$clock);
$enqueue = function()use($feedbackJobs,$submissions,$jobs): JobWorkItem {
    $s=$submissions->submission;
    $feedbackJobs->enqueue($s->ownerUserId(),'user'.$s->ownerUserId(),$s->submissionId());
    $payload=$jobs->call['targets'][0]->payload();
    return new JobWorkItem(new ClaimedJobItem(1,1,'submission_ai_feedback_generate','submission',(string)$s->submissionId(),null,null,$payload['base_input_hash'],new FormDraftReference('draft:v1:aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'),1,3,$s->ownerUserId(),str_repeat('a',64)),$payload);
};
$item=$enqueue(); $result=$worker->handle($item);
researchAssert($result->outcome()==='succeeded','Actual comparison worker succeeds');
researchAssert(!array_key_exists('learner_profile',$model->requests[0]),'Actual comparison model input omits entire profile');
researchAssert(!isset($model->requests[0]['assigned_arm'])&&!isset($model->requests[0]['study_id']),'Model receives no research identity or condition');
researchAssert($feedback->find(900)!==null,'Worker saves matching condition token');
$s=$submissions->submission;
researchAssert($feedbackJobs->findCurrentForActor($s->ownerUserId(),'user'.$s->ownerUserId(),900)!==null,'Current research feedback can be delivered');
$feedbackJobs->findCurrentForActor($s->ownerUserId(),'user'.$s->ownerUserId(),900);
researchAssert(count(array_filter($store->records($studyId),fn($r)=>$r['kind']==='feedback.delivered'))===1,'Repeated retrieval records one delivery receipt');
researchAssert($worker->handle($item)->resultCode()==='ai_feedback.reused'&&count($model->requests)===1,'Same input reuses result without second model call');
$submissions->submission=new AiFeedbackSubmission(901,1000,$people['A']['user_id'],0,6,0,0,'2026-09-10 00:00:00','print(2)','','',true,false);
$result=$worker->handle($enqueue());
researchAssert($result->outcome()==='succeeded'&&$model->requests[1]['learner_profile']['level']==='intermediate','Actual adaptive worker uses existing frozen level');
researchAssert($model->requests[0]['response_policy']===$model->requests[1]['response_policy'],'Actual workers share the same common response rules');
researchAssert(count($service->feedbackSamples(1,$studyId))===2 && $service->feedbackSamples(1,$studyId)[0]['content']['review_question']!== '', 'Authorized teacher can review actual stored feedback for intervention fidelity');
researchDenied(fn()=> $service->feedbackSamples(2,$studyId), 'Blinded evaluator cannot inspect condition-specific AI samples');
$stale=$enqueue(); $move('check');
researchAssert($worker->handle($stale)->outcome()==='needs_attention'&&count($model->requests)===2,'Queued feedback cannot execute after independent check starts');
researchDenied(fn()=> $feedbackJobs->findCurrentForActor($people['B']['user_id'],'user'.$people['B']['user_id'],900),'Saved response cannot be read during independent check');
$move('between_lessons');
researchAssert($feedbackJobs->findCurrentForActor($people['A']['user_id'],'user'.$people['A']['user_id'],901)===null,'Research cache stays hidden in ordinary AI phase');
// Separate study fixture resumes feedback solely to exercise mid-execution phase change and drift guards.
$store->studyRows[$studyId]['status']='feedback';$store->studyRows[$studyId]['data']['phase']='feedback';
$submissions->submission=new AiFeedbackSubmission(902,1000,$people['A']['user_id'],0,6,0,0,'2026-09-10 00:00:00','print(3)','','',true,false);
$model->revision=2;researchDenied($enqueue,'Frozen model routing rejects drift before queueing');$model->revision=1;
$item=$enqueue();$model->after=fn()=> $move('check');
$result=$worker->handle($item);
researchAssert($result->outcome()==='needs_attention'&&$feedback->find(902)===null,'Mid-execution phase change prevents saving or exposing generated content');
researchAssert(count(array_filter($store->records($studyId),fn($r)=>$r['kind']==='feedback.generated'))===2,'Late rejected result never becomes generated research exposure');
echo 'Research worker integration passed: '.($checks-$assertionsBefore)." assertions; actual service, queue, handler and cache, no external model calls\n";
