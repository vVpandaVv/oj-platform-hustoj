<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Application;

use CodingPanda\OjResearch\Contract\ResearchRepository;
use CodingPanda\OjResearch\Contract\ResearchLearningGateway;
use CodingPanda\OjResearch\Contract\PilotResources;
use CodingPanda\OjResearch\Domain\PilotProtocol as P;
use CodingPanda\OjResearch\Domain\ResearchProtocol;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionResearchRecorder;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\RecommendationPolicy;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use DateTimeZone;
use DomainException;

final class PilotService implements SubmissionResearchRecorder, RecommendationPolicy
{
    public function __construct(private readonly ResearchService $management, private readonly ResearchRepository $store,
        private readonly ResearchLearningGateway $learning, private readonly PilotResources $resources,
        private readonly FeatureFlags $features, private readonly AiTaskRouter $ai, private readonly Clock $clock) {}

    public function save(int $actor, int $id, int $revision, array $input): int
    {
        $this->enabled();
        $protocol = P::validate($input);
        $this->management->authorizeProtocol($actor,$protocol);
        $managed = $this->management->managedGroups($actor);
        foreach ($protocol['groups'] as $g) { if (! isset($managed[$g])) { throw new DomainException('선택 그룹을 관리할 권한이 없습니다.'); } }
        return $this->store->transaction(function () use ($actor,$id,$revision,$protocol): int {
            if ($id === 0) {
                $id = $this->store->create(['title'=>$protocol['title'],'owner_id'=>$actor,'status'=>'draft','revision'=>1,
                    'data'=>['protocol'=>$protocol,'lesson'=>0,'phase'=>'draft','phase_started_at'=>$this->now()], 'created_at'=>$this->now(),'updated_at'=>$this->now()]);
                $this->event($id,0,'created','pilot.created',['actor'=>$actor]);
            } else {
                $s = $this->locked($actor,$id,$revision);
                if ($s['status'] !== 'draft') { throw new DomainException('초기 수준 저장 이후에는 연구 조건을 고정합니다. 새 연구로 조건을 변경해 주세요.'); }
                $protocol['materials'] = $s['data']['protocol']['materials'];
                $s['data']['protocol']=$protocol; $s['title']=$protocol['title'];
                $this->event($id,0,'settings:'.$revision,'pilot.settings',['actor'=>$actor,'protocol_hash'=>ResearchProtocol::hash($protocol)]);
                $this->store->updateStudy($s,$revision);
            }
            return $id;
        });
    }

    public function material(int $actor,int $id,int $revision,int $problemId,array $input): void
    {
        $this->store->transaction(function () use ($actor,$id,$revision,$problemId,$input): void {
            $s=$this->locked($actor,$id,$revision); $p=$s['data']['protocol'];
            if ($s['status'] !== 'draft' || ! in_array($problemId,array_merge($p['practice'],$p['pretest'],$p['posttest']),true)) { throw new DomainException('준비 단계에서 연구에 포함한 문제 자료를 편집해 주세요.'); }
            $problem=$this->resources->problems($actor,[$problemId])[$problemId]??null;
            if ($problem===null) { throw new DomainException('접근할 수 있는 OJ 문제를 지정해 주세요.'); }
            $row=['problem'=>$problem,'reviewed'=>($input['reviewed']??false)===true,'reviewer'=>$actor,'updated_at'=>$this->now()];
            foreach (['topic','difficulty','answer','explanation','errors','source','version'] as $key) { $row[$key]=P::text($input[$key]??'', $key==='answer'?50000:12000); }
            if ($row['reviewed'] && ($row['answer']==='' || $row['explanation']==='' || $row['version']==='')) { throw new DomainException('정답 코드·해설·자료 버전을 작성한 뒤 검수를 완료해 주세요.'); }
            $s['data']['protocol']['materials'][$problemId]=$row;
            $this->event($id,0,'material:'.$revision,'pilot.material',['actor'=>$actor,'problem_id'=>$problemId,'reviewed'=>$row['reviewed']]);
            $this->store->updateStudy($s,$revision);
        });
    }

