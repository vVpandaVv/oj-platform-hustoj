<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Presentation;

use CodingPanda\OjResearch\Application\ResearchService;
use CodingPanda\OjResearch\Application\PilotService;
use CodingPanda\OjResearch\Domain\PilotProtocol as P;
use CodingPanda\OjResearch\Domain\PilotReport;
use CodingPanda\OjResearch\Domain\PilotDesign;
use CodingPanda\OjResearch\Domain\PilotAnalysisReport;
use CodingPanda\OjResearch\Domain\ResearchProtocol;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;

/** Operational views and manuscript views deliberately have different navigation and density. */
final class PilotPages
{
    public function __construct(private readonly ResearchService $research,private readonly PilotService $pilot,
        private readonly ActionUrlProvider $urls,private readonly ActionSecurity $security) {}

    public function isPilot(int $actor,int $id):bool {return P::is($this->research->managed($actor,$id));}
    public function hasLearner(int $actor):bool {return $this->pilot->learner($actor)!==null;}

    public function index(int $actor):string
    {
        $html='<header class="ojp-page-header"><div><p class="ojp-eyebrow">OJ Console · 수업 연구</p><h1>수업을 운영하고, 연구를 기록하세요</h1><p>문제 자료부터 두 집단의 수업 진행까지 한곳에서 관리합니다.</p></div><a class="ojp-button ojp-button--primary" href="/oj-console/research/new">새 시범 연구</a></header>';
        $html.='<div class="ojp-research-callout"><strong>관리는 수업 중심으로, 보고서는 논문 작성용으로</strong><p>각 연구의 관리 화면에서 수업을 진행하고, 별도 연구 보고서에서 저장된 조건과 실제 결과를 문안·표로 내려받으세요.</p></div><div class="ojp-research-grid">';
        $studies=$this->research->listFor($actor);
        foreach($studies as$s){$pilot=P::is($s);$p=$s['data']['protocol'];
            $html.='<article class="ojp-research-card"><span class="ojp-badge">'.$this->e(($pilot?P::PHASES:ResearchProtocol::PHASES)[$s['status']]??$s['status']).'</span><h2>'.$this->e($s['title']).'</h2><p>'.($pilot?'수준별 AI·문제 추천 시범 연구':'기존 독립 평가 연구').'</p><p>진행 '.$s['data']['lesson'].'차시 · 연결 그룹 '.count($p['groups']).'개</p><div class="ojp-research-actions"><a class="ojp-button ojp-button--primary" href="/oj-console/research/'.$s['id'].'">연구 관리</a>'.($pilot?'<a class="ojp-button" href="/oj-console/research/'.$s['id'].'/report">연구 보고서</a>':'').'</div></article>';
        }
        if($studies===[]){$html.='<article class="ojp-research-card"><h2>첫 연구를 준비해 보세요</h2><p>대상 그룹과 비교 조건을 저장한 뒤 문제 자료와 학생 명단을 차례로 준비할 수 있습니다.</p></article>';}
        return $html.'</div><details class="ojp-section"><summary>기존 독립 평가 연구 도구</summary><p><a href="/oj-console/research?legacy=1">이전 연구 방식으로 만들기</a> · <a href="/oj-console/research/rating">독립 채점함</a></p></details>';
    }

