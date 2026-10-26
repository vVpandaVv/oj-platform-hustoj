<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Domain;

final class PilotAnalysisReport
{
    public static function interval(array $effect):string
    {
        return PilotReport::number($effect['lower']).' ~ '.PilotReport::number($effect['upper']);
    }
    public static function interpretation(array $analysis):string
    {
        $p=$analysis['primary'];if($p['status']!=='estimated'){return '분석 보류: '.$p['reason'];}
        $e=$p['effect'];
        if($e['lower']<=0&&$e['upper']>=0){return '95% 신뢰구간에 0이 포함되어 차이의 방향을 명확히 판단하기 어렵습니다. 효과가 없다는 증거로 단정하지 않습니다.';}
        return $e['estimate']>0?'모형에서 A집단의 보정 성취가 더 높게 추정되었습니다. 인과 해석에는 비교 설계와 가정 검토가 필요합니다.':'모형에서 A집단의 보정 성취가 더 낮게 추정되었습니다. 인과 해석에는 비교 설계와 가정 검토가 필요합니다.';
    }
    public static function sections(array $study,array $report):array
    {
        $p=$study['data']['protocol'];$a=$report['analysis'];$primary=$a['primary'];$h=$a['heterogeneity'];
        $flow=[];$baseline=[];$levels=[];$differences=[];$effects=[];
        foreach(['A','B']as$arm){$f=$a['flow'][$arm];$b=$a['balance'][$arm];
            $flow[]=[$arm.' · '.$p['arms'][$arm]['name'],$f['included'],$f['pretest'],$f['posttest'],$f['complete'],$f['missing'],$report['withdrawn_by_arm'][$arm]??0];
            $baseline[]=[$arm,$b['n'],PilotReport::number($b['mean']),PilotReport::number($b['sd'])];}
        foreach($a['levels']as$key=>$l){$r=[$l['label']];foreach(['A','B']as$arm){$v=$l['summary'][$arm];array_push($r,$v['n'],PilotReport::number($v['pre']['mean']),PilotReport::number($v['post']['mean']),PilotReport::number($v['gain']['mean']));}
            $e=$h['effects'][$key]??null;$r[]=$e?PilotReport::number($e['estimate']):'계산 보류';$r[]=$e?self::interval($e):'—';$levels[]=$r;}
        if($primary['status']==='estimated'){$effects[]=['A − B',PilotReport::number($primary['effect']['estimate']),self::interval($primary['effect']),
            PilotReport::number($primary['standardized_difference']),$primary['n'],$primary['method'],$primary['effect']['df']];}
        foreach($h['differences']??[]as$d){$differences[]=[PilotDesign::LEVELS[$d['left']].' − '.PilotDesign::LEVELS[$d['right']],PilotReport::number($d['effect']['estimate']),self::interval($d['effect'])];}
        $phase=in_array($study['status'],['completed','archived'],true)?'저장된 수집 자료 기준':'중간 분석 · 수업/자료 수집이 아직 완료되지 않음';
        $notes=$a['warnings'];$notes[]='학습 자료와 분리한 공통 평가 점수를 주 결과로 사용한다. 오류·풀이 시간·만족도는 보조 지표이며, AI의 응답 품질 검사 자체는 학습 효과 검증이 아니다.';
        $notes[]='기존 학생 수준평가는 변경하지 않았다. 초기 수준은 공변량 및 탐색적 수준별 분석에 사용하며, 수준 등급의 변화만을 주 성취 결과로 삼지 않는다.';
        if(($report['erased']??0)>0){$notes[]='삭제된 참여자는 집단별 원자료가 남아 있지 않을 수 있다. 삭제 인원을 별도 제시하고 최초 배정 명부와 대조해야 한다.';}
        return [
            ['title'=>'5. 피드백의 학습 효과 분석','paragraphs'=>[
                '연구 질문: '.($p['notes']['question']?:PilotDesign::QUESTIONS[$p['design']??'custom']),
                '비교 설계: '.PilotDesign::LABELS[$p['design']??'custom'].' · '.$phase.'. 양의 차이는 A집단의 성취가 높은 방향이며, 단위는 100점 척도의 점수 차이다. 상대 향상률(%)이 아니다.',
                '주 분석은 사후 성취 = 절편 + 집단(A/B) + 사전 성취 + 초기 수준 범주의 선형 회귀(ANCOVA)이다. 사전·사후가 모두 있는 참여 동의 학생을 배정된 집단대로 분석하고, AI 사용 여부를 기준으로 제외하거나 집단을 바꾸지 않는다.',
                '학생 단위 분석은 HC3 표준오차와 잔차 자유도의 t 구간을, 학급 보정은 CR1 표준오차와 학급 수−1 자유도의 t 구간을 사용한다. 자동 선택은 분석 자료에 복수 학급이 있거나 학급 단위 배정이면 학급 보정을 적용한다.',
                self::interpretation($a),
                '표준화 차이는 보정된 A−B 점수 차이를 완전 사례의 집단 내 합동 사후 표준편차로 나눈 값이다. Hedges g나 상대 향상률이 아니며, 분모의 변동이 없으면 계산하지 않는다.',
                '완전 사례의 사전 표준화 평균 차이(A−B): '.PilotReport::number($a['baseline_smd']).'. 이 값만으로 집단의 동등성을 확정하지 않는다.',
                '분석 버전: '.$a['version'].' · 자료 해시: '.$report['data_hash']],
                'tables'=>[
                    ['caption'=>'표 7. 분석 대상과 결측','headers'=>['집단','현재 동의·배정','사전 있음','사후 있음','완전 사례','짝 결측','철회'],'rows'=>$flow],
                    ['caption'=>'표 8. 완전 사례의 사전 성취','headers'=>['집단','n','평균','SD'],'rows'=>$baseline],
                    ['caption'=>'표 9. 사전 성취·초기 수준 보정 결과','headers'=>['비교','점수 차이','95% 신뢰구간','표준화 차이','n','표준오차 방식','자유도'],'rows'=>$effects]]],
            ['title'=>'6. 수준별 탐색 분석과 해석 범위','paragraphs'=>[
                '수준별 효과는 주 모형에 집단×초기 수준 항을 추가한 별도 모형에서 추정한다. 각 수준의 사전·사후 평균은 완전 사례 기준이다. 자료 부족 수준을 초급으로 합치지 않는다.',
                $h['status']==='estimated'?'수준 간 효과 차이는 두 수준의 (A−B) 차이를 직접 비교한 상호작용 대비이다. 아래 신뢰구간은 탐색적이며 다중 비교를 보정하지 않았다.':'수준별 추정 보류: '.$h['reason'],
                ...$notes],
                'tables'=>[
                    ['caption'=>'표 10. 초기 수준별 성취와 피드백 효과','headers'=>['초기 수준','A n','A 사전','A 사후','A 변화','B n','B 사전','B 사후','B 변화','보정 A−B','95% 신뢰구간'],'rows'=>$levels],
                    ['caption'=>'표 11. 수준 간 피드백 효과 차이','headers'=>['수준 비교','효과 차이 (점)','95% 신뢰구간'],'rows'=>$differences]]],
        ];
    }
}