    public function enroll(int $actor,int $id,int $revision,array $ids): void
    {
        $s=$this->management->managed($actor,$id); $p=$s['data']['protocol']; $roster=$this->management->roster($actor,$id);
        if(!P::is($s)) {throw new DomainException('시범 연구가 아닙니다.');}
        $ids=P::ids($ids);
        if (count($ids)<2 || count($ids)>500) { throw new DomainException('동의를 확인한 학생 2~500명을 선택해 주세요.'); }
        foreach (['practice','pretest','posttest'] as $phase) { if ($p[$phase]===[]) { throw new DomainException('학습·사전·사후 문제를 모두 지정해 주세요.'); } }
        if ($p['notes']['consent']==='') { throw new DomainException('동의 문서와 자료 보관 절차를 작성해 주세요.'); }
        $hashes=$this->learning->problemHashes($actor,array_merge($p['practice'],$p['pretest'],$p['posttest']));
        $usesAi = $p['arms']['A']['ai']!=='off' || $p['arms']['B']['ai']!=='off';
        foreach ($p['support'] as $supports) { $usesAi=$usesAi || count(array_filter($supports,static fn($x)=>$x!=='off'))>0; }
        $routing=$usesAi?$this->ai->snapshot('submission_feedback',true):null;
        if ($routing?->fallbackProfileKey()!==null) { throw new DomainException('연구용 AI는 대체 모델이 없는 연결을 선택해 주세요.'); }
        $guides=$usesAi?$this->resources->guides():[];
        $snapshots=[];
        foreach ($ids as $user) {
            if (!isset($roster[$user])) { throw new DomainException('현재 그룹 학생 명단을 확인해 주세요.'); }
            $snapshots[$user]=$this->learning->levelSnapshot($user);
        }
        $this->store->transaction(function () use($actor,$id,$revision,$ids,$snapshots,$roster,$hashes,$routing,$guides):void {
            $s=$this->locked($actor,$id,$revision);
            if ($s['status']!=='draft' || $this->management->roster($actor,$id)!==$roster) { throw new DomainException('초기 자료 저장 단계 또는 학생 명단이 변경되었습니다.'); }
            foreach($ids as $user) {
                if($this->store->activeForUser($user)!==null) { throw new DomainException('다른 활성 연구에 참여 중인 학생이 있습니다.'); }
                $this->store->addParticipant(['study_id'=>$id,'user_id'=>$user,'group_id'=>$roster[$user]['group_id'],'active_user_id'=>$user,
                    'pseudonym'=>'R-'.bin2hex(random_bytes(8)),'arm'=>null,'consent'=>'granted','baseline'=>$snapshots[$user]]);
            }
            $s['data']+=['routing'=>$routing?->toArray(),'guides'=>$guides,'problem_hashes'=>$hashes,'baseline_at'=>$this->now(),
                'protocol_hash'=>ResearchProtocol::hash($s['data']['protocol'])];
            $this->phase($s,'baseline',$actor);
        });
    }

    public function allocate(int $actor,int $id,int $revision,array $manual=[]):void
    {
        $this->store->transaction(function ()use($actor,$id,$revision,$manual):void {
            $s=$this->locked($actor,$id,$revision);
            if($s['status']!=='allocation_ready') { throw new DomainException('사전 평가 종료 후 두 집단을 배정해 주세요.'); }
            $people=array_values(array_filter($this->store->participants($id),static fn($x)=>$x['consent']==='granted'));
            $seed=bin2hex(random_bytes(32));$p=$s['data']['protocol'];
            $map=P::allocate($people,$p['allocation'],$p['arms'],$seed,$manual);
            foreach($map as $pid=>$arm) {$this->store->updateParticipant($pid,['arm'=>$arm]);}
            $s['data']['allocation']=['method'=>$p['allocation'],'seed'=>$seed,'input_hash'=>ResearchProtocol::hash($people),'at'=>$this->now()];
            $s['data']['assignment_count']=count($people);
            $this->event($id,0,'allocation','allocation.completed',$s['data']['allocation']);$this->phase($s,'allocated',$actor);
        });
    }

