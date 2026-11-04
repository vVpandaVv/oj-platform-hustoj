<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Domain;

/** Structured classroom plans, with free text retained for older studies. */
final class ClassroomSettings
{
    // label, options, example default, report note
    public const CHOICES = [
        'grade'=>['대상 학년',['middle1'=>'중학교 1학년','middle2'=>'중학교 2학년','middle3'=>'중학교 3학년','mixed'=>'여러 학년','custom'=>'직접 작성'],'middle2','environment'],
        'experience'=>['프로그래밍 경험',['beginner'=>'기초 수업을 배운 입문 학생','none'=>'프로그래밍 경험 없음','mixed'=>'경험 수준이 섞여 있음','experienced'=>'문제 풀이 경험 있음','custom'=>'직접 작성'],'beginner','environment'],
        'language'=>['사용 언어',['python'=>'Python','cpp'=>'C / C++','java'=>'Java','blocks'=>'블록 프로그래밍','custom'=>'직접 작성'],'python','environment'],
        'device'=>['수업 기기',['pc'=>'컴퓨터실 · 1인 1PC','laptop'=>'1인 1노트북','shared'=>'기기 공동 사용','remote'=>'개인 기기 · 원격 수업','custom'=>'직접 작성'],'pc','environment'],
        'duration'=>['적용 기간',['w1'=>'1주','w2'=>'2주','w4'=>'4주','w6'=>'6주','w8'=>'8주','custom'=>'직접 작성'],'w4','period'],
        'inclusion'=>['참여 대상',['class'=>'선택한 그룹 중 동의를 확인한 학생','basic'=>'기본 알고리즘 학습을 마치고 동의한 학생','custom'=>'직접 작성'],'basic','recruitment'],
        'teacher_help'=>['두 집단 공통 교사 도움',['concept'=>'개념·오류 확인 질문 제공, 정답 코드 제외','operation'=>'접속·제출 등 시스템 사용만 지원','free'=>'필요에 따라 자유롭게 지원하고 관찰 기록','custom'=>'직접 작성'],'concept','teacher'],
        'explanation'=>['정답·해설 공개 시점',['after'=>'마무리 과제와 종료 설문 후','lesson'=>'해당 차시가 끝난 뒤','during'=>'풀이 중 필요할 때','none'=>'연구 수업 중 공개하지 않음','custom'=>'직접 작성'],'after','teacher'],
        'external_ai'=>['OJ 밖 AI 도구 사용 규칙',['none'=>'사용하지 않도록 안내','record'=>'허용하되 사용 여부를 관찰 기록','custom'=>'직접 작성'],'none','teacher'],
        'consent_method'=>['참여 동의 확인 방식',['pending'=>'아직 정하지 않음','paper'=>'종이 동의서로 확인','electronic'=>'전자 동의서로 확인','school'=>'학교에서 정한 절차로 확인','written'=>'직접 절차 작성 · 기존 기록 유지'],'pending','consent'],
        'storage'=>['자료 보관 장소',['pending'=>'아직 정하지 않음','school'=>'학교의 접근 제한 저장소','encrypted'=>'암호화한 연구자 PC / 저장 장치','institution'=>'소속 기관의 연구자료 저장소','custom'=>'기타 · 아래에 구체적으로 작성'],'pending','consent'],
        'access'=>['자료 열람 담당',['pending'=>'아직 정하지 않음','teacher'=>'담당 교사 / 연구자만','team'=>'승인된 공동 연구자','custom'=>'기타 · 아래에 구체적으로 작성'],'pending','consent'],
        'retention'=>['자료 보관 기간',['pending'=>'아직 정하지 않음','report'=>'연구 종료·보고서 제출 시까지','1y'=>'연구 종료 후 1년','3y'=>'연구 종료 후 3년','5y'=>'연구 종료 후 5년','custom'=>'기관 기준 또는 별도 기간 · 아래에 작성'],'pending','consent'],
        'disposal'=>['보관 기간이 끝난 뒤',['pending'=>'아직 정하지 않음','destroy'=>'전자 자료 삭제 · 종이 자료 파쇄','institution'=>'기관의 폐기 절차 적용','custom'=>'기타 · 아래에 구체적으로 작성'],'pending','consent'],
        'withdrawal'=>['참여 철회 접수',['pending'=>'아직 정하지 않음','teacher'=>'담당 교사에게 요청','researcher'=>'연구 담당자에게 요청','custom'=>'기타 · 아래에 구체적으로 작성'],'pending','consent'],
    ];
    public const TOPICS = ['io'=>'입출력·변수','condition'=>'조건문','loop'=>'반복문','list'=>'리스트·배열','function'=>'함수','search'=>'탐색·정렬'];
    public const METRICS = ['accuracy'=>'정답률 변화','errors'=>'오류 감소율','time'=>'문제 해결 시간','satisfaction'=>'학습 만족도','achievement'=>'별도 사전·사후 성취'];