    public function edit(int $actor,?array $s=null):string
    {
        $p=$s['data']['protocol']??null;
        if($p===null){$groups=$this->research->managedGroups($actor);if($groups===[]){return '<div class="ojp-empty-state"><h1>관리하는 그룹이 필요합니다</h1><p>대상 그룹을 만든 뒤 연구를 준비해 주세요.</p><a href="/oj-console/groups">그룹 관리</a></div>';}
            $p=P::validate(['title'=>'수준별 AI 피드백의 알고리즘 학습 효과','design'=>'adaptation','groups'=>[array_key_first($groups)]]);$p['groups']=[];}
        $html='<header class="ojp-page-header"><div><p class="ojp-eyebrow">연구 준비</p><h1>'.($s===null?'새 시범 연구':'연구 조건 설정').'</h1><p>먼저 비교 조건을 정하세요. 자료와 학생은 저장 후 준비할 수 있습니다.</p></div></header>';
        $html.=$this->form('pilot.save',$s).$this->field('연구 제목','title',$p['title']);
        $html.='<section class="ojp-research-card" data-oj-research-design><p class="ojp-eyebrow">비교할 효과</p><h2>어떤 차이를 확인할까요?</h2>'
            .$this->select('비교 설계','design',PilotDesign::LABELS,$p['design']??'custom')
            .'<div class="ojp-research-grid"><div><strong>수준별 맞춤의 추가 효과</strong><p>A는 수준별 AI, B는 공통 AI를 사용합니다. 같은 모델·자료를 사용하며 지침의 수준 반영 차이를 검토합니다.</p></div><div><strong>기존 수업 대비 피드백 효과</strong><p>A는 수준별 AI, B는 AI 없는 수업입니다. AI 제공과 맞춤을 포함한 효과를 비교합니다.</p></div></div>'
            .'<p class="ojp-field-help" role="status" data-oj-research-design-status>설계를 선택하면 아래 AI·문제 순서·평가 지원을 맞춥니다. 저장 전에 확인하세요. 다른 조건은 직접 설계에서 조정할 수 있습니다.</p>'
            .'<noscript><p>자동 설정을 사용하려면 JavaScript가 필요합니다. 아래 조건을 직접 맞춰 저장할 수도 있습니다.</p></noscript></section>';
        $html.='<section class="ojp-research-card"><h2>대상 그룹</h2><div class="ojp-research-choices">';
        foreach($this->research->managedGroups($actor)as$id=>$name){$html.='<label><input type="checkbox" name="groups[]" value="'.$id.'"'.(in_array($id,$p['groups'],true)?' checked':'').'> '.$this->e($name).'</label>';}
        $html.='</div><p class="ojp-field-help">선택 그룹의 학생을 등록한 뒤 A·B 두 처치 집단으로 배정합니다. 기존 학생 수준 평가 결과를 그대로 사용합니다.</p></section><div class="ojp-research-grid">';
        foreach(['A','B']as$arm){$g=$p['arms'][$arm];$html.='<section class="ojp-research-card"><p class="ojp-eyebrow">'.$arm.' 집단</p>'
            .$this->field('집단 이름','arm_'.$arm.'_name',$g['name']).$this->select('AI 피드백','arm_'.$arm.'_ai',P::AI,$g['ai'])
            .$this->select('문제 제공','arm_'.$arm.'_recommendation',P::REC,$g['recommendation'])
            .'<details><summary>집단별 세부 조건</summary>'.$this->field('배정 가중치 (1~10)','arm_'.$arm.'_weight',$g['weight'],'number')
            .$this->field('문제별 풀이 제한 (분)','arm_'.$arm.'_minutes',$g['minutes'],'number')
            .$this->area('교사 지원·일정·해설 공개 등','arm_'.$arm.'_conditions',$g['conditions']).'</details></section>';}
        $html.='</div><section class="ojp-research-card"><h2>문제와 수업 구성</h2><div class="ojp-form-grid">';
        foreach(['practice'=>'연습 문제 번호','pretest'=>'사전 평가 문제 번호','posttest'=>'사후 평가 문제 번호']as$key=>$label){$html.=$this->field($label,$key,implode(', ',$p[$key]));}
        $html.=$this->field('적용 차시 수','lessons_count',$p['lessons_count'],'number').$this->field('평가 문제별 제한 (분)','test_minutes',$p['test_minutes'],'number').'</div><p class="ojp-field-help">OJ 문제 번호를 쉼표로 구분합니다. 연습과 평가 문제는 서로 달라야 합니다.</p></section>';
        $html.='<section class="ojp-research-card"><h2>연구 환경에 맞게 조정</h2><details open><summary>집단 배정과 AI</summary><div class="ojp-form-grid">'
            .$this->select('배정 방식','allocation',['stratified'=>'기존 수준별 층화 배정','random'=>'학생 무작위 배정','class'=>'그룹(학급) 단위 배정','manual'=>'학생별 수동 배정'],$p['allocation'])
            .$this->select('수준 참조 시점','level_timing',['baseline'=>'초기 저장한 수준','current'=>'요청 시점에 저장된 기존 수준'],$p['level_timing'])
            .$this->field('활동별 AI 피드백 제출 수 (0: 무제한)','request_limit',$p['request_limit'],'number').'</div><p class="ojp-field-help">학생별 현재 단계·차시에서 피드백이 생성된 서로 다른 제출을 셉니다. 클릭 수·모델 호출 수 제한이 아닙니다.</p>'
            .$this->area('공통 AI 지침 (공통 AI 선택 시)','common_instructions',$p['common_instructions'])
            .'<p>수준별 AI는 OJ의 활성 수준별 지침을 연결합니다. 모델 연결은 <a href="/oj-console/settings/ai-routing">AI 작업 연결 설정</a>에서 확인하고, 초기 자료 저장 시 연구용 버전을 고정합니다.</p></details>';
        $html.='<details><summary>사전·사후 평가 지원</summary><div class="ojp-form-grid">';
        foreach(['pretest'=>'사전','posttest'=>'사후']as$phase=>$label){foreach(['A','B']as$arm){$html.=$this->select($label.' '.$arm.' 집단 AI','support_'.$phase.'_'.$arm,P::AI,$p['support'][$phase][$arm]);}}
        $html.='</div><p>배정 전 사전 평가는 두 집단에 같은 조건을 적용합니다. 평가 중에는 문제 추천을 제공하지 않습니다.</p></details>';
        $html.='<details open><summary>성취 분석과 측정 지표</summary><p>주 결과는 사후 성취입니다. 사전 성취·초기 수준을 보정하고, 오류·시간·만족도는 보조 결과로 제공합니다.</p><div class="ojp-form-grid">'
            .$this->select('학급 구조와 표준오차','covariance',['auto'=>'자동: 복수 학급은 학급 보정','hc3'=>'학생 단위 HC3 (독립성 가정)','cluster'=>'학급 보정 CR1'],$p['covariance']??'auto')
            .$this->select('공통 평가 채점','scoring',['ac'=>'정답 문항 비율 (100점)','pass_rate'=>'테스트 통과 비율 (100점)'],$p['scoring'])
            .$this->select('오류율 분모','errors',['first'=>'문항별 최초 유효 제출','all'=>'모든 유효 제출'],$p['errors'])
            .$this->select('풀이 시간','pause_time',['exclude'=>'일시 중단 제외','include'=>'일시 중단 포함'],$p['pause_time'])
            .$this->field('만족도 척도 최댓값 (2~10)','survey_max',$p['survey_max'],'number').'</div>'
            .$this->area('공통 만족도 문항 (한 줄에 하나)','survey_items',implode("\n",$p['survey_items'])).'</details>';
        $html.='<details><summary>연구 환경·논문 작성 메모</summary><p>이 항목은 연구 보고서의 방법 문안으로 연결됩니다. 자유 입력한 분석식·추가 조건은 실행 설정이 아니며 별도로 검토할 연구 계획입니다.</p>';
        foreach(P::NOTES as$key=>$label){$html.=$this->area($label,'note_'.$key,$p['notes'][$key]);}
        return $html.'</details></section><div class="ojp-research-actions">'.$this->button('연구 조건 저장').'<a class="ojp-button" href="/oj-console/research'.($s?'/'.$s['id']:'').'">돌아가기</a></div></form>';
    }