    public function transition(int $actor,int $id,int $revision,string $next,string $reason=''):void
    {
        $this->store->transaction(function ()use($actor,$id,$revision,$next,$reason):void {
            $s=$this->locked($actor,$id,$revision);$old=$s['status'];
            $allowed=['baseline'=>['pretest'],'pretest'=>['allocation_ready'],'allocated'=>['practice'],'practice'=>['between_lessons'],
                'between_lessons'=>[$s['data']['lesson']<$s['data']['protocol']['lessons_count']?'practice':'posttest'],
                'posttest'=>['completed'],'completed'=>['archived'],'cancelled'=>['archived']];
            if($next==='paused' && in_array($old,P::ACTIVE,true)) {$s['data']['resume_phase']=$old;}
            elseif($old==='paused' && $next===($s['data']['resume_phase']??'')) {}
            elseif($next==='cancelled' && !in_array($old,['archived','cancelled'],true)) {}
            elseif(!in_array($next,$allowed[$old]??[],true)) {throw new DomainException('현재 단계에서 선택할 수 없는 작업입니다.');}
            if($next==='paused'||$old==='paused'||$next==='cancelled') {if(P::text($reason,2000)==='') {throw new DomainException('변경 사유를 기록해 주세요.');}}
            if(in_array($old,P::ACTIVE,true)||$old==='paused') {
                // End any running timers at the teacher's explicit phase boundary.
                foreach($this->store->participants($id) as $person) { $this->pauseRunning($s,$person); }
            }
            if($next==='practice' && $old!=='paused') {++$s['data']['lesson'];}
            if(in_array($next,P::ACTIVE,true) && $old!=='paused') {$s['data']['activity_started_at']=$this->now();}
            if(in_array($next,['archived','cancelled'],true)) {foreach($this->store->participants($id)as$person){$this->store->updateParticipant($person['id'],['active_user_id'=>null]);}}
            $this->phase($s,$next,$actor,$reason);
        });
    }

    public function learner(int $actor):?array
    {
        $person=$this->store->activeForUser($actor);
        if($person===null) {return null;}
        $s=$this->store->study($person['study_id']);
        if($s===null || !P::is($s)) {return null;}
        $this->enabled();
        if($person['consent']!=='granted' || !isset($this->management->roster($s['owner_id'],$s['id'])[$actor])) {throw new DomainException('현재 연구 참여 권한이 없습니다.');}
        return ['study'=>$s,'participant'=>$person];
    }

    public function activityKey(array $s):string {return $s['status'].':'.$s['data']['lesson'];}
    public function problemIds(array $s):array {return in_array($s['status'],P::ACTIVE,true)?$s['data']['protocol'][$s['status']]:[];}
    public function catalog(int $actor,array $ids):array {return $this->resources->problems($actor,$ids);}
    public function records(int $actor,int $id):array {$this->management->managed($actor,$id);return $this->store->records($id);}

    public function sessions(array $s,array $person):array
    {
        $sessions=[];
        foreach($this->store->records($s['id'])as$r) {
            if($r['participant_id']!==$person['id']||$r['kind']!=='pilot.timer') {continue;}
            $d=$r['data'];$key=$d['activity'].':'.$d['problem_id'];
            $session=$sessions[$key]??['id'=>$d['session_id'],'activity'=>$d['activity'],'problem_id'=>$d['problem_id'],'started_at'=>$r['created_at'],'running_at'=>null,'seconds'=>0];
            if($d['action']==='start') {$session['running_at']=$r['created_at'];}
            else {if($session['running_at']!==null){$session['seconds']+=max(0,strtotime($r['created_at'].' UTC')-strtotime($session['running_at'].' UTC'));}$session['running_at']=null;}
            $sessions[$key]=$session;
        }
        return $sessions;
    }

    public function timer(int $actor,int $id,int $revision,int $problemId,string $action):void
    {
        $this->store->transaction(function()use($actor,$id,$revision,$problemId,$action):void {
            [$s,$person]=$this->studentLocked($actor,$id,$revision);
            if(!in_array($problemId,$this->problemIds($s),true)||!in_array($action,['start','pause'],true)) {throw new DomainException('현재 활동의 문제를 선택해 주세요.');}
            if($this->assessmentFinished($s,$person)) {throw new DomainException('이미 평가를 마쳤습니다.');}
            $this->learning->problemHashes($actor,[$problemId]);
            $key=$this->activityKey($s).':'.$problemId; $session=$this->sessions($s,$person)[$key]??null;
            if($action==='start' && $session!==null && $session['running_at']!==null) {return;}
            if($action==='pause' && ($session===null||$session['running_at']===null)) {return;}
            if($action==='start') {$this->pauseRunning($s,$person);}
            $this->event($id,$person['id'],'timer:'.bin2hex(random_bytes(12)),'pilot.timer',
                ['activity'=>$this->activityKey($s),'problem_id'=>$problemId,'session_id'=>$session['id']??bin2hex(random_bytes(16)),'action'=>$action]);
        });
    }

