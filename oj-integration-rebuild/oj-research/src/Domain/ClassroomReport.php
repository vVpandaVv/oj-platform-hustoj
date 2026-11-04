<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Domain;

/** Descriptive field evidence. Surveys, operation and performance remain separate. */
final class ClassroomReport
{
    public static function build(array $s,array $people,array $events):array
    {
        $p=$s['data']['protocol'];$base=PilotReport::build($s,$people,$events);$rows=[];$observations=[];$comments=[];
        $baseRows=array_column($base['rows'],null,'participant');
        foreach($people as$person){
            if($person['consent']!=='granted'){continue;}
            $bound=[];$judged=[];$surveys=[];$generated=0;$delivered=0;
            foreach($events as$e){
                if($e['participant_id']!==$person['id']){continue;}$d=$e['data'];
                if($e['kind']==='pilot.submission'&&$d['phase']==='practice'){$bound[$d['submission_id']]=$d;}
                if($e['kind']==='pilot.judged'&&$d['phase']==='practice'){$judged[$d['submission_id']]=$d;}
                if($e['kind']==='classroom.survey'){$surveys[$d['stage']]=$d;}
                if($e['kind']==='classroom.observation'){$observations[]=['participant'=>$person['pseudonym'],'arm'=>$person['arm']]+$d;}
                if($e['kind']==='feedback.generated'){++$generated;}
                if($e['kind']==='feedback.delivered'){++$delivered;}
            }
            $r=['participant'=>$person['pseudonym'],'class_code'=>self::groupCode($s,(int)$person['group_id']),
                'arm'=>$person['arm'],'level'=>$person['baseline']['level']??'insufficient',
                'before_responded'=>isset($surveys['before'])?1:0,'after_responded'=>isset($surveys['after'])?1:0,
                'submissions'=>count($bound),'valid_submissions'=>0,'accepted_submissions'=>0,'pending'=>0,'invalid'=>0,
                'attempted_units'=>0,'solved_units'=>0,'resubmissions'=>0,'paired_units'=>0,'first_errors'=>0,'last_errors'=>0,
                'solve_seconds_sum'=>0,'solve_time_n'=>0,'feedback_generated'=>$generated,'feedback_delivered'=>$delivered];
            $units=[];
            foreach($bound as$sid=>$binding){$units[$binding['lesson'].':'.$binding['problem_id']][$sid]=$judged[$sid]??null;}
            foreach($units as$attempts){
                ksort($attempts,SORT_NUMERIC);++$r['attempted_units'];$r['resubmissions']+=max(0,count($attempts)-1);$valid=[];$solved=false;$incomplete=false;
                foreach($attempts as$a){
                    if($a===null){++$r['pending'];$incomplete=true;continue;}
                    if(!in_array($a['result_code'],[4,5,6,7,8,9,10,11],true)){++$r['invalid'];$incomplete=true;continue;}
                    $valid[]=$a;++$r['valid_submissions'];
                    if($a['result_code']===4){++$r['accepted_submissions'];if(!$solved){++$r['solved_units'];$solved=true;}}
                }
                // Pending earlier submissions can still become the first accepted attempt.
                if(!$incomplete&&$solved){foreach($valid as$a){if($a['result_code']===4){$r['solve_seconds_sum']+=$a['elapsed_seconds'];++$r['solve_time_n'];break;}}}
                if(!$incomplete&&count($valid)>=2){++$r['paired_units'];$r['first_errors']+=(int)($valid[0]['result_code']!==4);$r['last_errors']+=(int)($valid[count($valid)-1]['result_code']!==4);}
            }
            $r['accepted_submission_rate']=$r['valid_submissions']?100*$r['accepted_submissions']/$r['valid_submissions']:null;
            $r['solve_seconds']=$r['solve_time_n']?$r['solve_seconds_sum']/$r['solve_time_n']:null;
            $r['practice_error_reduction']=$r['first_errors']?100*($r['first_errors']-$r['last_errors'])/$r['first_errors']:null;
            foreach(['before'=>$p['before_items'],'after'=>$p['survey_items']]as$stage=>$items){
                foreach($items as$i=>$item){$r[$stage.'_item_'.($i+1)]=$surveys[$stage]['answers'][$i]??null;}
                if(($surveys[$stage]['comment']??'')!==''){$comments[]=['participant'=>$person['pseudonym'],'stage'=>$stage,'comment'=>$surveys[$stage]['comment']];}
            }
            foreach(['pretest_score','posttest_score','gain','pretest_status','posttest_status']as$key){$r[$key]=$baseRows[$person['pseudonym']][$key];}
            $rows[]=$r;
        }
        $groups=[];foreach($p['groups']as$gid){$code=self::groupCode($s,$gid);$groups[$code]=self::aggregate(array_values(array_filter($rows,static fn($r)=>$r['class_code']===$code)));}
        $arms=[];$survey=[];
        foreach(['A','B']as$arm){$arms[$arm]=self::aggregate(array_values(array_filter($rows,static fn($r)=>$r['arm']===$arm)));}
        foreach(['before'=>$p['before_items'],'after'=>$p['survey_items']]as$stage=>$items){
            foreach($items as$i=>$item){foreach(['all','A','B']as$arm){
                $selected=$arm==='all'?$rows:array_values(array_filter($rows,static fn($r)=>$r['arm']===$arm));
                $values=array_column($selected,$stage.'_item_'.($i+1));$stats=PilotReport::stats($values);
                $responded=array_sum(array_column($selected,$stage.'_responded'));$distribution=array_fill(1,$p['survey_max'],0);
                foreach($values as$v){if($v!==null){++$distribution[$v];}}
                $survey[$stage][$i][$arm]=$stats+['item'=>$item,'eligible'=>count($selected),'responded'=>$responded,'na'=>$responded-$stats['n'],'missing'=>count($selected)-$responded,'distribution'=>$distribution];
            }}
        }
        $evidence=['rows'=>$rows,'observations'=>$observations,'comments'=>$comments,'survey'=>$survey];
        return ['schema'=>'group_classroom_report.v1','revision'=>$s['revision'],'phase'=>$s['status'],'rows'=>$rows,
            'total'=>self::aggregate($rows),'groups'=>$groups,'arms'=>$arms,'survey'=>$survey,'observations'=>$observations,'comments'=>$comments,
            'withdrawn'=>$base['withdrawn'],'erased'=>$base['erased'],'protocol_hash'=>$base['protocol_hash'],'data_hash'=>ResearchProtocol::hash($evidence),
            'assessment'=>['configured'=>$p['pretest']!==[]&&$p['posttest']!==[],'summary'=>$base['summary'],'analysis'=>$base['analysis']]];
    }