    public function show(int $actor,int $id,string $tab='overview'):string
    {
        $s=$this->research->managed($actor,$id);$p=$s['data']['protocol'];$people=$this->research->participants($actor,$id);$roster=$this->research->roster($actor,$id);
        if(!in_array($tab,['overview','materials','students','settings'],true)){$tab='overview';}
        $html=$this->header($s).'<nav class="ojp-research-nav" aria-label="연구 관리">';
        foreach(['overview'=>'수업 운영','materials'=>'문제 자료','students'=>'학생·집단','settings'=>'연구 조건']as$key=>$label){$html.='<a href="/oj-console/research/'.$id.'?tab='.$key.'"'.($key===$tab?' aria-current="page"':'').'>'.$label.'</a>';}
        $html.='</nav>';
        if($tab==='settings'){return $html.($s['status']==='draft'?$this->edit($actor,$s):'<section class="ojp-research-card"><h2>연구 조건이 고정되었습니다</h2><p>수집 도중 처치의 의미가 바뀌지 않도록 시작 조건을 보관합니다. 연결한 조건과 버전은 연구 보고서에서 확인할 수 있습니다.</p><a class="ojp-button" href="/oj-console/research/'.$id.'/report">저장한 조건 확인</a></section>');}
        if($tab==='materials'){return $html.$this->materials($actor,$s);}
        if($tab==='students'){return $html.$this->students($actor,$s,$people,$roster);}
        $phase=$s['status'];$stage=match($phase){'draft'=>0,'baseline','pretest','allocation_ready'=>1,'allocated','practice','between_lessons','paused'=>2,default=>3};
        $html.='<ol class="ojp-research-steps">';foreach(['연구 준비','사전·집단 배정','수업 적용','결과 확인']as$i=>$label){$html.='<li'.($i===$stage?' aria-current="step"':'').'><span>'.($i+1).'</span>'.$label.'</li>';}$html.='</ol>';
        $ready=count(array_filter($p['materials'],static fn($m)=>$m['reviewed']));
        $html.='<div class="ojp-research-stats">'.$this->stat('참여 학생',count(array_filter($people,static fn($x)=>$x['consent']==='granted')).'명')
            .$this->stat('수업 진행',$s['data']['lesson'].' / '.$p['lessons_count'].'차시').$this->stat('자료 검수',$ready.'개').'</div>';
        $html.='<section class="ojp-research-next"><p class="ojp-eyebrow">지금 할 일</p><h2>'.$this->e(P::PHASES[$phase]).'</h2>';
        $next=['baseline'=>['pretest','사전 평가 시작'],'pretest'=>['allocation_ready','사전 평가 종료'],'allocated'=>['practice','1차시 시작'],
            'practice'=>['between_lessons','현재 차시 마치기'],'between_lessons'=>$s['data']['lesson']<$p['lessons_count']?['practice','다음 차시 시작']:['posttest','사후 평가 시작'],
            'posttest'=>['completed','사후 평가 종료'],'completed'=>['archived','연구 보관하기'],'cancelled'=>['archived','중단 기록 보관']];
        if($phase==='draft'){$html.='<p>문제 자료를 준비하고, 동의가 확인된 학생의 초기 수준을 저장하세요.</p><a class="ojp-button ojp-button--primary" href="?tab=students">학생 명단 확인</a> <a class="ojp-button" href="?tab=materials">자료 준비</a>';}
        elseif($phase==='allocation_ready'){$html.='<p>사전 평가 결과를 수집한 뒤 두 집단을 배정하세요.</p><a class="ojp-button ojp-button--primary" href="?tab=students">두 집단 배정</a>';}
        elseif(isset($next[$phase])){[$to,$label]=$next[$phase];$html.='<p>'.($phase==='practice'?'학생은 기존 OJ에서 문제를 풀고 배정된 조건에 따라 AI를 사용합니다.':'학생에게 현재 활동을 안내한 뒤 다음 단계로 진행하세요.').'</p>'.$this->form('pilot.transition',$s).'<input type="hidden" name="next_phase" value="'.$to.'">'.$this->button($label).'</form>';}
        elseif($phase==='paused'){$html.=$this->form('pilot.transition',$s).'<input type="hidden" name="next_phase" value="'.$this->e($s['data']['resume_phase']).'">'.$this->field('재개 사유','reason','').$this->button('활동 재개').'</form>';}
        else{$html.='<p>연구 기록을 보관했습니다. 보고서와 자료를 확인할 수 있습니다.</p>';}
        $html.='</section><section class="ojp-research-callout"><strong>'.$this->e(PilotDesign::LABELS[$p['design']??'custom']).'</strong><p>'.$this->e($p['notes']['question']?:PilotDesign::QUESTIONS[$p['design']??'custom']).'</p></section><div class="ojp-research-grid">';
        foreach(['A','B']as$arm){$g=$p['arms'][$arm];$count=count(array_filter($people,static fn($x)=>$x['arm']===$arm&&$x['consent']==='granted'));
            $html.='<section class="ojp-research-card"><p class="ojp-eyebrow">'.$arm.' 집단 · '.$count.'명</p><h2>'.$this->e($g['name']).'</h2><p><strong>'.$this->e(P::AI[$g['ai']]).'</strong></p><p>'.$this->e(P::REC[$g['recommendation']]).' · 문제별 '.$g['minutes'].'분</p><p>'.$this->e($g['conditions']).'</p></section>';}
        $html.='</div>';
        if(in_array($phase,['posttest','completed','archived'],true)){$html.=$this->analysisCards($this->reportData($actor,$id));}
        $warnings=PilotDesign::cautions($p);
        if($warnings!==[]){$html.='<details class="ojp-research-card"><summary>연구 설계 점검 · '.count($warnings).'항목</summary>';foreach($warnings as$w){$html.='<p>'.$this->e($w).'</p>';}$html.='</details>';}
        $html.='<section class="ojp-research-card"><h2>수집 기록</h2><p>연구 활동으로 연결된 제출의 최종 판정을 가져옵니다. 보고서는 마지막 수집 상태를 기준으로 작성됩니다.</p><div class="ojp-research-actions">'.$this->form('pilot.collect',$s).$this->button('최종 판정 수집').'</form><a class="ojp-button" href="/oj-console/research/'.$id.'/report">연구 보고서 열기</a><a class="ojp-button" href="/research">내 연구 활동</a></div></section>';
        if(!in_array($phase,['archived','cancelled'],true)){$html.='<details class="ojp-section"><summary>수업 일시 중단·연구 중단</summary>'.$this->form('pilot.transition',$s)
            .$this->select('변경할 상태','next_phase',in_array($phase,P::ACTIVE,true)?['paused'=>'현재 활동 일시 중단','cancelled'=>'연구 중단']:['cancelled'=>'연구 중단'],'cancelled').$this->field('사유','reason','').$this->button('상태 변경').'</form></details>';}
        return $html;
    }

