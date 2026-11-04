<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Presentation;

use CodingPanda\OjResearch\Application\{ResearchService,PilotService};
use CodingPanda\OjResearch\Domain\{ClassroomProtocol as C,ClassroomReport as R,PilotProtocol as P,PilotDesign,PilotReport};
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\{ActionUrlProvider,ActionSecurity};

final class ClassroomPages
{
    public function __construct(private readonly ResearchService $research,private readonly PilotService $pilot,
        private readonly ActionUrlProvider $urls,private readonly ActionSecurity $security) {}

    public function index(int $actor):string
    {
        $studies=$this->research->listFor($actor);$groups=$this->research->managedGroups($actor);
        $h=$this->header('그룹별 수업','설문부터 수업 결과까지, 한 흐름으로 관리하세요.','<a class="ojp-button ojp-button--primary" href="/oj-console/research/new">수업 만들기</a>');
        $h.=$this->steps('draft').'<div class="ojp-research-grid">';
        foreach($groups as$id=>$name){$items=array_filter($studies,static fn($s)=>in_array($id,$s['data']['protocol']['groups'],true));
            $h.='<section class="ojp-research-card ojp-classroom-group"><p class="ojp-eyebrow">수업 그룹</p><h2>'.$this->e($name).'</h2>';
            if($items===[]){$h.='<p>아직 연결한 수업이 없습니다.</p>';}
            foreach($items as$s){$h.='<article class="ojp-classroom-session"><span class="ojp-badge">'.$this->e(C::is($s)?C::phase($s):(P::PHASES[$s['status']]??$s['status'])).'</span><h3>'.$this->e($s['title']).'</h3><p>진행 '.$s['data']['lesson'].'차시'.(count($s['data']['protocol']['groups'])>1?' · 여러 그룹이 함께하는 수업':'').'</p><a class="ojp-button" href="/oj-console/research/'.$s['id'].'">수업 열기 →</a></article>';}
            $h.='<a class="ojp-button" href="/oj-console/research/new?group_id='.$id.'">이 그룹에 수업 만들기</a></section>';
        }
        if($groups===[]){$h.='<section class="ojp-research-card"><h2>먼저 그룹을 만들어 주세요</h2><a href="/oj-console/groups">그룹 관리</a></section>';}
        return $h.'</div><details class="ojp-research-card"><summary>상세 연구 도구</summary><p><a href="/oj-console/research/new?advanced=1">평가·배정을 직접 진행하는 시범 연구</a> · <a href="/oj-console/research?legacy=1">독립 채점 연구</a></p></details>';
    }