    public static function examples(): array
    {
        return [
            'question'=>PilotDesign::QUESTIONS['adaptation'],
            'environment'=>'', 'period'=>'두 집단에 동일한 학습 시간과 문제를 제공할 계획이다.',
            'recruitment'=>'참여하지 않거나 철회한 학생에게도 정규 수업의 학습 기회를 제공한다.',
            'citation'=>'기존 연구에서 개발한 학생 수준 분석 기능을 그대로 사용한다. 실제 연구자·발표 연도·논문명은 확인하여 보완한다.',
            'baseline_content'=>'수업 전 기본 알고리즘 개념을 학습하고 기존 수준 분석 결과를 시작 시점에 저장한다.',
            'dataset'=>'기존 OJ 문제를 주제·난이도·오류 유형으로 분류하고 정답 코드와 해설을 준비한다. 수업 적용 전에 교사가 자료를 검토한다.',
            'equivalence'=>'별도 성취 과제를 사용하는 경우 사전·사후 과제의 개념 범위·난이도·채점 기준을 맞추고 검토 근거를 기록한다.',
            'teacher'=>'교사 도움과 반복 연습이 결과에 영향을 줄 수 있으므로 관찰 기록에 남긴다.',
            'analysis'=>'시스템 작동, 학생 경험, 학습 수행을 구분해 집단별로 정리한다. 별도 성취 과제가 있으면 사전 수준과 집단 배정 방식을 고려해 변화를 분석한다. 관찰된 차이를 곧바로 인과적 효과로 해석하지 않는다.',
            'missing'=>'설문 무응답과 해당 없음을 구분한다. 참여 철회 자료는 분석에서 제외한다. 미해결·채점 대기와 성취 과제 미완료를 구분해 보고한다.',
            'consent'=>'',
            'additional'=>'현장적용 방법, 자료 수집, 수준 분석·피드백 적합성, 결과 해석 범위에 대해 전문가 자문을 받을 계획이다.',
        ];
    }

    public static function forForm(array $p, bool $new): array
    {
        if (isset($p['setup'])) { return $p['setup']; }
        $v=['choices'=>[], 'topics'=>$new?['io','condition','loop']:[], 'metrics'=>$new?['accuracy','errors','time','satisfaction']:[],
            'reference'=>'','storage_detail'=>'','start_date'=>'','end_date'=>'','notes'=>$new?self::examples():$p['notes'],
            'arm_conditions'=>['A'=>$new?'':$p['arms']['A']['conditions'],'B'=>$new?'':$p['arms']['B']['conditions']]];
        foreach(self::CHOICES as $key=>$field){$v['choices'][$key]=$new?$field[2]:'';}
        if(!$new){$v['choices']['consent_method']='written';}
        return $v;
    }

