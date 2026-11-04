<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Domain;

use DomainException;

/** Independently readable report, advisory form and appendices share one evidence snapshot. */
final class ClassroomDocuments
{
    public const CATALOG = [
        'report'=>['title'=>'현장적용 결과 보고서','description'=>'적용 방법, 주요 결과와 해석 범위를 정리한 본문입니다.','category'=>'본문'],
        'advisory'=>['title'=>'전문가 자문 요청서','description'=>'검토 항목 5개와 전문가 의견 작성란을 제공합니다.','category'=>'자문'],
        'surveys'=>['title'=>'시작·종료 설문지','description'=>'설정한 실제 문항과 응답 척도, 자유 의견란입니다.','category'=>'첨부자료'],
        'protocol'=>['title'=>'수업 운영 조건·AI 지침','description'=>'배정·시간·AI 한도·평가 지원 및 보관한 지침입니다.','category'=>'첨부자료'],
        'materials'=>['title'=>'문제 자료 목록·검수 기록','description'=>'주제·난이도·용도·버전과 자료 구축 근거입니다.','category'=>'첨부자료'],
        'results'=>['title'=>'결과 상세표·지표 설명','description'=>'그룹·집단·설문 문항별 결과와 결측·분모를 확인합니다.','category'=>'첨부자료'],
        'observations'=>['title'=>'교사 관찰·학생 자유 의견','description'=>'기록한 현장 장면과 의견을 원자료 형태로 확인합니다.','category'=>'첨부자료'],
    ];

    public static function title(string $document):string
    {
        if(!isset(self::CATALOG[$document])){throw new DomainException('열 수 없는 보고서 자료입니다.');}
        return self::CATALOG[$document]['title'];
    }

