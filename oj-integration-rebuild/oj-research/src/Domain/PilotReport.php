<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Domain;

/** Reporting is read-only: only explicitly bound, finally judged OJ submissions count. */
final class PilotReport
{
    public static function build(array $study,array $people,array $events):array
    {
        $p=$study['data']['protocol'];$rows=[];$withdrawn=0;$withdrawnArms=['A'=>0,'B'=>0,'unassigned'=>0];
        foreach($people as$person){
            if($person['consent']!=='granted'){++$withdrawn;++$withdrawnArms[$person['arm']??'unassigned'];continue;}
            $judged=[];$bound=[];$finished=[];$survey=null;$feedback=0;$delivered=0;
            foreach($events as$e){if($e['participant_id']!==$person['id']){continue;}$d=$e['data'];
                if($e['kind']==='pilot.submission'){$bound[$d['submission_id']]=$d;}
                if($e['kind']==='pilot.judged'){$judged[$d['submission_id']]=$d;}
                if($e['kind']==='pilot.finished'){$finished[$d['phase']]=true;}
                if($e['kind']==='pilot.survey'){$survey=array_sum($d['answers'])/count($d['answers']);}
                if($e['kind']==='feedback.generated'){++$feedback;}
                if($e['kind']==='feedback.delivered'){++$delivered;}
            }
            $row=['participant'=>$person['pseudonym'],'arm'=>$person['arm'],'level'=>$person['baseline']['level']??'insufficient',
                'level_policy'=>$person['baseline']['policy_version']??'', 'satisfaction'=>$survey,'feedback_count'=>$feedback,
                'feedback_delivered_count'=>$delivered,
                'class_code'=>isset($person['group_id'])?'C-'.substr(hash('sha256',($study['id']??0).':'.$person['group_id']),0,12):''];
            foreach(['pretest','posttest']as$phase){
                $phaseBound=array_filter($bound,static fn($d)=>$d['phase']===$phase);
                $phaseJudged=array_filter($judged,static fn($d)=>$d['phase']===$phase);
                $pending=count(array_diff_key($phaseBound,$phaseJudged));
                $invalid=count(array_filter($phaseJudged,static fn($d)=>!in_array($d['result_code'],[4,5,6,7,8,9,10,11],true)));
                $score=null;$times=[];$errors=[];$sum=0;$partialMissing=false;
                foreach($p[$phase]as$problemId){
                    $attempts=array_values(array_filter($phaseJudged,static fn($d)=>$d['problem_id']===$problemId&&in_array($d['result_code'],[4,5,6,7,8,9,10,11],true)));
                    usort($attempts,static fn($a,$b)=>$a['submission_id']<=>$b['submission_id']);
                    $values=[];$solved=false;
                    foreach($attempts as$a){
                        if(!$solved&&$a['result_code']===4){$times[]=$a['elapsed_seconds'];$solved=true;}
                        if($p['scoring']==='pass_rate'&&$a['result_code']!==4&&$a['pass_rate']===null){$partialMissing=true;}
                        $values[]=$a['result_code']===4?1:($p['scoring']==='pass_rate'?($a['pass_rate']??0):0);
                    }
                    $sum+=($values===[]?0:max($values));
                    foreach($p['errors']==='first'?array_slice($attempts,0,1):$attempts as$a){$errors[]=$a['result_code']===4?0:1;}
                }
                $valid=isset($finished[$phase])&&$pending===0&&$invalid===0&&!$partialMissing;
                if($valid&&count($p[$phase])>0){$score=100*$sum/count($p[$phase]);}
                $row[$phase.'_score']=$score;
                $row[$phase.'_status']=$pending>0?'채점 대기':($invalid>0?'판정 확인 필요':($partialMissing?'부분점수 누락':(isset($finished[$phase])?'평가 완료':'최종 제출 없음')));
                $row[$phase.'_errors']=$valid&&$errors!==[]?100*array_sum($errors)/count($errors):null;
                $row[$phase.'_error_n']=$valid?count($errors):0;
                $row[$phase.'_seconds']=$valid&&$times!==[]?array_sum($times)/count($times):null;
                $row[$phase.'_solved_time_n']=$valid?count($times):0;
            }
            $row['gain']=$row['pretest_score']!==null&&$row['posttest_score']!==null?$row['posttest_score']-$row['pretest_score']:null;
            $row['error_reduction']=$row['pretest_errors']!==null&&$row['pretest_errors']>0&&$row['posttest_errors']!==null
                ?100*($row['pretest_errors']-$row['posttest_errors'])/$row['pretest_errors']:null;
            $rows[]=$row;
        }
        $summary=[];
        foreach(['A','B']as$arm){$selected=array_values(array_filter($rows,static fn($r)=>$r['arm']===$arm));
            $summary[$arm]=['n'=>count($selected)];
            foreach(['pretest_score','posttest_score','gain','error_reduction','posttest_seconds','satisfaction']as$key){$summary[$arm][$key]=self::stats(array_column($selected,$key));}
        }
        return ['schema'=>'classroom_pilot_report.v3','revision'=>$study['revision'],'phase'=>$study['status'],'rows'=>$rows,'summary'=>$summary,
            'protocol_hash'=>$study['data']['protocol_hash']??ResearchProtocol::hash($p),
            'analysis_settings'=>['design'=>$p['design']??'custom','allocation'=>$p['allocation'],'covariance'=>$p['covariance']??'auto',
                'level_timing'=>$p['level_timing'],'scoring'=>$p['scoring'],'arms'=>$p['arms'],'assessment_support'=>$p['support']],
            'analysis'=>PilotAnalysis::build($p,$rows),'withdrawn_by_arm'=>$withdrawnArms,
            'withdrawn'=>$withdrawn,'erased'=>$study['data']['erased_count']??0,'data_hash'=>ResearchProtocol::hash($rows)];
    }
    public static function stats(array $values):array
    {
        $values=array_values(array_filter($values,static fn($v)=>$v!==null));$n=count($values);
        if($n===0){return ['n'=>0,'mean'=>null,'sd'=>null];}$mean=array_sum($values)/$n;
        return ['n'=>$n,'mean'=>$mean,'sd'=>$n>1?sqrt(array_sum(array_map(static fn($v)=>($v-$mean)**2,$values))/($n-1)):null];
    }
    public static function number(?float $v):string {return $v===null?'미수집':number_format($v,2);}
    public static function sections(array $s,array $report):array
    {
        $p=$s['data']['protocol'];$note=static fn($key)=>$p['notes'][$key]!==''?$p['notes'][$key]:'[연구자가 작성할 항목]';
        $materials=[];
        foreach(array_unique(array_merge($p['practice'],$p['pretest'],$p['posttest']))as$id){$m=$p['materials'][$id]??[];
            $materials[]=[(string)$id,$m['problem']['title']??'자료 미등록',$m['topic']??'미정',$m['difficulty']??'미정',
                in_array($id,$p['practice'],true)?'연습':'평가',($m['reviewed']??false)?'검수 완료':'검수 대기',$m['version']??'미정'];
        }
        $arms=[];
        foreach(['A','B']as$arm){$g=$p['arms'][$arm];$arms[]=[$arm.' · '.$g['name'],PilotProtocol::AI[$g['ai']],PilotProtocol::REC[$g['recommendation']],
            $p['lessons_count'].'차시 / 문제별 '.$g['minutes'].'분',$g['conditions']?:'공통 조건 적용'];}
        $outcomes=[];
        foreach(['A','B']as$arm){$g=$report['summary'][$arm];$r=[$p['arms'][$arm]['name'],(string)$g['n']];
            foreach(['pretest_score','posttest_score','gain','error_reduction','posttest_seconds','satisfaction']as$key){$v=$g[$key];$r[]=self::number($v['mean']).' (n='.$v['n'].', SD='.self::number($v['sd']).')';}$outcomes[]=$r;}
        $feedback=[];
        foreach(['beginner'=>'초급','intermediate'=>'중급','advanced'=>'고급','insufficient'=>'자료 부족']as$key=>$label){$g=$s['data']['guides'][$key]??null;
            $feedback[]=[$label,$g['key']??'초기 자료 저장 시 연결',(string)($g['revision']??'미정'),$g['instructions']??'기존 OJ의 활성 수준별 지침 사용'];}
        return array_merge([
            ['title'=>'1. 교육자료 개발 및 데이터 구축','paragraphs'=>[
                '본 연구는 기존 온라인 채점 시스템(OJ)의 알고리즘 문제를 학습 자료로 활용한다. '.$note('dataset'),
                '연습 문제 '.count($p['practice']).'개와 사전·사후 평가 문제를 분리하여 구성하였다. 문제별 정답 코드, 해설, 오류 유형, 출처, 검수 상태와 버전을 기록하고, 검수를 완료한 자료만 AI 피드백의 근거로 사용한다.'],
                'tables'=>[['caption'=>'표 1. 문제 데이터셋 구성','headers'=>['문제 번호','제목','주제','난이도','용도','검수','자료 버전'],'rows'=>$materials],
                    ['caption'=>'표 2. 수준별 피드백 지침','headers'=>['수준','지침','버전','지원 방식'],'rows'=>$feedback]]],
            ['title'=>'2. 연구 대상 및 사전 절차','paragraphs'=>[$note('environment'),$note('recruitment'),
                '기본 알고리즘 학습 내용: '.$note('baseline_content'),
                '기존 학생 수준 평가 방법을 변경하지 않고 그 결과를 초기 자료로 보관한다. 선행 연구: '.$note('citation'),
                '사전 평가 종료 후 두 집단을 '.(['stratified'=>'수준별 층화 배정','random'=>'학생 단위 무작위 배정','class'=>'학급 단위 배정','manual'=>'교사 수동 배정'][$p['allocation']]).'으로 구성한다. 배정 가중치는 A:B = '.$p['arms']['A']['weight'].':'.$p['arms']['B']['weight'].'이다. 실제 인원은 결과 표에 제시한다.',
                '사전·사후 문항 동형성: '.$note('equivalence'),'연구 참여 및 자료 관리: '.$note('consent')],
                'tables'=>[['caption'=>'표 3. 공통 평가 지원 조건','headers'=>['평가','A 집단 AI','B 집단 AI','제한 시간'],'rows'=>[
                    ['사전',PilotProtocol::AI[$p['support']['pretest']['A']],PilotProtocol::AI[$p['support']['pretest']['B']],'문제별 '.$p['test_minutes'].'분'],
                    ['사후',PilotProtocol::AI[$p['support']['posttest']['A']],PilotProtocol::AI[$p['support']['posttest']['B']],'문제별 '.$p['test_minutes'].'분']]]]],
            ['title'=>'3. 수업 적용 및 처치','paragraphs'=>[$note('question'),'연구 기간 및 일정: '.$note('period'),
                '학생은 기존 OJ 문제와 코드 제출 화면에서 활동한다. 수준별 피드백은 '.($p['level_timing']==='baseline'?'초기 저장한 수준':'요청 시점에 저장된 기존 수준 결과').'에 맞는 지침을 선택하며, 모든 학생에게 동일한 힌트를 순차 공개하는 방식이 아니다.',
                'AI 요청 한도는 활동별 '.($p['request_limit']===0?'무제한':$p['request_limit'].'개의 제출').'이다. 연구 시작 시 AI 연결·지침·문제 내용의 버전을 고정한다. 공용 AI 게이트웨이를 사용하는 별도 애드온 AI는 평가·수업 중 차단한다.',
                '교사 지원과 해설 공개: '.$note('teacher'),'추가 연구 조건: '.$note('additional')],
                'tables'=>[['caption'=>'표 4. 두 집단의 수업 조건','headers'=>['집단','AI 피드백','문제 제공','적용량','추가 조건'],'rows'=>$arms]]],
            ['title'=>'4. 자료 수집 및 결과 분석','paragraphs'=>[
                '정답률은 공통 평가 문항을 기준으로 산출한다. 채점 방식은 '.($p['scoring']==='ac'?'전 테스트 통과 문항의 비율':'문항별 최대 테스트 통과 비율의 평균').'이며 100점 척도로 환산한다. 학생의 평가 완료 확인이 없거나 판정이 미확정인 경우 점수를 결측으로 남긴다.',
                '오류율은 '.($p['errors']==='first'?'문항별 최초 유효 제출':'모든 유효 제출').' 중 정답이 아닌 제출의 비율이다. 오류 감소율은 (사전 오류율−사후 오류율)/사전 오류율×100으로 산출하며, 사전 오류율 0은 계산하지 않는다.',
                '문제 해결 시간은 풀이 시작부터 최초 정답 코드 제출까지의 '.($p['pause_time']==='exclude'?'일시 중단을 제외한 활성 시간':'경과 시간').'이다. 미해결 문제는 시간 평균에서 제외하며 해결 문항 수를 함께 내보낸다. 채점기의 실행 시간을 풀이 시간으로 사용하지 않는다.',
                '만족도는 두 집단에 동일한 '.$p['survey_max'].'점 척도 '.count($p['survey_items']).'문항을 제공하여 학생별 평균을 산출한다. 무응답은 결측이다.',
                '분석 계획: '.$note('analysis'),'결측 처리 계획: '.$note('missing'),
                '아래 값은 수집된 자료의 기술통계이다. 변화량은 사전·사후 점수가 모두 있는 학생으로 계산한다. 이어지는 효과 분석에서 사전 성취와 초기 수준을 보정한다. 연구 설계의 타당성이나 인과 관계는 자동 판정하지 않는다.',
                '철회 '.$report['withdrawn'].'명, 삭제 '.$report['erased'].'명. 보고서 데이터 해시: '.$report['data_hash']],
                'tables'=>[['caption'=>'표 5. 집단별 실제 수집 결과','headers'=>['집단','배정 n','사전 점수','사후 점수','변화량 (%p)','오류 감소 (%)','사후 해결 시간 (초)','만족도'],'rows'=>$outcomes],
                    ['caption'=>'표 6. 공통 만족도 문항','headers'=>['번호','문항','척도'],'rows'=>array_map(static fn($i,$item)=>[(string)($i+1),$item,'1~'.$p['survey_max']],array_keys($p['survey_items']),$p['survey_items'])]]]
        ],PilotAnalysisReport::sections($s,$report));
    }
    public static function markdown(array $s,array $report):string
    {
        $out='# '.$s['title']."\n\n연구 보고서 · 현재 저장 자료 기준 · 연구 버전 ".$s['revision']."\n\n";
        $escape=static fn($v)=>str_replace(["|","\r","\n"],["\\|",'', '<br>'],(string)$v);
        foreach(self::sections($s,$report)as$section){$out.='## '.$section['title']."\n\n".implode("\n\n",$section['paragraphs'])."\n\n";
            foreach($section['tables']as$t){$out.='### '.$t['caption']."\n\n| ".implode(' | ',array_map($escape,$t['headers']))." |\n| ".implode(' | ',array_fill(0,count($t['headers']),'---'))." |\n";
                foreach($t['rows']as$row){$out.='| '.implode(' | ',array_map($escape,$row))." |\n";}$out.="\n";}}
        return $out;
    }
}