    public static function validate(array $v): array
    {
        foreach(['choices','notes','arm_conditions']as$key){if(isset($v[$key])&&!is_array($v[$key])){throw new \DomainException('수업 조건 형식을 확인해 주세요.');}}
        $out=['choices'=>[]];
        foreach(self::CHOICES as$key=>$field){$out['choices'][$key]=PilotProtocol::choice($v['choices'][$key]??'',array_merge([''],array_keys($field[1])));}
        foreach(['topics'=>self::TOPICS,'metrics'=>self::METRICS]as$key=>$options){
            $values=$v[$key]??[];
            if(!is_array($values)||count($values)>count($options)){throw new \DomainException('체크한 항목을 확인해 주세요.');}
            $out[$key]=array_values(array_unique(array_map(static fn($value)=>PilotProtocol::choice($value,array_keys($options)),$values)));
        }
        foreach(['reference','storage_detail','start_date','end_date']as$key){$out[$key]=PilotProtocol::text($v[$key]??'', $key==='storage_detail'?2000:200);}
        foreach(['start_date','end_date']as$key){if($out[$key]!==''&&(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$out[$key],$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))){throw new \DomainException('수업 날짜를 확인해 주세요.');}}
        if($out['start_date']!==''&&$out['end_date']!==''&&$out['start_date']>$out['end_date']){throw new \DomainException('종료일은 시작일 이후로 선택해 주세요.');}
        foreach(PilotProtocol::NOTES as$key=>$label){$out['notes'][$key]=PilotProtocol::text($v['notes'][$key]??'');}
        foreach(['A','B']as$arm){$out['arm_conditions'][$arm]=PilotProtocol::text($v['arm_conditions'][$arm]??'');}
        return $out;
    }

    public static function apply(array $p,array $v): array
    {
        $v=self::validate($v);$p['setup']=$v;$parts=[];
        if(in_array($v['notes']['question'],PilotDesign::QUESTIONS,true)){
            $v['notes']['question']=PilotDesign::QUESTIONS[$p['design']];$p['setup']=$v;
        }
        foreach(self::CHOICES as$key=>[$label,$options,$default,$note]){
            $value=$v['choices'][$key];
            if($value!==''&&$value!=='written'){$parts[$note][]=$label.': '.$options[$value];}
        }
        if($v['topics']!==[]){$parts['baseline_content'][]='학습 주제: '.implode(', ',array_map(static fn($x)=>self::TOPICS[$x],$v['topics']));}
        if($v['metrics']!==[]){$parts['analysis'][]='중점 검토 지표: '.implode(', ',array_map(static fn($x)=>self::METRICS[$x],$v['metrics']));}
        foreach(['start_date'=>'시작일','end_date'=>'종료일']as$key=>$label){if($v[$key]!==''){$parts['period'][]=$label.': '.$v[$key];}}
        if($v['reference']!==''){$parts['consent'][]='동의서 식별명 (교사 입력): '.$v['reference'];}
        if($v['storage_detail']!==''){$parts['consent'][]='보관·철회 세부 계획: '.$v['storage_detail'];}
        foreach(PilotProtocol::NOTES as$key=>$label){
            $auto=isset($parts[$key])?'설정한 계획 — '.implode(' / ',$parts[$key]).'.':'';
            $p['notes'][$key]=trim($auto."\n".$v['notes'][$key]);
        }
        $support=isset($parts['teacher'])?'수업 운영 규칙 — '.implode(' / ',$parts['teacher']).'.':'';
        foreach(['A','B']as$arm){$p['arms'][$arm]['conditions']=trim($support."\n".$v['arm_conditions'][$arm]);}
        return $p;
    }

    public static function requireReady(array $p): void
    {
        if(!isset($p['setup'])){return;}
        $v=$p['setup'];$choices=$v['choices'];
        if($choices['consent_method']==='written'){
            if($v['notes']['consent']===''){throw new \DomainException('직접 작성한 동의 확인·자료 보관·철회 절차를 입력해 주세요.');}
            return;
        }
        foreach(self::CHOICES as$key=>[$label,$options,$default,$note]){
            if($note==='consent'&&in_array($choices[$key],['','pending'],true)){throw new \DomainException('시작 설문을 열기 전에 «'.$label.'»을 선택해 주세요. 설정만 먼저 저장할 수 있습니다.');}
            if($note==='consent'&&$choices[$key]==='custom'&&$v['storage_detail']===''){throw new \DomainException('기타로 선택한 보관·철회 절차를 구체적으로 적어 주세요.');}
        }
    }
}