    private function pauseRunning(array $s,array $person):void
    {
        foreach($this->sessions($s,$person)as$session) {if($session['running_at']!==null){
            $this->event($s['id'],$person['id'],'timer:'.bin2hex(random_bytes(12)),'pilot.timer',
                ['activity'=>$session['activity'],'problem_id'=>$session['problem_id'],'session_id'=>$session['id'],'action'=>'pause']);
        }}
    }

    public function bindSubmission(int $actor,int $problemId,int $submissionId):void
    {
        $ctx=$this->learner($actor);if($ctx===null){return;}$s=$ctx['study'];
        if(!in_array($s['status'],array_merge(P::ACTIVE,['paused']),true)){return;}
        $this->store->transaction(function()use($actor,$s,$problemId,$submissionId):void {
            [$s,$person]=$this->studentLocked($actor,$s['id'],$s['revision']);
            if(!in_array($problemId,$this->problemIds($s),true)||$this->assessmentFinished($s,$person)){throw new DomainException('현재 연구 활동에 지정된 문제만 제출할 수 있습니다.');}
            $session=$this->sessions($s,$person)[$this->activityKey($s).':'.$problemId]??null;
            if($session===null||$session['running_at']===null){throw new DomainException('내 연구 활동에서 이 문제의 풀이 시작을 눌러 주세요.');}
            $limit=($s['status']==='practice'?$s['data']['protocol']['arms'][$person['arm']]['minutes']:$s['data']['protocol']['test_minutes'])*60;
            $elapsed=$session['seconds']+max(0,strtotime($this->now().' UTC')-strtotime($session['running_at'].' UTC'));
            if($elapsed>$limit){throw new DomainException('이 문제의 풀이 제한 시간이 지났습니다.');}
            $hashes=$this->learning->problemHashes($actor,[$problemId]);
            if(($hashes[$problemId]??'')!==($s['data']['problem_hashes'][$problemId]??null)){throw new DomainException('연구 시작 후 문제 내용이 변경되었습니다.');}
            $this->event($s['id'],$person['id'],'binding:'.$submissionId,'pilot.submission',[
                'submission_id'=>$submissionId,'problem_id'=>$problemId,'activity'=>$this->activityKey($s),'phase'=>$s['status'],
                'lesson'=>$s['data']['lesson'],'session_id'=>$session['id'],'started_at'=>$session['started_at'],
                'elapsed_seconds'=>$s['data']['protocol']['pause_time']==='exclude'?$elapsed:max(0,strtotime($this->now().' UTC')-strtotime($session['started_at'].' UTC')),
                'arm'=>$person['arm'],'revision'=>$s['revision']]);
        });
    }

    public function assessmentFinished(array $s,array $person):bool
    {
        if(!in_array($s['status'],['pretest','posttest'],true)){return false;}
        foreach($this->store->records($s['id'])as$r){if($r['participant_id']===$person['id']&&$r['kind']==='pilot.finished'&&$r['data']['phase']===$s['status']){return true;}}
        return false;
    }

    public function finish(int $actor,int $id,int $revision):void
    {
        $this->store->transaction(function()use($actor,$id,$revision):void {
            [$s,$p]=$this->studentLocked($actor,$id,$revision);
            if(!in_array($s['status'],['pretest','posttest'],true)){throw new DomainException('평가 중에만 최종 제출할 수 있습니다.');}
            $this->pauseRunning($s,$p);
            $this->event($id,$p['id'],'finished:'.$p['id'].':'.$s['status'],'pilot.finished',['phase'=>$s['status']]);
        });
    }

    public function survey(int $actor,int $id,int $revision,array $answers):void
    {
        $this->store->transaction(function()use($actor,$id,$revision,$answers):void {
            [$s,$p]=$this->studentLocked($actor,$id,$revision);
            if(!in_array($s['status'],['posttest','completed'],true)){throw new DomainException('사후 단계에서 설문에 응답해 주세요.');}
            $protocol=$s['data']['protocol'];$values=[];
            foreach($protocol['survey_items']as$i=>$item){$values[]=P::integer($answers[$i]??'',1,$protocol['survey_max']);}
            $this->event($id,$p['id'],'survey:'.$p['id'],'pilot.survey',['answers'=>$values]);
        });
    }

