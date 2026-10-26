<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Domain;

/** Saved research intent is separate from the existing learner-level calculation. */
final class PilotDesign
{
    public const LABELS = ['adaptation'=>'수준별 맞춤의 추가 효과', 'feedback'=>'기존 수업 대비 피드백 효과', 'custom'=>'직접 설계'];
    public const LEVELS = ['beginner'=>'초급', 'intermediate'=>'중급', 'advanced'=>'고급', 'insufficient'=>'자료 부족'];
    public const QUESTIONS = [
        'adaptation'=>'공통 AI 피드백과 비교하여 수준별 AI 피드백을 제공할 때 알고리즘 성취가 얼마나 달라지는가?',
        'feedback'=>'AI 없는 수업과 비교하여 수준별 AI 피드백을 제공할 때 알고리즘 성취가 얼마나 달라지는가?',
        'custom'=>'저장된 두 집단의 수업 조건에 따라 알고리즘 성취가 얼마나 달라지는가?',
    ];

    public static function issues(array $p): array
    {
        $a=$p['arms']['A']; $b=$p['arms']['B']; $issues=[];
        if ($a['ai']!=='level' || $b['ai']!== (($p['design']??'custom')==='feedback'?'off':'common')) {
            $issues[]='선택한 비교 설계에 맞게 A는 수준별 AI, B는 공통 AI 또는 AI 없음으로 설정하세요.';
        }
        if ($a['recommendation']!=='common' || $b['recommendation']!=='common') {
            $issues[]='피드백의 효과를 구분하려면 두 집단 모두 공통 문제 순서를 사용하세요.';
        }
        if ($a['minutes']!==$b['minutes'] || $a['conditions']!==$b['conditions']) {
            $issues[]='두 집단의 풀이 제한과 교사 지원·추가 조건을 동일하게 설정하세요.';
        }
        foreach (['pretest','posttest'] as $phase) {
            if ($p['support'][$phase]['A']!=='off' || $p['support'][$phase]['B']!=='off') {
                $issues[]='독립 성취를 측정하려면 사전·사후 평가에서 두 집단 모두 AI를 끄세요.'; break;
            }
        }
        return $issues;
    }

    public static function cautions(array $p): array
    {
        $warnings=[];
        if (($p['design']??'custom')==='custom') {
            $warnings[]='직접 설계한 비교입니다. 집단 간 차이를 수준별 맞춤만의 효과로 단정하지 마세요.';
            if ($p['arms']['A']['recommendation']!==$p['arms']['B']['recommendation'] || $p['arms']['A']['recommendation']==='personal') {
                $warnings[]='문제 추천·순서가 학생 또는 집단마다 달라 피드백 효과와 함께 나타날 수 있습니다.';
            }
        }
        if ($p['arms']['A']['minutes']!==$p['arms']['B']['minutes'] || $p['arms']['A']['conditions']!==$p['arms']['B']['conditions']) {
            $warnings[]='학습 시간 또는 추가 지원 조건이 다릅니다. 결과에 함께 영향을 줄 수 있습니다.';
        }
        foreach (['pretest','posttest'] as $phase) {
            if ($p['support'][$phase]['A']!=='off' || $p['support'][$phase]['B']!=='off') {
                $warnings[]='평가에 AI가 허용되어 AI 없이 해결하는 독립 성취로 해석할 수 없습니다.'; break;
            }
        }
        if ($p['notes']['equivalence']==='') {$warnings[]='사전·사후 평가의 내용 범위·난이도·채점 기준이 비교 가능한지 검토하고 근거를 기록하세요.';}
        if (array_intersect($p['pretest'],$p['posttest'])!==[]) {$warnings[]='사전·사후에 같은 문항이 포함됩니다. 재검사·기억 효과를 검토하세요.';}
        if ($p['allocation']==='manual') {$warnings[]='수동 배정은 선택 편향이 있을 수 있습니다. 통계 보정만으로 인과 효과를 보장하지 않습니다.';}
        if (($p['design']??'custom')==='adaptation') {$warnings[]='공통 AI와 수준별 AI의 공통 지침·자료·모델을 맞추고, 수준 반영에 해당하는 차이를 연구자가 검토하세요.';}
        return $warnings;
    }
}