    public function edit(int $actor,?array $s=null,int $groupId=0):string
    {
        $groups=$this->research->managedGroups($actor);
        if($groups===[]){return '<section class="ojp-empty-state"><h1>관리하는 그룹이 필요합니다</h1><a href="/oj-console/groups">그룹 관리</a></section>';}
        $p=$s['data']['protocol']??P::validate(['title'=>'수준별 AI 피드백 수업','design'=>'adaptation','groups'=>[array_key_first($groups)],'lessons_count'=>6]);
        if($s===null){$p['groups']=isset($groups[$groupId])?[$groupId]:[];$p['before_items']=C::BEFORE;$p['survey_items']=C::AFTER;}
        $setup=\CodingPanda\OjResearch\Domain\ClassroomSettings::forForm($p,$s===null);
        $p['notes']=$setup['notes'];foreach(['A','B']as$arm){$p['arms'][$arm]['conditions']=$setup['arm_conditions'][$arm];}
        $h=$this->header($s?'수업 설정 수정':'수업 만들기',$s?'저장한 수업 조건입니다. 기존에 작성한 설명은 유지됩니다.':'학생 수준별 AI 피드백의 알고리즘 학습 효과를 살펴보는 설정 초안입니다. 4주·6차시·45분 예시를 실제 조건에 맞게 바꾸세요.');
        $h.=$this->form('pilot.classroom.save',$s).'<input type="hidden" name="guided_settings" value="1"><section class="ojp-research-card"><h2>어느 그룹과 수업할까요?</h2>'.$this->field('수업 이름','title',$p['title']).'<fieldset class="ojp-classroom-fieldset"><legend>대상 그룹 · 여러 그룹 선택 가능</legend><div class="ojp-research-choices">';
        foreach($groups as$id=>$name){$h.='<label><input type="checkbox" name="groups[]" value="'.$id.'"'.(in_array($id,$p['groups'],true)?' checked':'').'> '.$this->e($name).'</label>';}
        $h.='</div></fieldset><div class="ojp-form-grid">'.$this->field('수업 문제 번호 · 쉼표로 구분','practice',implode(', ',$p['practice'])).$this->field('수업 차시 수','lessons_count',$p['lessons_count'],'number').$this->field('문제별 풀이 제한 (분)','minutes',$p['arms']['A']['minutes'],'number').'</div></section>';
        $h.='<section class="ojp-research-card" data-oj-classroom-design><h2>어떤 차이를 확인할까요?</h2>'.$this->select('두 집단의 비교 조건','design',PilotDesign::LABELS,$p['design']).'<div class="ojp-research-grid"><div><strong>A · 실험집단</strong><p>기본값은 기존 학생 수준에 맞는 AI 피드백입니다.</p></div><div><strong>B · 비교 수업</strong><p data-oj-classroom-comparison>'.($p['design']==='adaptation'?'B는 공통 AI 피드백입니다. 수준에 맞춘 설명의 추가 효과를 살펴봅니다.':($p['design']==='feedback'?'B는 AI 없는 기존 수업입니다. 수준별 AI를 포함한 수업의 차이를 살펴봅니다.':'두 집단 조건을 세부 설정에서 직접 선택합니다.')).'</p></div></div><p class="ojp-field-help">기본 배정은 기존 수준별 층화 배정입니다. 수업 시작을 누르면 두 집단을 자동 구성합니다.</p></section>';
        $h.='<section class="ojp-research-card"><h2>설문도 함께 준비됩니다</h2><div class="ojp-research-grid"><div><h3>시작 설문</h3><p>수업 전 자기평가 '.count($p['before_items']).'문항</p></div><div><h3>종료 설문</h3><p>수업 후 자기평가·만족도·AI 경험 '.count($p['survey_items']).'문항 + 자유 의견</p></div></div><p>학생이 직접 응답합니다. 해당 없음과 무응답은 점수에서 제외하고 따로 표시합니다.</p><details data-oj-research-modal><summary>설문 문항과 척도 수정</summary>'.$this->area('시작 설문 · 한 줄에 한 문항','before_items',implode("\n",$p['before_items'])).$this->area('종료 설문 · 한 줄에 한 문항','survey_items',implode("\n",$p['survey_items'])).$this->select('설문 응답 척도','survey_max',array_combine(range(2,10),array_map(static fn($n)=>$n.'점 척도',range(2,10))),$p['survey_max']).'<p>기본 예시는 시작 자기평가 3문항, 종료 자기평가 3문항·수업 만족도 1문항·AI 경험 3문항입니다. 시작·종료의 같은 자기평가 문구를 유지하면 인식 변화를 살펴볼 수 있습니다. AI 미사용·미열람 학생은 AI 문항에서 해당 없음을 선택합니다.</p><p>검증된 척도가 아닌 현장용 초안입니다. 전문가 문항 검토와 소수 학생의 문장 이해 확인 후 사용하세요. 실제 학습 효과는 별도 성취 과제와 제출 기록으로 확인합니다. 기존에 저장한 설문은 자동 교체하지 않습니다.</p></details></section>';
        $h.=ClassroomSettingsFields::consent($setup);
        $h.='<details class="ojp-research-card" data-oj-research-modal><summary>수업 환경에 맞게 세부 조건 설정</summary><div class="ojp-form-grid">'.$this->select('학생 배정','allocation',['stratified'=>'기존 수준별 층화 배정','random'=>'학생 무작위 배정','class'=>'그룹 단위 배정 (2그룹 이상)'],$p['allocation']).$this->select('피드백에서 참조할 수준','level_timing',['baseline'=>'시작 시 저장한 기존 수준','current'=>'요청 시점의 기존 수준'],$p['level_timing']).$this->field('학생·활동별 AI 제출 한도 (0: 무제한)','request_limit',$p['request_limit'],'number').'</div><p>한도는 피드백이 생성된 서로 다른 제출 수입니다. 모델 호출 횟수와는 다릅니다. 기존 활성 수준별 지침과 AI 연결은 등록 시 고정됩니다.</p>';
        foreach(['A','B']as$arm){$a=$p['arms'][$arm];$h.='<h3>'.$arm.' 집단</h3><div class="ojp-form-grid">'.$this->field('집단 이름','arm_'.$arm.'_name',$a['name']).$this->select('목표 인원 비율','arm_'.$arm.'_weight',array_combine(range(1,10),range(1,10)),$a['weight']).$this->field('문제별 시간 · 직접 설계에서 적용','arm_'.$arm.'_minutes',$a['minutes'],'number').$this->select('AI · 직접 설계에서 적용','arm_'.$arm.'_ai',P::AI,$a['ai']).$this->select('문제 제공 · 직접 설계에서 적용','arm_'.$arm.'_recommendation',P::REC,$a['recommendation']).'</div>'.$this->area('추가 조건 · 선택 (두 집단이 다르면 직접 설계)','arm_'.$arm.'_conditions',$a['conditions']);}
        $h.='<p class="ojp-field-help" data-oj-research-ratio role="status">목표 인원 비율 A:B = '.$p['arms']['A']['weight'].':'.$p['arms']['B']['weight'].'</p><p>1:1은 두 집단을 비슷한 인원으로, 2:1은 A에 B의 약 두 배를 배정하려는 목표입니다. 점수·학생 수준·AI 요청 한도에는 영향을 주지 않습니다. 수준별로 인원이 적거나 그룹을 통째로 배정하면 실제 비율이 달라질 수 있습니다.</p>';
        $h.=$this->area('공통 AI용 실제 프롬프트','common_instructions',$p['common_instructions']).'<p class="ojp-field-help">설명 메모가 아니라 모델 요청에 전달되는 지침입니다. ‘공통 AI’를 선택한 집단·활동에만 적용합니다. 수준별 AI는 등록 시 보관한 기존 수준별 지침을 사용하고, AI 사용 안 함에서는 요청하지 않습니다. 학생 등록 후에는 연구 조건과 함께 고정됩니다.</p>'.'<h3>별도 성취 과제 (선택)</h3><p>수업 밖 독립 성취의 변화를 보려면 시작·마무리 과제를 모두 지정하세요. 생략하면 수업 중 수행과 설문만 정리합니다.</p><div class="ojp-form-grid">'.$this->field('시작 과제 번호','pretest',implode(', ',$p['pretest'])).$this->field('마무리 과제 번호','posttest',implode(', ',$p['posttest'])).$this->field('과제별 제한 (분)','test_minutes',$p['test_minutes'],'number').$this->select('과제 채점','scoring',['ac'=>'정답 문항 비율','pass_rate'=>'테스트 통과 비율'],$p['scoring']).$this->select('풀이 시간','pause_time',['exclude'=>'일시 중단 제외','include'=>'일시 중단 포함'],$p['pause_time']).'</div>';
        $h.='<details><summary>평가 지원·분석 조건</summary><div class="ojp-form-grid">';foreach(['pretest'=>'시작','posttest'=>'마무리']as$phase=>$label){foreach(['A','B']as$a){$h.=$this->select($label.' '.$a.' AI · 직접 설계에서 적용','support_'.$phase.'_'.$a,P::AI,$p['support'][$phase][$a]);}}
        $h.=$this->select('별도 평가 오류율 기준','errors',['first'=>'최초 유효 제출','all'=>'모든 유효 제출'],$p['errors']).$this->select('별도 성취 분석의 표준오차','covariance',['auto'=>'학급 구조에 따라 자동','hc3'=>'학생 단위 HC3','cluster'=>'학급 보정 CR1'],$p['covariance']).'</div></details>';
        $h.='</details><details class="ojp-research-card" data-oj-research-modal><summary>수업 맥락·보고서 설명 설정</summary>'.ClassroomSettingsFields::context($setup);
        return $h.'</details><div class="ojp-research-actions">'.$this->button('설정 저장 · 학생 확인').'<a href="/oj-console/research">목록으로</a></div></form>';
    }

