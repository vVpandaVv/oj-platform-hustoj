<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Domain;

/** A simpler workflow reuses the same study storage and server-side research policies. */
final class ClassroomProtocol
{
    public const WORKFLOW = 'group_classroom.v1';
    public const BEFORE = [
        '나는 AI의 도움 없이 조건문이 필요한 간단한 문제를 풀 수 있다.',
        '나는 AI의 도움 없이 반복문이 필요한 간단한 문제를 풀 수 있다.',
        '나는 AI의 도움 없이 내 코드에서 오류가 난 위치를 찾을 수 있다.',
    ];
    public const AFTER = [
        ...self::BEFORE,
        '이번 알고리즘 수업에 전반적으로 만족한다.',
        '이번 수업에서 읽은 AI 피드백의 설명을 이해할 수 있었다.',
        '이번 수업에서 읽은 AI 피드백은 내 코드에서 무엇을 확인할지 정하는 데 도움이 되었다.',
        '이번 수업에서 읽은 AI 피드백은 내가 이해하기에 알맞은 난이도였다.',
    ];
    public static function scaleOptions(int $max): array
    {
        $labels=$max===5?[1=>'전혀 그렇지 않다',2=>'그렇지 않다',3=>'보통이다',4=>'그렇다',5=>'매우 그렇다']:[1=>'전혀 그렇지 않다',$max=>'매우 그렇다'];
        $out=[];for($i=1;$i<=$max;++$i){$out[$i]=$i.(isset($labels[$i])?' · '.$labels[$i]:'');}return $out;
    }
    public static function surveyGuidance(string $stage): string
    {
        return '정답은 없습니다. 지금 자신의 생각을 기준으로 응답하세요. '.($stage==='after'?'AI 피드백 문항은 이번 수업에서 실제로 읽은 OJ AI 피드백만 생각하세요. AI를 사용하지 않았거나 피드백을 읽지 않았다면 해당 없음을 선택하세요. ':'').'판단하기 어려운 문항도 해당 없음을 선택할 수 있습니다.';
    }
    public static function itemPurpose(string $item): string
    {
        if(in_array($item,self::BEFORE,true)){return '독립 문제 해결에 대한 자기평가';}
        if($item===self::AFTER[3]){return '수업 만족도 · 모든 집단';}
        if(in_array($item,array_slice(self::AFTER,4),true)){return 'AI 피드백 경험 · 실제 읽은 학생';}
        return '교사가 설정한 문항';
    }
    public static function is(array $study): bool { return ($study['data']['protocol']['workflow'] ?? '') === self::WORKFLOW; }
    public static function questions(string $text, array $defaults): array
    {
        $items = array_values(array_filter(array_map('trim', explode("\n", PilotProtocol::text($text)))));
        if ($items === []) { return $defaults; }
        if (count($items) > 20) { throw new \DomainException('설문은 20문항 이하로 구성해 주세요.'); }
        return $items;
    }
    public static function phase(array $study): string
    {
        return match ($study['status']) {
            'draft'=>'설정 확인', 'baseline'=>'시작 설문', 'pretest'=>'시작 과제', 'practice'=>'수업 중',
            'posttest'=>'마무리 과제', 'completed'=>'종료 설문·결과', 'archived'=>'보관한 수업',
            'paused'=>'잠시 멈춤', 'cancelled'=>'중단한 수업', default=>'수업 준비',
        };
    }
    /** @return array{0:string,1:string}|null */
    public static function next(array $study): ?array
    {
        $p = $study['data']['protocol'];
        return match ($study['status']) {
            'baseline'=>$p['pretest'] !== [] ? ['pretest','시작 과제 열기'] : ['practice','수업 시작'],
            'pretest'=>['practice','수업 시작'],
            'practice'=>$study['data']['lesson'] < $p['lessons_count'] ? ['practice','다음 차시 시작'] : ($p['posttest'] !== [] ? ['posttest','마무리 과제 열기'] : ['completed','수업 마치고 종료 설문 열기']),
            'posttest'=>['completed','종료 설문 열기'],
            default=>null,
        };
    }
}