    public function surveySaved(array $s,array $p):bool
    {
        foreach($this->store->records($s['id'])as$r){if($r['participant_id']===$p['id']&&$r['kind']==='pilot.survey'){return true;}}return false;
    }

    public function aiMode(array $s,array $person):string
    {
        if($s['status']==='practice') {return $s['data']['protocol']['arms'][$person['arm']]['ai'];}
        if(in_array($s['status'],['pretest','posttest'],true)) {
            // Before assignment the common pretest policy must match for both eventual arms.
            $support=$s['data']['protocol']['support'][$s['status']];
            if($person['arm']===null && $support['A']!==$support['B']){throw new DomainException('집단 배정 전 사전 평가 AI 조건은 두 집단이 같아야 합니다.');}
            return $support[$person['arm']??'A'];
        }
        return 'off';
    }

    public function context(AiFeedbackSubmission $submission):?array
    {
        $ctx=$this->learner($submission->ownerUserId());if($ctx===null){return null;}$s=$ctx['study'];$person=$ctx['participant'];
        if(!in_array($s['status'],array_merge(P::ACTIVE,['paused']),true)){return null;}
        $mode=$this->aiMode($s,$person);
        if($mode==='off'||$this->assessmentFinished($s,$person)){throw new DomainException('현재 수업 조건에서는 AI 피드백을 사용할 수 없습니다.');}
        $binding=null;
        foreach($this->store->records($s['id'])as$r){if($r['participant_id']===$person['id']&&$r['kind']==='pilot.submission'&&$r['data']['submission_id']===$submission->submissionId()){$binding=$r['data'];}}
        if($binding===null||$binding['activity']!==$this->activityKey($s)){throw new DomainException('현재 연구 활동에서 제출한 코드에만 AI 도움을 제공합니다.');}
        $level=$s['data']['protocol']['level_timing']==='current'?$this->learning->levelSnapshot($person['user_id']):$person['baseline'];
        $key=$level['level']??'insufficient';
        $instructions=$mode==='level'?($s['data']['guides'][$key]['instructions']??$s['data']['guides']['insufficient']['instructions']):$s['data']['protocol']['common_instructions'];
        $material=$s['data']['protocol']['materials'][$submission->problemId()]??null;
        $context=['protocol_type'=>P::TYPE,'study_id'=>$s['id'],'participant_id'=>$person['id'],'revision'=>$s['revision'],
            'lesson'=>$s['data']['lesson'],'activity'=>$this->activityKey($s),'assigned_arm'=>$person['arm'],'policy'=>$mode==='level'?'level_adaptive':'common',
            'baseline'=>$level,'instructions'=>$instructions,'routing'=>$s['data']['routing'],'problem_hash'=>$s['data']['problem_hashes'][$submission->problemId()],
            'protocol_hash'=>$s['data']['protocol_hash'],'limit'=>$s['data']['protocol']['request_limit']];
        if($material!==null&&$material['reviewed']) {$context['learning_materials']=['explanation'=>$material['explanation'],'reference_code'=>$material['answer'],'error_types'=>$material['errors'],'version'=>$material['version']];}
        $context['token']=ResearchProtocol::hash($context);$this->quota($context,$submission->submissionId());return $context;
    }

    private function quota(array $context,int $submissionId):void
    {
        if($context['limit']===0){return;}$seen=[];
        foreach($this->store->records($context['study_id'])as$r){if($r['participant_id']===$context['participant_id']&&$r['kind']==='feedback.generated'&&($r['data']['context']['activity']??'')===$context['activity']){$seen[$r['data']['feedback']['submission_id']]=true;}}
        if(!isset($seen[$submissionId])&&count($seen)>=$context['limit']){throw new DomainException('이 활동의 AI 요청 한도에 도달했습니다.');}
    }

    public function record(array $context,string $event,array $data):void
    {
        $this->store->transaction(function()use($context,$event,$data):void {
            $s=$this->store->study($context['study_id'],true);$p=array_column($this->store->participants($context['study_id']),null,'id')[$context['participant_id']]??null;
            if(!in_array($event,['generated','delivered'],true)||$s===null||$s['revision']!==$context['revision']||$p===null||$p['consent']!=='granted'||$this->aiMode($s,$p)==='off'||$this->assessmentFinished($s,$p)){throw new DomainException('연구 조건이 변경되어 피드백 제공을 중단했습니다.');}
            $this->learner($p['user_id']);$this->quota($context,(int)$data['submission_id']);
            $this->event($s['id'],$p['id'],$event.':'.$context['token'].':'.$data['submission_id'].':'.$data['input_hash'],'feedback.'.$event,
                ['context'=>array_diff_key($context,array_flip(['baseline','instructions','routing','learning_materials'])),'feedback'=>$data]);
        });
    }

