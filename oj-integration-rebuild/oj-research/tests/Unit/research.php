<?php
declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\AI\Application\FeedbackInputFactory;
use CodingPanda\OjPlatform\Modules\AI\Application\ResearchPolicyBridge;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Groups\Contract\GroupContextAccess;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjResearch\Application\ResearchService;
use CodingPanda\OjResearch\Application\ResearchFeatureDisableGuard;
use CodingPanda\OjResearch\Contract\ResearchLearningGateway;
use CodingPanda\OjResearch\Contract\ResearchRepository;
use CodingPanda\OjResearch\Domain\ResearchProtocol;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskResponse;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskAssignmentSnapshot;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

require_once dirname(__DIR__) . '/bootstrap.php';
$checks = 0;
function researchAssert(bool $condition, string $message): void { global $checks; ++$checks; if (! $condition) { throw new RuntimeException($message); } }
function researchDenied(callable $work, string $message): void { try { $work(); } catch (DomainException | LogicException $e) { researchAssert(true, $message); return; } throw new RuntimeException($message); }

final class ResearchMemoryStore implements ResearchRepository
{
    public array $studyRows = []; public array $people = []; public array $events = [];
    public function transaction(callable $work): mixed {
        $backup = [$this->studyRows, $this->people, $this->events];
        try { return $work(); } catch (Throwable $e) { [$this->studyRows, $this->people, $this->events] = $backup; throw $e; }
    }
    public function create(array $study): int { $id=count($this->studyRows)+1; $this->studyRows[$id]=['id'=>$id]+$study; return $id; }
    public function study(int $id, bool $lock=false): ?array { return $this->studyRows[$id]??null; }
    public function studies(): array { return array_values($this->studyRows); }
    public function updateStudy(array $study,int $expectedRevision):void {
        if ($this->studyRows[$study['id']]['revision'] !== $expectedRevision) { throw new DomainException('revision conflict'); }
        $study['revision']=$expectedRevision+1; $this->studyRows[$study['id']]=$study;
    }
    public function participants(int $studyId):array { return array_values(array_filter($this->people,fn($p)=>$p['study_id']===$studyId)); }
    public function addParticipant(array $p):int {
        foreach($this->people as $existing) { if($existing['active_user_id']!==null&&$existing['active_user_id']===$p['active_user_id']) throw new DomainException('active conflict'); }
        $id=count($this->people)+1; $this->people[$id]=['id'=>$id]+$p; return $id;
    }
    public function updateParticipant(int $id,array $changes):void { $this->people[$id]=array_replace($this->people[$id],$changes); }
    public function activeForUser(int $userId):?array { foreach($this->people as $p) { if($p['active_user_id']===$userId)return $p; } return null; }
    public function append(int $studyId,int $participantId,string $key,string $kind,array $data,string $at):void {
        $key=$studyId.':'.$key;
        if(isset($this->events[$key])) { if($this->events[$key]['data']!==$data)throw new DomainException('idempotency conflict'); return; }
        $this->events[$key]=['id'=>count($this->events)+1,'study_id'=>$studyId,'participant_id'=>$participantId,'kind'=>$kind,'data'=>$data,'created_at'=>$at];
    }
    public function records(int $studyId):array { return array_values(array_filter($this->events,fn($e)=>$e['study_id']===$studyId)); }
}
final class ResearchGroups implements GroupContextAccess
{
    public bool $authorized=true; public array $members=[1=>[10,11,12,13],2=>[13,14,15]];
    public function findById(int $groupId):?LearningGroup { return in_array($groupId,[1,2],true)?new LearningGroup($groupId,'그룹'.$groupId,'group-'.$groupId,'','private','manual',true,null,'normal',null,1,null):null; }
    public function canManage(int $userId,LearningGroup $group):bool { return $this->authorized && $userId===1; }
    public function managedBy(int $userId):array { return $userId===1?[$this->findById(1),$this->findById(2)]:[]; }
    public function learningBy(int $userId):array { return []; }
    public function activeLearnerUserIds(int $groupId):array { return $this->members[$groupId]??[]; }
    public function isActiveMember(int $groupId,int $userId):bool { return in_array($userId,$this->members[$groupId]??[],true); }
    public function relationOf(int $userId,LearningGroup $group):string { return $userId===1?'owner':'none'; }
}
final class ResearchUsers implements WordPressUserGateway
{
    public function currentUser():?UserIdentity{return null;}
    public function findById(int $userId):?UserIdentity{return $userId>0?new UserIdentity($userId,'user'.$userId,'','학생'.$userId):null;}
    public function findByLogin(string $userLogin):?UserIdentity{return $this->findById((int)substr($userLogin,4));}
    public function findByEmail(string $email):?UserIdentity{return null;}
    public function verifyCredentials(string $userLogin,string $password):?UserIdentity{return null;}
    public function establishSession(int $userId,bool $remember):void{}
    public function createUser(string $userLogin,string $email,string $password,string $displayName,string $role):UserIdentity{throw new LogicException('unused');}
    public function updateUser(int $userId,string $email,string $displayName):UserIdentity{throw new LogicException('unused');}
    public function setRoles(int $userId,array $roles):void{}
    public function setPassword(int $userId,string $password):void{}
    public function invalidateSessions(int $userId):void{}
    public function requestPasswordReset(string $userLogin):void{}
    public function resetPasswordWithKey(string $userLogin,string $resetKey,string $newPassword):void{}
    public function deleteUser(int $userId):void{}
    public function findMany(array $userIds):array{return array_map($this->findById(...),$userIds);}
}
final class ResearchPermissions implements Authorization
{
    public function can(int $userId,string $capability,?PermissionContext $context=null):bool{return in_array($userId,[1,2,3],true);}
    public function canAny(int $userId,array $capabilities):bool{return $this->can($userId,'');}
    public function isSuperAdmin(int $userId):bool{return false;}
}
final class ResearchLearning implements ResearchLearningGateway
{
    public array $calls=[]; public string $level='intermediate';
    public function levelSnapshot(int $userId):array {
        $this->calls[]=$userId;
        return ['user_id'=>$userId,'analysis_status'=>$userId===15?'insufficient':'classified','level'=>$userId===15?null:$this->level,
            'proven_tier'=>2,'skill_score'=>$userId===15?null:55.0,'proven_difficulty'=>50.0,'breadth'=>60.0,'recent_challenge'=>50.0,'stability'=>60.0,
            'submission_count'=>20,'attempted_problem_count'=>8,'solved_problem_count'=>7,'recent_sample_count'=>8,'difficulty_coverage'=>100.0,'topic_coverage'=>100.0,
            'policy_version'=>'learner-level-v1','last_submission_id'=>100,'analyzed_at'=>'2026-09-09 00:00:00'];
    }
    public function problemHashes(int $actorUserId,array $problemIds):array{return array_fill_keys($problemIds,hash('sha256','problem'));}
    public function submissions(int $userId,array $problemIds,string $from,string $until):array{return [['submission_id'=>$userId+1000,'problem_id'=>$problemIds[0],'result_code'=>4,'processing'=>false,'language_id'=>0,'submitted_at'=>$from]];}
}
final class ResearchFeatures implements FeatureFlags {public bool $enabled=true;public function isEnabled(string $featureKey):bool{return $this->enabled;}}
final class ResearchClock implements Clock {public DateTimeImmutable $time;public function __construct(){$this->time=new DateTimeImmutable('2026-09-10 00:00:00',new DateTimeZone('UTC'));}public function now():DateTimeImmutable{return $this->time;}}
final class ResearchRouter implements AiTaskRouter
{
    public ?string $fallback=null;
    public function canRoute(string $taskType,bool $requiresSubmissionSource=false):bool{return true;}
    public function snapshot(string $taskType,bool $requiresSubmissionSource=false):AiTaskAssignmentSnapshot{return new AiTaskAssignmentSnapshot($taskType,1,'research.model',$this->fallback,str_repeat('a',64));}
    public function execute(AiTaskAssignmentSnapshot $assignment,AiTaskRequest $request):AiTaskResponse{throw new LogicException('No network calls in research unit tests');}
}