    public static function groupCode(array $s,int $group):string {return 'C-'.substr(hash('sha256',$s['id'].':'.$group),0,12);}
    private static function aggregate(array $rows):array
    {
        $a=['n'=>count($rows)];
        foreach(['before_responded','after_responded','submissions','valid_submissions','accepted_submissions','pending','invalid','attempted_units','solved_units','resubmissions','paired_units','first_errors','last_errors','solve_seconds_sum','solve_time_n','feedback_generated','feedback_delivered']as$key){$a[$key]=array_sum(array_column($rows,$key));}
        $a['accepted_submission_rate']=$a['valid_submissions']?100*$a['accepted_submissions']/$a['valid_submissions']:null;
        $a['solve_seconds']=$a['solve_time_n']?$a['solve_seconds_sum']/$a['solve_time_n']:null;
        $a['practice_error_reduction']=$a['first_errors']?100*($a['first_errors']-$a['last_errors'])/$a['first_errors']:null;
        return $a;
    }

    public static function sections(array $s,array $r):array
    {
        $p=$s['data']['protocol'];$n=static fn($k)=>$p['notes'][$k]?:'[연구자가 작성할 항목]';$f=static fn($v)=>PilotReport::number($v);$arms=[];$outcomes=[];$systems=[];$survey=[];$materials=[];$observations=[];
        foreach($p['arms']as$arm=>$a){$g=$r['arms'][$arm];
            $arms[]=[$arm.' · '.$a['name'],$g['n'],PilotProtocol::AI[$a['ai']],PilotProtocol::REC[$a['recommendation']],$a['minutes'].'분/문제',$a['conditions']?:'별도 기록 없음'];
            $systems[]=[$arm,$g['submissions'],$g['pending'],$g['invalid'],$g['feedback_generated'],$g['feedback_delivered']];
            $outcomes[]=[$arm,$g['n'],$g['solved_units'].' / '.$g['attempted_units'],$f($g['accepted_submission_rate']).' (n='.$g['valid_submissions'].')',$g['resubmissions'],$g['first_errors'].' → '.$g['last_errors'].' (짝 n='.$g['paired_units'].')',$f($g['practice_error_reduction']),$f($g['solve_seconds']).' (n='.$g['solve_time_n'].')'];
        }
        foreach(['before'=>'시작','after'=>'종료']as$stage=>$label){foreach($r['survey'][$stage]as$items){foreach(['A','B']as$arm){$v=$items[$arm];$survey[]=[$label,$arm,$v['item'],$v['responded'].' / '.$v['eligible'],$v['n'],$v['na'],$v['missing'],$f($v['mean']),$f($v['sd'])];}}}
        foreach(array_unique(array_merge($p['practice'],$p['pretest'],$p['posttest']))as$id){$m=$p['materials'][$id]??[];$materials[]=[$id,$m['topic']??'미등록',$m['difficulty']??'미등록',in_array($id,$p['practice'],true)?'수업':(in_array($id,$p['pretest'],true)?'시작 과제':'마무리 과제'),($m['reviewed']??false)?'검수 완료':'검수 미완료',$m['version']??'미등록',$m['errors']??'미등록'];}
        foreach($r['observations']as$o){$observations[]=[$o['participant'],$o['arm']??'배정 전',$o['lesson'],self::observationLabel($o['level_match']),self::observationLabel($o['teacher_help']),self::observationLabel($o['feedback_use']),self::observationLabel($o['issue']),$o['note']];}
        $sections=[
            ['title'=>'1. 현장적용 방법과 교육자료','paragraphs'=>[
                '보고 시점: '.ClassroomProtocol::phase($s).'. '.(in_array($s['status'],['completed','archived'],true)?'수업 종료 자료이며 설문 응답 및 판정 상태를 함께 확인한다.':'진행 중인 수업의 중간 자료이며 최종 결과가 아니다.'),
                '연구 질문: '.$n('question'),'적용 대상·환경: '.$n('environment'),'모집·프로그래밍 경험: '.$n('recruitment'),'적용 기간: '.$n('period').' / 계획 '.$p['lessons_count'].'차시, 진행 '.$s['data']['lesson'].'차시.',
                '학습 내용: '.$n('baseline_content'),'문제 데이터 구축: '.$n('dataset'),
                '기존 수준 분석 결과를 등록 시 보관하였다. 시작 설문은 자기보고이며 기존 수준을 재산정하지 않는다. 선행 수준 평가 근거: '.$n('citation'),
                '배정 방식: '.(['stratified'=>'기존 수준에 따른 층화 배정','random'=>'학생 무작위 배정','class'=>'그룹 단위 배정'][$p['allocation']]??$p['allocation']).'. 수업 시작 시 A·B를 한 번 배정한다. 시작 설문 무응답 학생도 동의·등록되어 있으면 포함하며 응답 여부는 별도로 집계한다.',
                '참여·보관 절차: '.$n('consent')],
                'tables'=>[['caption'=>'문제 자료 구성','headers'=>['문제','주제','난이도','용도','검수','버전','오류 유형'],'rows'=>$materials],['caption'=>'두 집단의 실제 조건','headers'=>['집단','참여 n','AI','문제 제공','풀이 제한','교사 지원 등'],'rows'=>$arms]]],
            ['title'=>'2. 자료 수집과 시스템 작동','paragraphs'=>[
                '학생은 /research에서 설문에 응답하고 풀이 시작을 선택한 후 기존 OJ 문제 화면에서 제출한다. 현재 활동에 연결된 제출·최종 판정만 분석한다. 보고서 조회 시 최신 판정을 반영하며 채점 대기는 점수 0으로 바꾸지 않는다.',
                'AI 생성 기록과 화면 전달 기록은 각각 집계한다. 전달은 학생의 실제 읽기·이해·활용을 증명하지 않는다. 실패 요청 전체 및 지연 시간 계측 자료가 없어 성공률·평균 응답 시간은 산출하지 않는다.',
                '재제출 횟수와 판정 변화는 확인할 수 있다. 코드 원문 간 차이와 수정 이유는 현재 자동 수집하지 않으므로 코드 수정의 질은 별도 표본 검토가 필요하다.',
                'AI 한도: 학생·활동별 피드백을 생성한 서로 다른 제출 '.($p['request_limit']===0?'무제한':$p['request_limit'].'개').'. 모델 호출·클릭 수 한도가 아니다.',
                '철회 '.$r['withdrawn'].'명, 삭제 '.$r['erased'].'명은 개인 결과에서 제외했다. 집계 단위와 결측은 각 표에 표시한다.'],
                'tables'=>[['caption'=>'시스템 기록 (학습 효과 지표와 구분)','headers'=>['집단','수업 제출','채점 대기','판정 확인 필요','AI 생성','AI 전달'],'rows'=>$systems]]],
            ['title'=>'3. 학생 설문: 경험·이해도·만족도','paragraphs'=>[
                '시작·종료 설문을 문항별로 분석한다. 척도는 1~'.$p['survey_max'].'점이며 높을수록 문장에 동의한다. 해당 없음/판단 어려움은 평균에서 제외하고 무응답과 구분한다. 서로 다른 구성개념을 합친 총점은 산출하지 않는다.',
                '현재 문항은 현장용 초안이며 검증된 심리척도가 아니다. 전문가가 문항의 내용 타당성을 검토하고 소수 학생에게 문장의 이해 여부를 확인한다. AI 미사용·미열람 학생은 관련 문항에서 해당 없음을 선택하도록 안내한다. AI 경험 문항의 유효 응답자와 모든 집단의 수업 만족도 응답자를 구분한다.',
                '만족도나 자기보고 이해도만으로 학습 성취 향상을 판단하지 않는다. 시작·종료에 같은 문항을 사용해도 응답 학생이 다를 수 있으므로 시점별 평균 차이를 개인별 향상으로 해석하지 않는다.'],
                'tables'=>[['caption'=>'설문 문항별 결과','headers'=>['시점','집단','문항','응답/대상','유효 n','해당 없음','무응답','평균','SD'],'rows'=>$survey],['caption'=>'자유 의견 (응답자가 기입한 개인 정보는 공유 전 확인)','headers'=>['참여 코드','시점','의견'],'rows'=>array_map(static fn($c)=>[$c['participant'],$c['stage']==='before'?'시작':'종료',$c['comment']],$r['comments'])]]],
            ['title'=>'4. 학습 수행 결과와 해석 범위','paragraphs'=>[
                '해결 단위는 학생×차시×문제이며, 시도한 단위 중 정답이 한 번 이상 나온 단위를 센다. 미시도 문제를 해결률 분모에 넣지 않는다. 제출 정답률은 유효 판정(4~11) 제출 중 정답 제출 비율이며 동일 학생의 반복 제출을 포함한다.',
                '최초→최종 오류는 유효 판정 2회 이상이고 대기·확인 필요 판정이 없는 같은 학습 단위를 짝지어 비교한다. 오류 감소율=(최초 오류 수−최종 오류 수)/최초 오류 수×100이다. 최초 오류 0일 때 미산출한다. 이는 같은 문제의 반복 풀이 변화이며 독립적 성취 향상과 다르다.',
                '해결 시간은 최초 정답 제출까지 '.($p['pause_time']==='exclude'?'일시 중단을 제외한 활동 시간':'경과 시간').'의 평균이다. 미해결·판정 미확정 단위는 시간 평균에서 제외한다. n은 시간 자료가 있는 해결 단위 수이다.',
                $r['assessment']['configured']?'시작·마무리 과제를 설정했다. 아래 성취 점수는 평가 완료 확인과 최종 판정이 모두 확보된 학생만 포함한다. 문항 동형성·사전 차이·교사 지원을 검토해야 한다.':'별도의 시작·마무리 과제 쌍을 설정하지 않았다. 수업 중 수행과 인식은 기술할 수 있으나 독립 성취의 상승 폭은 산출하지 않는다.',
                '교사 지원·반복 연습·수업 밖 경험은 결과에 영향을 줄 수 있다. 두 집단 간 기술적 차이만으로 수준별 피드백의 인과 효과를 입증하거나 전체 학생으로 일반화하지 않는다. 교사 지원 계획: '.$n('teacher'),
                '분석·결측 계획: '.$n('analysis').' / '.$n('missing')],
                'tables'=>[['caption'=>'집단별 수업 수행 (기술통계)','headers'=>['집단','학생 n','해결/시도 단위','제출 정답률 %','재제출','짝지은 오류','오류 감소 %','해결 초'],'rows'=>$outcomes]]],
            ['title'=>'5. 교사 관찰과 수준·피드백의 현장 적합성','paragraphs'=>[
                '교사는 학생별 관찰 당시 차시와 함께 수준 일치, 도움, 피드백 이해, 운영 문제를 기록한다. 관찰이 없으면 적합으로 간주하지 않는다. 동일 학생의 반복 관찰은 독립된 학생 수가 아니다.',
                '수준 비교의 기준은 등록 시 저장한 기존 수준이다. 관찰이 평가 알고리즘이나 학생 수준을 자동 변경하지 않는다. 피드백의 적절성은 생성·전달 횟수와 별도로 실제 응답 표본·학생 수행·교사 관찰을 종합하여 검토한다.'],
                'tables'=>[['caption'=>'교사 관찰 원자료','headers'=>['참여 코드','집단','차시','초기 수준과 비교','교사 도움','피드백 활용','운영 문제','메모'],'rows'=>$observations]]],
            ['title'=>'6. 전문가 자문 요청서','paragraphs'=>[
                '자문 질문: 확보한 자료로 어느 범위까지 해석할 수 있는가? 시스템 작동, 학생 만족도, 학습 수행 및 효과를 구분하여 검토해 주십시오. 아래 의견란은 전문가 작성용이며 자동 생성한 평가가 아닙니다.',
                '검토 자료 식별: 연구 '.$s['id'].' / 설정 버전 '.$s['revision'].' / 조건 해시 '.$r['protocol_hash'].' / 결과 해시 '.$r['data_hash'],
                '전문가 성명·전문 분야·검토일: [작성]'],
                'tables'=>[['caption'=>'자문 항목과 검토 요청','headers'=>['항목','검토 질문','참조','전문가 의견·보완 요청'],'rows'=>[
                    ['현장적용 방법의 적절성','경험·기간·차시·학습 내용·활용 방식이 연구 목적에 적절한가?','1절 / 시작 설문','[작성]'],
                    ['자료 수집 및 분석의 적절성','제출·피드백·재제출·설문·관찰 자료와 분모·결측 처리가 충분한가?','2~5절','[작성]'],
                    ['수준 분석 및 피드백의 현장 적합성','기존 수준이 실제 수행·교사 관찰과 부합하고 피드백이 이해·해결에 도움이 되는가?','3·5절 / 실제 피드백 표본 별첨','[작성]'],
                    ['현장적용 결과 해석의 타당성','자료가 뒷받침하는 결론의 범위는 어디까지인가? 교사 도움·반복 연습·일반화 제한을 고려했는가?','4절','[작성]'],
                    ['종합 의견 및 개선 방향','실제 활용 가능성, 운영 어려움과 수준·피드백·수업 방식의 보완 사항은 무엇인가?','전체','[작성]']]]]],
        ];
        if($r['assessment']['configured']){$scoreRows=[];foreach(['A','B']as$a){$v=$r['assessment']['summary'][$a];$scoreRows[]=[$a,$f($v['pretest_score']['mean']).' (n='.$v['pretest_score']['n'].')',$f($v['posttest_score']['mean']).' (n='.$v['posttest_score']['n'].')',$f($v['gain']['mean']).' (짝 n='.$v['gain']['n'].')'];}$sections[3]['tables'][]=['caption'=>'별도 과제 성취 (100점 척도)','headers'=>['집단','시작','마무리','변화 %p'],'rows'=>$scoreRows];}
        $groupRows=[];foreach($r['groups']as$code=>$g){$groupRows[]=[$code,$g['n'],$g['before_responded'],$g['after_responded'],$g['solved_units'].' / '.$g['attempted_units'],$g['pending']];}
        $sections[0]['tables'][]=['caption'=>'그룹별 참여·진행 (공유용 익명 그룹 코드)','headers'=>['그룹','학생 n','시작 응답','종료 응답','해결/시도 단위','채점 대기'],'rows'=>$groupRows];
        $guideRows=[];foreach($s['data']['guides']??[]as$level=>$guide){$guideRows[]=[$level,$guide['key']??'',(string)($guide['revision']??''),$guide['instructions']??''];}
        $sections[4]['tables'][]=['caption'=>'등록 시 보관한 수준별 피드백 지침','headers'=>['수준','지침 키','버전','지침 내용'],'rows'=>$guideRows];
        $sections[4]['paragraphs'][]='피드백의 수준 참조: '.($p['level_timing']==='baseline'?'등록 시 보관한 수준':'요청 당시 기존 시스템의 수준 결과').'. 공통 AI용 실제 프롬프트 (공통 AI 조건에만 전달, 수준별 AI는 보관한 수준별 지침 사용): '.$p['common_instructions'];
        $routing=$s['data']['routing']??[];
        $sections[4]['paragraphs'][]='보관한 AI 연결: '.($routing['primary_profile_key']??'미등록').' / 연결 버전 '.($routing['routing_revision']??'미등록').'.';
        $sections[3]['paragraphs'][]='선택 과제 동형성 검토: '.$n('equivalence').' / 추가 조건: '.$n('additional');
        return $sections;
    }

    public static function observationLabel(string $key):string
    {
        return ['unobserved'=>'미관찰','matches'=>'초기 수준과 부합','higher'=>'실제 수행이 더 높음','lower'=>'실제 수행이 더 낮음','none'=>'없음','some'=>'일부 도움','much'=>'많은 도움','understood'=>'이해·활용 관찰','difficult'=>'이해 어려움','unused'=>'사용하지 않음','ai_delay'=>'AI 지연','ai_error'=>'AI 오류','submission'=>'제출 문제','other'=>'기타'][$key]??$key;
    }
}