    public function recommendationIds(int $actor):?array
    {
        $ctx=$this->learner($actor);if($ctx===null){return null;}$s=$ctx['study'];$p=$ctx['participant'];
        if(!in_array($s['status'],array_merge(P::ACTIVE,['paused']),true)){return null;}
        if($s['status']!=='practice'){return [];}
        $mode=$s['data']['protocol']['arms'][$p['arm']]['recommendation'];
        if($mode==='teacher'){return [];}
        $ids=$this->problemIds($s);
        if($mode==='personal'){
            $profile=$s['data']['protocol']['level_timing']==='current'?$this->learning->levelSnapshot($actor):$p['baseline'];
            $target=(int)($profile['proven_tier']??1);$catalog=$this->resources->problems($actor,$ids);
            usort($ids,static fn($a,$b)=>(abs(($catalog[$a]['difficulty']??$target)-$target)<=>abs(($catalog[$b]['difficulty']??$target)-$target))?:($a<=>$b));
        }
        return $ids;
    }

    public function collect(int $actor,int $id,int $revision):void
    {
        $this->store->transaction(function()use($actor,$id,$revision):void {
            $s=$this->locked($actor,$id,$revision);$records=$this->store->records($id);
            if($s['status']==='archived'){throw new DomainException('보관한 연구는 수집 결과를 변경하지 않습니다.');}
            foreach($this->store->participants($id)as$p){
                if($p['consent']!=='granted'){continue;}$bindings=[];
                foreach($records as$r){if($r['participant_id']===$p['id']&&$r['kind']==='pilot.submission'){$bindings[$r['data']['submission_id']]=$r['data'];}}
                if($bindings===[]){continue;}
                $all=$this->learning->submissions($p['user_id'],array_values(array_unique(array_column($bindings,'problem_id'))),$s['data']['baseline_at'],$this->now());
                foreach($all as$submission){if(isset($bindings[$submission['submission_id']])&&!$submission['processing']){
                    $data=$bindings[$submission['submission_id']]+['result_code'=>$submission['result_code'],'pass_rate'=>$submission['pass_rate']??null,'submitted_at'=>$submission['submitted_at']];
                    $this->event($id,$p['id'],'judged:'.$submission['submission_id'].':'.ResearchProtocol::hash($data),'pilot.judged',$data);
                }}
            }
            $this->event($id,0,'refresh:'.$revision,'pilot.collected',['actor'=>$actor]);$this->store->updateStudy($s,$revision);
        });
    }

    private function studentLocked(int $actor,int $id,int $revision):array
    {
        $ctx=$this->learner($actor);$s=$this->store->study($id,true);
        if($ctx===null||$s===null||$ctx['study']['id']!==$id||$s['revision']!==$revision||$s['status']==='paused'){throw new DomainException('연구 활동이 변경되었습니다. 새로고침해 주세요.');}
        return [$s,$ctx['participant']];
    }
    private function locked(int $actor,int $id,int $revision):array
    {
        $this->enabled();$this->management->managed($actor,$id);$s=$this->store->study($id,true);
        if($s===null||!P::is($s)||$s['revision']!==$revision){throw new DomainException('연구 조건이 변경되었습니다. 새로고침해 주세요.');}return $s;
    }
    private function phase(array $s,string $next,int $actor,string $reason=''):void
    {
        $this->event($s['id'],0,'phase:'.($s['revision']+1),'phase.changed',['actor'=>$actor,'from'=>$s['status'],'to'=>$next,'reason'=>$reason]);
        $s['status']=$next;$s['data']['phase']=$next;$s['data']['phase_started_at']=$this->now();$s['updated_at']=$this->now();$this->store->updateStudy($s,$s['revision']);
    }
    private function event(int $id,int $person,string $key,string $kind,array $data):void {$this->store->append($id,$person,$key,$kind,$data,$this->now());}
    private function enabled():void {if(!$this->features->isEnabled('research')){throw new DomainException('수업 연구 기능이 꺼져 있습니다.');}}
    private function now():string {return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
}