$store=new ResearchMemoryStore();$groups=new ResearchGroups();$learning=new ResearchLearning();$clock=new ResearchClock();$features=new ResearchFeatures();$router=new ResearchRouter();
$service=new ResearchService($store,$groups,$learning,new ResearchUsers(),new ResearchPermissions(),$features,$router,$clock);
$input=['title'=>'피드백 효과 연구','groups'=>[1,2],'raters'=>[2,3],'lessons'=>[['problems'=>[1000],'check'=>'반복문으로 새 문제를 해결하고 설명하세요.']],
    'pretest'=>'사전 독립 과제','posttest'=>'사후 독립 과제','delayed'=>'지연 독립 과제','analysis_plan'=>'원배정 집단 기준 사후 총점; 미응시는 결측으로 보고.','consent_reference'=>'동의문서-v1'];
researchDenied(fn()=>$service->create(1,array_replace($input,['raters'=>[1,2]])),'Owner cannot act as blinded rater');
researchDenied(fn()=>$service->create(1,array_replace($input,['raters'=>[10,11]])),'Student accounts cannot be assigned console rating tasks');
researchDenied(fn()=>$service->create(10,$input),'Students cannot create a group study');
$id=$service->create(1,$input);
$revision=fn()=>$store->study($id)['revision'];
$phase=function(string $next)use($service,$id,$revision):void{$service->transition(1,$id,$revision(),$next);};
researchAssert(count($service->roster(1,$id))===6,'Overlapping group membership is deduplicated');
$router->fallback='fallback.model';researchDenied(fn()=>$service->enroll(1,$id,$revision(),[10,11]),'Fallback model cannot be frozen');$router->fallback=null;
$service->enroll(1,$id,$revision(),[10,11,12,13,14,15]);
researchAssert(count($store->participants($id))===6,'Six unique participants enrolled');
$snapshot=$store->participants($id)[0]['baseline'];$learning->level='advanced';
researchAssert($store->participants($id)[0]['baseline']===$snapshot,'Existing baseline remains byte-for-byte unchanged');
researchDenied(fn()=>$service->enroll(1,$id,$revision(),[10,11]),'Baseline cannot be recaptured after freeze');
$guard=new ResearchFeatureDisableGuard($store);researchAssert($guard->blockers()!==[],'Active study blocks feature disable');
$submission=fn(int $user,int $number=500)=>new AiFeedbackSubmission($number,1000,$user,0,6,0,0,'2026-09-10 00:00:00','print(1)','','',true,false);
$bridge=new ResearchPolicyBridge();researchDenied(fn()=>$bridge->context($submission(10)),'Unattached bridge fails closed');$bridge->attach($service);
$phase('pretest');
researchDenied(fn()=>$service->context($submission(10)),'Pretest blocks feedback generation and reads');
researchDenied(fn()=>$service->assertAiAccess(10),'Pretest also blocks addon AI calls');
researchDenied(fn()=>$service->missing(1,$id,$revision(),1,'posttest','absent'),'Future test cannot be marked missing');
foreach([10,11,12,13,14] as $user){$service->submitAnswer($user,$id,$revision(),'print(1)','풀이를 설명했습니다.');}
researchDenied(fn()=>$service->submitAnswer(10,$id,$revision(),'other','other'),'Final answer is immutable');
researchDenied(fn()=>$service->submitAnswer(99,$id,$revision(),'other','other'),'Nonparticipant cannot submit');
researchAssert(count($service->ratingTasks(2))===5,'Only assigned rater receives answers');
researchAssert($service->ratingTasks(10)===[],'Learner cannot enumerate answers');
researchDenied(fn()=>$service->managed(2,$id),'Rater cannot unblind management view');
$tasks=$service->ratingTasks(2);researchAssert(!isset($tasks[0]['arm'])&&!isset($tasks[0]['baseline'])&&!isset($tasks[0]['user_id']),'Rating DTO excludes condition and identity');
$rateAll=function()use($service):void{foreach([2,3]as$rater){foreach($service->ratingTasks($rater)as$task){$service->rate($rater,$task['study_id'],$task['blind_id'],['understanding'=>15,'design'=>15,'implementation'=>30,'explanation'=>15]);}}};
researchDenied(fn()=>$service->rate(2,$id,$tasks[0]['blind_id'],['understanding'=>21,'design'=>0,'implementation'=>0,'explanation'=>0]),'Rubric range enforced server-side');
$rateAll();$phase('allocation_ready');
researchDenied(fn()=>$service->allocate(1,$id,$revision()),'Unrecorded pretest missingness blocks allocation');
$people=$store->participants($id);$last=end($people);$service->missing(1,$id,$revision(),$last['id'],'pretest','absent');
$service->allocate(1,$id,$revision());$allocation=array_column($store->participants($id),'arm','id');
researchAssert(count(array_filter($allocation,fn($arm)=>$arm==='A'))>0&&count(array_filter($allocation,fn($arm)=>$arm==='B'))>0,'Both conditions allocated');
researchDenied(fn()=>$service->allocate(1,$id,$revision()),'Assignment cannot be rerolled');
researchAssert($allocation===array_column($store->participants($id),'arm','id'),'Failed reroll leaves assignment untouched');
$phase('practice');researchDenied(fn()=>$service->context($submission(10)),'First attempt blocks AI');$phase('feedback');
$adaptive=null;$common=null;
foreach($store->participants($id)as$p){$c=$service->context($submission($p['user_id']));if($c['policy']==='level_adaptive')$adaptive=$c;else$common=$c;}
researchAssert($adaptive!==null&&$common!==null,'Adaptive and common contexts resolved');
$factory=new FeedbackInputFactory();$base=['problem'=>['description'=>'test'],'submission'=>['source'=>'print(1)']];
$a=$factory->research($base,$adaptive);$b=$factory->research($base,$common);
researchAssert(isset($a['learner_profile'])&&!array_key_exists('learner_profile',$b),'Comparison payload has no learner profile field');
researchAssert($a['learner_profile']['level']==='intermediate','Adaptive input uses saved original level, not changed live result');
researchAssert($a['response_policy']===$b['response_policy'],'Both conditions use identical response rules');
researchAssert($adaptive['token']!==$common['token'],'Condition/participant contexts cannot share a cache token');
researchDenied(fn()=>$service->assertAiAccess(10),'Other AI tasks are unavailable during the research feedback phase');
researchAssert($service->context($submission(999))===null,'Nonresearch learner keeps ordinary feedback');
$owner=$store->people[$adaptive['participant_id']]['user_id'];
for($n=1;$n<=3;$n++){$service->record($adaptive,'generated',['submission_id'=>500+$n,'input_hash'=>'hash'.$n]);}
$before=count($store->records($id));$service->record($adaptive,'generated',['submission_id'=>501,'input_hash'=>'hash1']);
researchAssert(count($store->records($id))===$before,'Repeated response receipt is idempotent');
researchDenied(fn()=>$service->context($submission($owner,504)),'Fourth distinct feedback is blocked');
$phase('check');researchDenied(fn()=>$service->record($adaptive,'delivered',['submission_id'=>501,'input_hash'=>'hash1']),'Late AI response cannot be delivered after check starts');
researchAssert(count(array_filter($store->records($id),fn($e)=>$e['kind']==='submission.observed'))===6,'Native submission records captured for the same lesson');
$service->submitAnswer(10,$id,$revision(),'print(2)','독립 확인');$rateAll();$phase('between_lessons');$phase('posttest');
foreach(range(10,15)as$user){$service->submitAnswer($user,$id,$revision(),'print(3)','독립 사후검사');}$rateAll();$phase('waiting_delay');
researchDenied(fn()=>$phase('delayed'),'Scheduled retention interval enforced');$clock->time=$clock->time->modify('+14 days');$phase('delayed');
foreach(range(10,15)as$user){$service->submitAnswer($user,$id,$revision(),'print(4)','독립 지연검사');}$rateAll();$phase('completed');$phase('locked');
$export=$service->export(1,$id);researchAssert($export['assignment_count']===6&&count($export['participants'])===6,'Original allocation denominator preserved');
$lastRow=end($export['participants']);researchAssert($lastRow['pretest_score']===null&&$lastRow['pretest_missing_reason']==='absent','Absent is null, never zero');
researchAssert($lastRow['posttest_score']===75.0,'Independent rater mean is recorded');
$encoded=json_encode($export);researchAssert(!str_contains($encoded,'학생10')&&!str_contains($encoded,'print(3)')&&!str_contains($encoded,'"user_id"'),'Default export excludes identity and code');
$service->withdraw(1,$id,$revision(),$last['id'],'자료 사용 철회');$export=$service->export(1,$id);
researchAssert($export['assignment_count']===6&&count($export['participants'])===5&&$export['withdrawn_count']===1,'Withdrawal excludes future export without rewriting allocation');
$groups->authorized=false;researchDenied(fn()=>$service->export(1,$id),'Revoked group authority blocks export');$groups->authorized=true;
$phase('archived');researchAssert($store->activeForUser(10)===null&&$guard->blockers()===[],'Archive releases reservations and feature guard');
$id2=$service->create(1,$input);$service->transition(1,$id2,1,'cancelled','예비 연구 종료');researchAssert($store->study($id2)['status']==='cancelled','Draft can be cancelled with a reason');
$features->enabled=false;researchDenied(fn()=>$service->create(1,$input),'Disabled feature rejects commands');

echo "수업 연구 단위 검사 통과: {$checks} assertions; 그룹 중복·동결·원배정·AI 조건·검사·채점·내보내기 검증\n";