    public static function sections(array $s,array $r,string $document):array
    {
        self::title($document);$p=$s['data']['protocol'];$all=ClassroomReport::sections($s,$r);
        $table=static function(string $caption)use($all):array {
            foreach($all as$section){foreach($section['tables']as$t){if($t['caption']===$caption){return $t;}}}
            throw new \LogicException('Missing document table: '.$caption);
        };
        $stamp='수업 '.$s['id'].' · '.ClassroomProtocol::phase($s).' · 저장 버전 '.$s['revision'];
        if($document==='advisory'){return [$all[5]];}
        if($document==='surveys'){
            $out=[];
            foreach(['before_items'=>'시작 설문','survey_items'=>'종료 설문']as$key=>$title){$rows=[];foreach($p[$key]as$i=>$item){$rows[]=[$i+1,$item,ClassroomProtocol::itemPurpose($item),'1 ~ '.$p['survey_max'].' / 해당 없음·판단 어려움',''];}
                $out[]=['title'=>$title,'paragraphs'=>[$stamp,ClassroomProtocol::surveyGuidance($key==='before_items'?'before':'after'),implode(' / ',ClassroomProtocol::scaleOptions($p['survey_max'])),'아래는 실제 수업에 설정한 문항입니다. 검증된 척도가 아닌 현장용 초안입니다. 전문가 문항 검토와 소수 학생의 문장 이해 확인 후 사용합니다. 자기평가는 실제 성취 점수를 대신하지 않습니다.','자유 의견 (선택, 이름 등 개인 정보 제외): __________________________________'],
                    'tables'=>[['caption'=>$title.' 응답지','headers'=>['번호','문항','측정 내용·응답 대상','척도','응답'],'rows'=>$rows]]];}
            return $out;
        }
        if($document==='materials'){
            return [['title'=>'문제 자료 구성과 검수','paragraphs'=>[$stamp,'자료 구축 절차: '.($p['notes']['dataset']?:'[작성 필요]'),'검수를 완료한 정답·해설만 AI 근거 자료에 연결한다. 이 목록은 정답 코드 공개용이 아니다.'],
                'tables'=>[$table('문제 자료 구성')]]];
        }
        if($document==='protocol'){
            $rows=[];foreach(PilotProtocol::NOTES as$key=>$label){$rows[]=[$label,$p['notes'][$key]?:'[작성 필요]'];}
            $supports=[];foreach(['pretest'=>'시작','posttest'=>'마무리']as$phase=>$label){$supports[]=[$label,implode(', ',$p[$phase])?:'미설정',PilotProtocol::AI[$p['support'][$phase]['A']],PilotProtocol::AI[$p['support'][$phase]['B']],$p['test_minutes'].'분/문제'];}
            return [['title'=>'수업 운영 조건','paragraphs'=>[$stamp,
                '수업 문제: '.implode(', ',$p['practice']).' / 계획 '.$p['lessons_count'].'차시 / 배정 방식 '.$p['allocation'].' / A:B 가중치 '.$p['arms']['A']['weight'].':'.$p['arms']['B']['weight'],
                '학생·활동별 AI 한도: '.($p['request_limit']===0?'무제한':$p['request_limit'].'개 제출').'. 같은 활동에서 피드백이 생성된 서로 다른 제출 수를 센다.',
                '수준 참조: '.($p['level_timing']==='baseline'?'등록 시 기존 수준':'요청 시 기존 수준').'. 기존 수준 평가 알고리즘을 변경하지 않는다.',
                '과제 채점: '.($p['scoring']==='ac'?'정답 문항 비율':'테스트 통과 비율').' / 풀이 시간: '.($p['pause_time']==='exclude'?'일시 중단 제외':'경과 시간').'.'],
                'tables'=>[$table('두 집단의 실제 조건'),['caption'=>'선택 과제 지원','headers'=>['시점','문제 번호','A AI','B AI','제한'],'rows'=>$supports],['caption'=>'연구 환경 기록','headers'=>['항목','내용'],'rows'=>$rows]]],
                ['title'=>'피드백 지침과 모델 연결','paragraphs'=>array_slice($all[4]['paragraphs'],2),'tables'=>[$table('등록 시 보관한 수준별 피드백 지침')]]];
        }
        if($document==='observations'){
            return [['title'=>'교사 관찰과 학생 의견','paragraphs'=>[$stamp,'관찰 기록은 같은 학생의 반복 관찰을 포함할 수 있다. 관찰이 없는 학생을 적합으로 간주하지 않는다.','이름 대신 참여 코드를 사용한다. 자유 입력에 개인 정보가 있는지 공유 전에 확인한다.'],
                'tables'=>[$table('교사 관찰 원자료'),$table('자유 의견 (응답자가 기입한 개인 정보는 공유 전 확인)')]]];
        }
        if($document==='results'){
            $survey=$all[2];$survey['tables']=[$table('설문 문항별 결과')];
            return [['title'=>'그룹·집단과 수집 현황','paragraphs'=>[$stamp],'tables'=>[$table('그룹별 참여·진행 (공유용 익명 그룹 코드)'),$table('시스템 기록 (학습 효과 지표와 구분)')]],$survey,$all[3]];
        }
        // Main text stays concise; extensive protocols, raw opinions and questionnaire forms are appendices.
        $methods=$all[0];$methods['tables']=[$table('두 집단의 실제 조건')];
        $survey=$all[2];$survey['tables']=[];
        $observation=$all[4];$observation['tables']=[];$observation['paragraphs']=array_slice($observation['paragraphs'],0,2);
        return [$methods,$all[1],$survey,$all[3],$observation,
            ['title'=>'첨부자료 안내','paragraphs'=>['시작·종료 설문지, 수업 운영 조건·AI 지침, 문제 자료 목록·검수 기록, 결과 상세표·지표 설명, 교사 관찰·학생 자유 의견은 각각 별도 자료로 제공한다. 전문가 자문 요청서도 별도 문서로 연다.',$stamp],'tables'=>[]]];
    }
}