    public function show(int $actor,array $s):string
    {
        $id=$s['id'];$p=$s['data']['protocol'];$people=$this->research->participants($actor,$id);$roster=$this->research->roster($actor,$id);$groups=$this->research->managedGroups($actor);
        $r=R::build($s,$people,$this->pilot->reportRecords($actor,$id));$t=$r['total'];
        $h=$this->header($s['title'],C::phase($s),' <a class="ojp-button" href="/oj-console/research/'.$id.'/report">보고서·첨부자료 ↗</a>').$this->steps($s['status']);
        $h.='<div class="ojp-research-stats">'.$this->stat('참여 학생',$t['n'].'명').$this->stat('수업',$s['data']['lesson'].' / '.$p['lessons_count'].'차시').$this->stat('시작 / 종료 설문',$t['before_responded'].' / '.$t['after_responded'].'명').'</div>';
        if($s['status']==='draft'){
            $h.='<section class="ojp-research-next"><p class="ojp-eyebrow">시작 준비</p><h2>동의를 확인한 학생에게 설문을 열어 주세요</h2><p>기존 수준과 AI 설정을 보관합니다. 등록 후에는 수업 조건이 고정됩니다.</p>'.$this->form('pilot.enroll',$s);
            foreach($p['groups']as$gid){$h.='<fieldset class="ojp-classroom-fieldset"><legend>'.$this->e($groups[$gid]??'그룹').'</legend><div class="ojp-research-choices">';foreach($roster as$v){if($v['group_id']===$gid){$h.='<label><input type="checkbox" name="participants[]" value="'.$v['user_id'].'"> '.$this->e($v['name']).'</label>';}}$h.='</div></fieldset>';}
            $h.='<label><input type="checkbox" name="consent_confirmed" value="1" required> 선택 학생의 참여·자료 수집 동의를 확인했습니다.</label>'.$this->button('시작 설문 열기').'</form><p><a href="?tab=settings">설정 수정</a> · <a href="?tab=materials">문제 자료 준비</a></p></section>';
        }else{
            $next=C::next($s);$h.='<section class="ojp-research-next"><p class="ojp-eyebrow">지금 할 일</p><h2>'.$this->e(C::phase($s)).'</h2>';
            if($next){$h.='<p>'.($s['status']==='baseline'?'시작 설문 '.$t['before_responded'].' / '.$t['n'].'명 응답. 미응답은 결측으로 남기고 진행할 수 있습니다.':'현재 활동을 마치면 다음 버튼을 누르세요. 제출 판정과 설문은 결과 화면에 자동 반영됩니다.').'</p>'.$this->form('pilot.transition',$s).'<input type="hidden" name="next_phase" value="'.$next[0].'">'.$this->button($next[1]).'</form>';}
            elseif($s['status']==='completed'){$h.='<p>종료 설문 '.$t['after_responded'].' / '.$t['n'].'명 응답. 학생에게 종료 설문을 안내하고 아래 결과를 확인하세요.</p>';}
            elseif($s['status']==='paused'){$h.=$this->form('pilot.transition',$s).'<input type="hidden" name="next_phase" value="'.$this->e($s['data']['resume_phase']).'">'.$this->field('재개 사유','reason','').$this->button('수업 재개').'</form>';}
            else{$h.='<p>수업 기록과 보고서를 확인할 수 있습니다.</p>';}
            $h.='<p>학생 참여 주소: <a href="/research">/research</a> · 설문 응답과 풀이 시작은 이 주소에서 합니다.</p></section>';
        }
        $h.='<h2>그룹별 진행</h2><div class="ojp-research-grid">';
        foreach($p['groups']as$gid){$g=$r['groups'][R::groupCode($s,$gid)];$h.='<section class="ojp-research-card"><h3>'.$this->e($groups[$gid]??'그룹').'</h3><p>'.$g['n'].'명 · 시작 설문 '.$g['before_responded'].'명 · 종료 설문 '.$g['after_responded'].'명</p><div class="ojp-classroom-progress"><span>해결한 학습 단위 '.$g['solved_units'].' / 시도 '.$g['attempted_units'].'</span></div><p>제출 '.$g['submissions'].'건 · 채점 대기 '.$g['pending'].'건</p>';
            foreach(['A','B']as$a){$count=count(array_filter($people,static fn($v)=>$v['group_id']===$gid&&$v['arm']===$a&&$v['consent']==='granted'));$h.='<p><span class="ojp-badge">'.$a.' · '.$count.'명</span> '.$this->e($p['arms'][$a]['name']).' · '.$this->e(P::AI[$p['arms'][$a]['ai']]).'</p>';}
            $h.='</section>';
        }
        $h.='</div>';
        if($s['status']!=='draft'){$h.=$this->resultCards($r);}
        if(!in_array($s['status'],['draft','archived','cancelled'],true)){$h.='<details class="ojp-research-card" data-oj-research-modal><summary>교사 관찰 남기기 · 선택</summary><p>관찰한 내용만 기록하세요. 기존 학생 수준은 변경되지 않습니다.</p>'.$this->form('pilot.classroom.observation',$s);$options=[''=>'학생 선택'];foreach($people as$v){if($v['consent']==='granted'){$options[$v['id']]=$roster[$v['user_id']]['name']??$v['pseudonym'];}}
            $h.='<input type="hidden" name="request_id" value="'.bin2hex(random_bytes(16)).'">'.$this->select('관찰한 학생','participant_id',$options,'').'<div class="ojp-form-grid">';
            foreach(['level_match'=>['초기 수준과 실제 수행',['unobserved','matches','higher','lower']],'teacher_help'=>['교사 도움',['unobserved','none','some','much']],'feedback_use'=>['피드백 활용',['unobserved','understood','difficult','unused']],'issue'=>['운영 문제',['unobserved','none','ai_delay','ai_error','submission','other']]]as$key=>[$label,$keys]){$h.=$this->select($label,$key,array_combine($keys,array_map(R::observationLabel(...),$keys)),'unobserved');}
            $h.='</div>'.$this->area('구체적인 장면·교사 도움·반복 연습 등','note','').$this->button('관찰 저장').'</form></details>';}
        $h.='<details class="ojp-research-card" data-oj-research-modal><summary>학생별 참여·배정 확인</summary>';
        foreach($people as$v){$h.='<p>'.$this->e($roster[$v['user_id']]['name']??$v['pseudonym']).' · '.$this->e($groups[$v['group_id']]??'그룹').' · '.$this->e($v['arm']??'수업 시작 시 배정').' · '.$this->e($v['consent']==='granted'?'참여':'철회').'</p>';}
        $h.='</details><details class="ojp-research-card" data-oj-research-modal><summary>설정·자료·수업 종료 관리</summary><p><a href="?tab=settings">조건 확인</a> · <a href="?tab=materials">문제 자료</a> · <a href="?tab=students">학생 참여 관리</a></p>';
        if(in_array($s['status'],['completed','cancelled'],true)){$h.='<p>보관하면 학생의 설문 입력이 닫히고 결과가 고정됩니다. 대기 중인 채점은 완료 후 보관하세요.</p>'.$this->form('pilot.transition',$s).'<input type="hidden" name="next_phase" value="archived">'.$this->button('수업 보관').'</form>';}
        elseif(!in_array($s['status'],['archived','paused'],true)){$h.=$this->form('pilot.transition',$s).$this->select('운영 상태','next_phase',in_array($s['status'],P::ACTIVE,true)?['paused'=>'일시 중단','cancelled'=>'수업 중단']:['cancelled'=>'수업 중단'],'cancelled').$this->field('변경 사유','reason','').$this->button('상태 변경').'</form>';}
        return $h.'</details>';
    }