    private function materials(int $actor,array $s):string
    {
        $p=$s['data']['protocol'];$ids=array_unique(array_merge($p['practice'],$p['pretest'],$p['posttest']));$catalog=$this->pilot->catalog($actor,$ids);
        $html='<div class="ojp-research-callout"><strong>피드백의 근거가 되는 문제 자료</strong><p>정답 코드·해설·오류 유형을 검수한 자료만 AI에 연결됩니다. 정답 자료는 이 관리 화면에서만 편집합니다.</p></div><div class="ojp-research-grid">';
        foreach($ids as$id){$m=$p['materials'][$id]??[];$html.='<article class="ojp-research-card"><span class="ojp-badge">'.(($m['reviewed']??false)?'검수 완료':'자료 준비').'</span><h2>#'.$id.' '.$this->e($catalog[$id]['title']??'접근 불가').'</h2><p>'.(in_array($id,$p['practice'],true)?'연습 자료':'공통 평가').'</p>';
            if($s['status']==='draft'){$html.='<details><summary>자료 편집</summary>'.$this->form('pilot.material',$s).'<input type="hidden" name="problem_id" value="'.$id.'">';
                foreach(['topic'=>'주제','difficulty'=>'난이도·판단 근거','source'=>'출처·이용 범위','version'=>'자료 버전']as$key=>$label){$html.=$this->field($label,$key,$m[$key]??'');}
                foreach(['answer'=>'정답 코드','explanation'=>'해설·풀이 근거','errors'=>'오류 유형·판별 기준']as$key=>$label){$html.=$this->area($label,$key,$m[$key]??'');}
                $html.='<label><input type="checkbox" name="reviewed" value="1"'.(($m['reviewed']??false)?' checked':'').'> 정답·해설 검수 완료</label>'.$this->button('문제 자료 저장').'</form></details>';}
            else{$html.='<p>버전 '.$this->e($m['version']??'미등록').' · '.$this->e($m['topic']??'주제 미등록').'</p>';}
            $html.='</article>';}
        return $html.'</div>';
    }