    public function report(int $actor,array $s,string $document='',bool $fragment=false):string
    {
        $r=R::build($s,$this->research->participants($actor,$s['id']),$this->pilot->reportRecords($actor,$s['id']));
        $pages=new ClassroomDocumentPages($this->urls,$this->security);
        if($document!==''){return $pages->document($s,$r,$document,$fragment);}
        return $this->header('결과 보고서·첨부자료',$s['title'],'<a class="ojp-button" href="/oj-console/research/'.$s['id'].'">수업 관리로 돌아가기</a>').$this->resultCards($r).$pages->library($s);
    }

    public function learner(int $actor,array $ctx):string
    {
        $s=$ctx['study'];$person=$ctx['participant'];$p=$s['data']['protocol'];
        $h=$this->header($s['title'],C::phase($s)).$this->steps($s['status']);
        if(in_array($s['status'],['baseline','completed'],true)){
            $stage=$s['status']==='baseline'?'before':'after';$saved=false;
            foreach($this->pilot->learnerRecords($actor)as$e){if($e['kind']==='classroom.survey'&&$e['data']['stage']===$stage){$saved=true;}}
            if($saved){return $h.'<section class="ojp-research-next"><h2>설문 응답을 저장했어요</h2><p>'.($stage==='before'?'선생님의 수업 시작 안내를 기다려 주세요. 새로고침하면 현재 활동을 확인할 수 있어요.':'수업에 참여해 주셔서 감사합니다.').'</p></section>';}
            $h.='<section class="ojp-research-card"><h2>'.($stage==='before'?'수업 전에 알려 주세요':'이번 수업은 어땠나요?').'</h2><p>'.$this->e(C::surveyGuidance($stage)).'</p><p>'.$this->e(implode(' / ',C::scaleOptions($p['survey_max']))).'. 응답은 한 번 저장합니다.</p>'.$this->form('pilot.classroom.survey',$s).'<input type="hidden" name="survey_stage" value="'.$stage.'">';
            $options=[''=>'선택하세요']+C::scaleOptions($p['survey_max']);$options['na']='해당 없음 / 판단 어려움';
            foreach($stage==='before'?$p['before_items']:$p['survey_items']as$i=>$item){$h.=$this->select(($i+1).'. '.$item,'answer_'.$i,$options,'',true);}
            return $h.$this->area('더 하고 싶은 말 (선택) · 이름 등 개인 정보는 쓰지 마세요','comment','').$this->button('설문 저장').'</form></section>';
        }
        if(!in_array($s['status'],P::ACTIVE,true)){return $h.'<section class="ojp-research-card"><h2>선생님의 안내를 기다려 주세요</h2><p>활동이 시작되면 여기에 문제가 표시됩니다.</p></section>';}
        $ids=$this->pilot->problemIds($s);$ordered=$this->pilot->recommendationIds($actor);if($ordered!==null&&$ordered!==[]){$ids=$ordered;}$catalog=$this->pilot->catalog($actor,$ids);$sessions=$this->pilot->sessions($s,$person);$finished=$this->pilot->assessmentFinished($s,$person);
        $h.='<section class="ojp-research-callout"><strong>'.($this->pilot->aiMode($s,$person)==='off'?'이번 활동은 AI 도움 없이 진행합니다.':'이번 활동에서는 제출 후 AI 피드백을 받을 수 있습니다.').'</strong><p>풀이 시작을 누르면 OJ 문제 화면으로 이동합니다. 문제를 바꾸거나 잠시 멈출 때는 이 화면을 이용하세요.</p></section><div class="ojp-research-grid">';
        foreach($ids as$id){$session=$sessions[$this->pilot->activityKey($s).':'.$id]??null;$running=$session!==null&&$session['running_at']!==null;$h.='<section class="ojp-research-card"><span class="ojp-badge">'.($finished?'과제 완료':($running?'풀이 중':'준비')).'</span><h2>#'.$id.' '.$this->e($catalog[$id]['title']??'문제').'</h2>';if(!$finished){$h.=$this->form('pilot.timer',$s).'<input type="hidden" name="problem_id" value="'.$id.'"><input type="hidden" name="timer_action" value="'.($running?'pause':'start').'">'.$this->button($running?'일시 중단':($session?'이어서 풀기':'풀이 시작')).'</form>';if($running){$h.='<p><a href="/problems/'.$id.'">문제 화면으로</a></p>';}}$h.='</section>';}$h.='</div>';
        if(in_array($s['status'],['pretest','posttest'],true)&&!$finished){$h.='<section class="ojp-research-card"><h2>과제를 모두 마쳤나요?</h2><p>완료 확인 후에는 이 과제를 다시 제출할 수 없습니다. 미시도 문제는 0점으로 계산됩니다.</p>'.$this->form('pilot.finish',$s).$this->button('과제 완료 확인').'</form></section>';}
        return $h;
    }