    private function students(int $actor,array $s,array $people,array $roster):string
    {
        $html='';
        if($s['status']==='draft'){$html.='<section class="ojp-research-card"><h2>참여 학생과 초기 수준 저장</h2><p>선택 학생의 기존 수준 결과를 보관합니다. 계산 방법은 변경하지 않습니다.</p>'.$this->form('pilot.enroll',$s).'<div class="ojp-research-choices">';
            foreach($roster as$r){$html.='<label><input type="checkbox" name="participants[]" value="'.$r['user_id'].'"> '.$this->e($r['name']).'</label>';}
            $html.='</div><p><label><input type="checkbox" name="consent_confirmed" value="1" required> 선택한 학생의 연구 참여·자료 수집 동의를 확인했습니다.</label></p>'.$this->button('초기 수준 저장 · 연구 시작').'</form></section>';}
        $allocation=$s['status']==='allocation_ready';
        if($allocation){$html.='<section class="ojp-research-card"><h2>두 집단 배정</h2><p>기존 수준을 기준으로 두 집단을 구성합니다. 사전 성취 자료는 집단 구성의 균형 확인과 분석에 사용할 수 있습니다.</p>'.$this->form('pilot.allocate',$s);}
        $rows=[];
        foreach($people as$person){$arm=$person['arm']??'미배정';
            if($allocation&&$s['data']['protocol']['allocation']==='manual'&&$person['consent']==='granted'){$arm=$this->select('집단','assign_'.$person['id'],['A'=>'A 집단','B'=>'B 집단'],'A');}else{$arm=$this->e($arm);}
            $rows[]=['name'=>$this->e($roster[$person['user_id']]['name']??'그룹 탈퇴 학생'),'id'=>$this->e($person['pseudonym']),
                'level'=>$this->e(['beginner'=>'초급','intermediate'=>'중급','advanced'=>'고급','insufficient'=>'자료 부족'][$person['baseline']['level']??'insufficient']??'자료 부족'),'arm'=>$arm,'consent'=>$person['consent']==='granted'?'참여':'철회'];}
        $html.=$this->table(['학생','연구 번호','초기 수준','집단','참여 상태'],array_map('array_values',$rows),false);
        if($allocation){$html.=$this->button('두 집단 배정 확정').'</form></section>';}
        if($people!==[]&&!in_array($s['status'],['archived','cancelled'],true)){$options=[];foreach($people as$person){if($person['consent']==='granted'){$options[$person['id']]=$roster[$person['user_id']]['name']??$person['pseudonym'];}}
            if($options!==[]){$html.='<details class="ojp-section"><summary>참여 철회 기록</summary>'.$this->form('withdraw',$s).$this->select('학생','participant_id',$options,(string)array_key_first($options)).$this->field('철회 사유','reason','').$this->button('철회 반영').'</form></details>';}}
        return $html;
    }

    public function report(int $actor,int $id):string
    {
        $s=$this->research->managed($actor,$id);$report=$this->reportData($actor,$id);
        $html='<div class="ojp-research-report"><header class="ojp-page-header"><div><p class="ojp-eyebrow">논문 작성용 · 연구 보고서</p><h1>'.$this->e($s['title']).'</h1><p>저장한 연구 조건과 실제 수집 자료를 연결한 초안입니다. 미작성 항목은 대괄호로 표시합니다.</p></div><a class="ojp-button" href="/oj-console/research/'.$id.'">수업 관리로 돌아가기</a></header>';
        $html.='<div class="ojp-research-actions">';foreach(['markdown'=>'논문 문안·표 내려받기','html'=>'인쇄용 보고서 내려받기','csv'=>'분석용 데이터 CSV','json'=>'분석 근거 JSON']as$format=>$label){$html.=$this->form('pilot.export',$s).'<input type="hidden" name="format" value="'.$format.'">'.$this->button($label).'</form>';}$html.='</div>';
        $html.='<div class="ojp-research-callout"><strong>보고서 기준: 연구 버전 '.$s['revision'].'</strong><p>실제 수집 자료로 계산합니다. 평균·변화량과 사전 보정 효과를 구분하며, 자료가 부족하면 추정을 보류합니다. 연구 중에는 중간 결과입니다.</p></div>'.$this->analysisCards($report);
        foreach(PilotReport::sections($s,$report)as$section){$html.='<article class="ojp-research-paper"><h2>'.$this->e($section['title']).'</h2>';foreach($section['paragraphs']as$text){$html.='<p>'.nl2br($this->e($text)).'</p>';}
            foreach($section['tables']as$t){$html.='<h3>'.$this->e($t['caption']).'</h3>'.$this->table($t['headers'],$t['rows']);}$html.='</article>';}
        return $html.'</div>';
    }
    public function reportData(int $actor,int $id):array
    {
        return PilotReport::build($this->research->managed($actor,$id),$this->research->participants($actor,$id),$this->pilot->records($actor,$id));
    }