    private function resultCards(array $r):string
    {
        $t=$r['total'];return '<section class="ojp-classroom-results"><h2>지금까지 모인 결과</h2><div class="ojp-classroom-three"><article class="ojp-research-card"><p class="ojp-eyebrow">01 · 시스템 작동</p><h3>AI 생성 '.$t['feedback_generated'].'건</h3><p>전달 '.$t['feedback_delivered'].'건 · 채점 대기 '.$t['pending'].'건</p><p class="ojp-field-help">전달 기록은 실제 읽기·이해를 뜻하지 않습니다.</p></article><article class="ojp-research-card"><p class="ojp-eyebrow">02 · 학생 경험</p><h3>종료 설문 '.$t['after_responded'].' / '.$t['n'].'명</h3><p>이해도와 만족도는 문항별로 정리합니다.</p><p class="ojp-field-help">무응답과 해당 없음을 구분합니다.</p></article><article class="ojp-research-card"><p class="ojp-eyebrow">03 · 학습 수행</p><h3>해결 '.$t['solved_units'].' / 시도 '.$t['attempted_units'].'단위</h3><p>재제출 '.$t['resubmissions'].'회 · 해결 평균 '.PilotReport::number($t['solve_seconds']).'초</p><p class="ojp-field-help">학생×차시×문제 기준. 인과적 학습 효과로 단정하지 않습니다.</p></article></div></section>';
    }
    private function steps(string $phase):string {$stage=in_array($phase,['draft','baseline'],true)?0:(in_array($phase,['completed','archived','cancelled'],true)?2:1);$h='<ol class="ojp-research-steps ojp-classroom-steps">';foreach(['설정·시작 설문','그룹별 수업','종료 설문·결과']as$i=>$label){$h.='<li'.($stage===$i?' aria-current="step"':'').'><span>'.($i+1).'</span>'.$label.'</li>';}return $h.'</ol>';}
    private function header(string $title,string $subtitle,string $action=''):string {return '<header class="ojp-page-header"><div><p class="ojp-eyebrow"><a href="/oj-console/research">그룹 수업</a></p><h1>'.$this->e($title).'</h1><p>'.$this->e($subtitle).'</p></div>'.$action.'</header>';}
    private function stat(string $label,string $value):string {return '<div><span>'.$this->e($label).'</span><strong>'.$this->e($value).'</strong></div>';}
    private function form(string $op,?array $s=null):string {return '<form class="ojp-form-stack" method="post" action="'.$this->e($this->urls->actionUrl()).'">'.$this->security->hiddenFields('oj_research_command').'<input type="hidden" name="operation" value="'.$op.'"><input type="hidden" name="study_id" value="'.($s['id']??0).'"><input type="hidden" name="revision" value="'.($s['revision']??0).'">';}
    private function field(string $label,string $name,mixed $v,string $type='text'):string {return '<label class="ojp-research-field">'.$this->e($label).'<input class="ojp-input" name="'.$name.'" type="'.$type.'" value="'.$this->e((string)$v).'"'.($type==='number'?' min="0" step="1"':'').'></label>';}
    private function area(string $label,string $name,string $v):string {return '<label class="ojp-research-field">'.$this->e($label).'<textarea class="ojp-textarea" name="'.$name.'" rows="3">'.$this->e($v).'</textarea></label>';}
    private function select(string $label,string $name,array $options,mixed $v,bool $required=false):string {$h='<label class="ojp-research-field">'.$this->e($label).'<select class="ojp-select" name="'.$name.'"'.($required?' required':'').'>';foreach($options as$key=>$text){$h.='<option value="'.$this->e((string)$key).'"'.((string)$key===(string)$v?' selected':'').'>'.$this->e((string)$text).'</option>';}return $h.'</select></label>';}
    private function button(string $label):string {return '<button class="ojp-button ojp-button--primary" type="submit">'.$this->e($label).'</button>';}
    private function e(string $v):string {return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