    private function analysisCards(array $report):string
    {
        $a=$report['analysis'];$p=$a['primary'];$ok=$p['status']==='estimated';
        $n=$a['flow']['A']['complete']+$a['flow']['B']['complete'];
        $label=in_array($report['phase'],['completed','archived'],true)?'학습 효과 · 현재 수집 자료':'학습 효과 · 중간 분석';
        $html='<section class="ojp-research-card"><p class="ojp-eyebrow">'.$label.'</p><h2>비교집단과 얼마나 달라졌나요?</h2><div class="ojp-research-stats">'
            .$this->stat('분석 가능한 학생',$n.'명')
            .$this->stat('사전·수준 보정 A − B',$ok?PilotReport::number($p['effect']['estimate']).'점':'자료 확인 필요')
            .$this->stat('95% 신뢰구간',$ok?PilotAnalysisReport::interval($p['effect']):'계산 보류').'</div>'
            .'<p>'.$this->e(PilotAnalysisReport::interpretation($a)).'</p><p class="ojp-field-help">양수는 A집단의 성취가 높은 방향입니다. 상대 향상률(%)이 아닙니다. 수준별 비교와 분석 가정은 별도 연구 보고서에서 확인하세요.</p></section>';
        return $html;
    }

    public function learner(int $actor):string
    {
        $ctx=$this->pilot->learner($actor);if($ctx===null){return '<p>현재 참여 중인 시범 연구가 없습니다.</p>';}
        $s=$ctx['study'];$p=$ctx['participant'];$protocol=$s['data']['protocol'];$ids=$this->pilot->problemIds($s);$catalog=$this->pilot->catalog($actor,$ids);
        $html='<header class="ojp-page-header"><div><p class="ojp-eyebrow">내 연구 활동</p><h1>'.$this->e($s['title']).'</h1><p>'.$this->e(P::PHASES[$s['status']]).' · '.$s['data']['lesson'].'차시</p></div></header>';
        if(in_array($s['status'],P::ACTIVE,true)){
            $mode=$this->pilot->aiMode($s,$p);$html.='<div class="ojp-research-callout"><strong>'.($mode==='off'?'이번 활동은 AI 도움 없이 진행합니다.':'이번 활동은 제출 후 AI 풀이 도움을 사용할 수 있습니다.').'</strong><p>풀이 시작을 누르면 기존 OJ 문제 화면으로 이동합니다. 잠시 멈출 때는 여기에서 일시 중단을 선택하세요.</p></div>';
            $ordered=$this->pilot->recommendationIds($actor);if($ordered!==null&&$ordered!==[]){$ids=$ordered;}
            $sessions=$this->pilot->sessions($s,$p);$finished=$this->pilot->assessmentFinished($s,$p);$html.='<div class="ojp-research-grid">';
            foreach($ids as$id){$session=$sessions[$this->pilot->activityKey($s).':'.$id]??null;$running=$session!==null&&$session['running_at']!==null;
                $html.='<article class="ojp-research-card"><span class="ojp-badge">'.($finished?'평가 종료':($running?'풀이 중':($session?'일시 중단':'시작 전'))).'</span><h2>#'.$id.' '.$this->e($catalog[$id]['title']??'문제').'</h2>';
                if(!$finished){$html.=$this->form('pilot.timer',$s).'<input type="hidden" name="problem_id" value="'.$id.'"><input type="hidden" name="timer_action" value="'.($running?'pause':'start').'">'.$this->button($running?'일시 중단':($session?'이어서 풀기':'풀이 시작')).'</form>';
                    if($running){$html.='<p><a href="/problems/'.$id.'">문제 화면으로</a></p>';}}
                $html.='</article>';}
            $html.='</div>';
            if(in_array($s['status'],['pretest','posttest'],true)&&!$finished){$html.='<section class="ojp-research-card"><h2>평가를 모두 마쳤나요?</h2><p>아래 확인 후에는 이 평가에 다시 제출할 수 없습니다. 풀지 않은 문항은 0점으로 계산됩니다.</p>'.$this->form('pilot.finish',$s).$this->button('평가 완료 확인').'</form></section>';}
        }else{$html.='<section class="ojp-research-card"><h2>'.($s['status']==='paused'?'수업이 잠시 중단되었습니다':'교사의 다음 활동 안내를 기다려 주세요').'</h2><p>활동을 시작하면 여기에 문제와 도움 제공 조건이 표시됩니다.</p></section>';}
        if(in_array($s['status'],['posttest','completed'],true)&&$this->pilot->surveySaved($s,$p)) {
            $html.='<section class="ojp-research-card"><h2>설문 응답을 저장했습니다</h2><p>참여해 주셔서 감사합니다.</p></section>';
        } elseif(in_array($s['status'],['posttest','completed'],true)){
            $html.='<section class="ojp-research-card"><h2>학습 경험 설문</h2><p>1은 가장 낮은 평가, '.$protocol['survey_max'].'는 가장 높은 평가입니다. 응답은 한 번 저장합니다.</p>'.$this->form('pilot.survey',$s);
            foreach($protocol['survey_items']as$i=>$item){$options=[''=>'선택하세요'];for($j=1;$j<=$protocol['survey_max'];++$j){$options[(string)$j]=(string)$j;}$html.=$this->select($item,'answer_'.$i,$options,'');}
            $html.=$this->button('설문 응답 저장').'</form></section>';
        }
        return $html;
    }

    private function header(array $s):string {return '<header class="ojp-page-header"><div><p class="ojp-eyebrow"><a href="/oj-console/research">수업 연구</a> / 연구 관리</p><h1>'.$this->e($s['title']).'</h1><p>'.$this->e(P::PHASES[$s['status']]).' · 저장 버전 '.$s['revision'].'</p></div><a class="ojp-button" href="/oj-console/research/'.$s['id'].'/report">연구 보고서 ↗</a></header>';}
    private function stat(string $label,string $value):string {return '<div><span>'.$this->e($label).'</span><strong>'.$this->e($value).'</strong></div>';}
    private function form(string $op,?array $s=null):string {return '<form class="ojp-form-stack" method="post" action="'.$this->e($this->urls->actionUrl()).'">'.$this->security->hiddenFields('oj_research_command').'<input type="hidden" name="operation" value="'.$op.'"><input type="hidden" name="study_id" value="'.($s['id']??0).'"><input type="hidden" name="revision" value="'.($s['revision']??0).'">';}
    private function field(string $label,string $name,mixed $value,string $type='text'):string {return '<label class="ojp-research-field">'.$this->e($label).'<input class="ojp-input" name="'.$name.'" type="'.$type.'" value="'.$this->e((string)$value).'"'.($type==='number'?' min="0" step="1"':'').'></label>';}
    private function area(string $label,string $name,string $value):string {return '<label class="ojp-research-field">'.$this->e($label).'<textarea class="ojp-textarea" name="'.$name.'" rows="4">'.$this->e($value).'</textarea></label>';}
    private function select(string $label,string $name,array $options,mixed $value):string {$html='<label class="ojp-research-field">'.$this->e($label).'<select class="ojp-select" name="'.$name.'">';foreach($options as$key=>$text){$html.='<option value="'.$this->e((string)$key).'"'.((string)$key===(string)$value?' selected':'').'>'.$this->e($text).'</option>';}return $html.'</select></label>';}
    private function button(string $label):string {return '<button class="ojp-button ojp-button--primary" type="submit">'.$this->e($label).'</button>';}
    private function table(array $headers,array $rows,bool $escape=true):string {$html='<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>';foreach($headers as$h){$html.='<th scope="col">'.$this->e($h).'</th>';}$html.='</tr></thead><tbody>';foreach($rows as$row){$html.='<tr>';foreach($row as$v){$html.='<td>'.($escape?$this->e((string)$v):$v).'</td>';}$html.='</tr>';}if($rows===[]){$html.='<tr><td colspan="'.count($headers).'">아직 기록이 없습니다.</td></tr>';}return $html.'</tbody></table></div>';}
    private function e(string $value):string {return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
