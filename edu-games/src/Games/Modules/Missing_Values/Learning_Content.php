<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\Missing_Values;

use EduGames\Learning\Question as Q;

final class Learning_Content {
    private const METHODS = array('mean'=>'평균','median'=>'중앙값','mode'=>'최빈값','interpolate'=>'시간 보간','previous'=>'이전 상태 유지','leave'=>'미확인으로 보존','recollect'=>'다시 수집','exclude'=>'이번 집계에서만 제외');

    public static function questions(): array {
        $bank = array();
        for ($v=0;$v<6;$v++) {
            $n=20+$v*2;
            $bank[]=self::make("mean-$v",'easy',"관측값의 평균으로 대체하는 실습입니다. 미기록은 분모에서 제외합니다. 단위: cm.",array($n,$n+2,null,$n+4),array('mean'),array(2=>$n+2),'평균은 세 관측값의 합÷3입니다. 대체값은 실제 관측이 아니라 추정입니다.');
            $n=10+$v;
            $bank[]=self::make("median-$v",'normal','중앙값 대체 정책을 사용합니다. 응답 시간(ms)의 빈칸을 채우세요.',array($n,$n+1,null,$n+2,$n+90),array('median'),array(2=>$n+1.5),'관측값을 정렬하고 가운데 두 값의 평균을 구합니다. 큰 값 하나가 중앙값을 크게 움직이지는 않습니다.');
            $colors=array('빨강','파랑','초록'); $color=$colors[$v%3]; $other=$colors[($v+1)%3];
            $bank[]=self::make("mode-$v",'easy','모형 물체의 미기록 색을 최빈 범주로 대체하고 추정으로 표시하세요.',array_merge(array($color,$other,null,$color,$color),array_fill(0,$v,$color)),array('mode'),array(2=>$color),'가장 자주 기록된 범주를 사용합니다. 실제 물체의 색을 알아낸 것은 아닙니다.',array(),$colors);
            $end=20+$v*4; $t1=5+$v; $t2=15+$v;
            $bank[]=self::make("interpolate-$v",'hard',"가상 카트가 일정한 속도로 움직입니다. 0초 위치는 0cm, {$end}초 위치는 ".($end*2)."cm입니다. 실제 시간 간격으로 선형 보간하세요.",array(0,null,null,$end*2),array('interpolate'),array(1=>$t1*2,2=>$t2*2),'배열 칸 간격이 아닌 시간 비율을 곱합니다. 일정한 속도라는 가정이 없으면 이 값은 확정할 수 없습니다.',array(0,$t1,$t2,$end));
        }
        for($v=0;$v<4;$v++) {
            $state=$v%2?'꺼짐':'켜짐'; $last=$v%2?'켜짐':'꺼짐';
            $bank[]=self::make("state-$v",'normal','오류 없는 가상 스위치는 상태가 바뀔 때만 기록합니다. 빈칸은 변화 없음입니다. 빈칸의 상태를 표시하세요.',array($state,null,null,$last),array('previous'),array(1=>$state,2=>$state),'이전 상태 유지는 변화 시에만 기록한다는 규칙 덕분에 가능합니다. 고장 난 기록 장치에는 그대로 적용하지 않습니다.',array(0,5*($v+1),5*($v+2),5*($v+3)),array('켜짐','꺼짐'));
            $contexts=array(
                array('자발적 취향 설문의 응답 수를 집계합니다. 미응답자의 취향을 대신 추정하지 않습니다.','leave'),
                array('대여 물품 일련번호가 빠졌습니다. 반납 확인에는 실제 번호가 반드시 필요하므로 원본을 확인하여 다시 기록해야 합니다.','recollect'),
                array('센서가 고장 난 시점은 미기록입니다. 이번 작업의 정책은 정상적으로 관측된 값만 집계하고 원본 빈칸은 보존하는 것입니다.','exclude'),
                array('선택 질문의 미응답은 응답하지 않았다는 상태로 보존합니다. 가장 인기 있는 답을 대신 적지 않습니다.','leave'),
            );
            [$prompt,$action]=$contexts[$v];
            $bank[]=Q::make("unknown-$v",'결측을 채우지 않는 판단','hard',$prompt,array('rows'=>array(array('번호'=>'A','기록'=>'기록됨'),array('번호'=>'B','기록'=>null),array('번호'=>'C','기록'=>'기록됨'),array('번호'=>'D','기록'=>'기록됨'))),array(Q::field('method','처리 정책','choice',self::METHODS),Q::field('observed','실제 기록이 있는 행 수')),array('method'=>array($action),'observed'=>3),'미확인과 0은 다릅니다. 추정해도 되는 정보인지, 원본 확인이 필요한지 목적에 따라 구분합니다.','이 작업에 실제 값이 필요한지와 대체 정책이 제시되었는지 확인합니다.');
            $n=6+$v;
            $bank[]=self::make("compare-$v",'hard','관측 대표값으로 대체하는 모형 실습입니다. 평균·중앙값·최빈값 중 하나를 선택하고 빈칸을 채우세요. 셋 모두 허용하며 대체값은 추정으로 표시합니다.',array($n,$n+1,null,$n+1,$n+2),array('mean','median','mode'),array(2=>$n+1),'세 정책의 결과는 같습니다. 같은 값이 나왔다고 정책이 항상 동등한 것은 아닙니다. 다른 분포에서는 결과가 달라집니다.');
        }
        return $bank;
    }

    private static function make(string $id,string $level,string $prompt,array $values,array $methods,array $replacements,string $explanation,array $times=array(),array $categories=array()): array {
        $fields=array(Q::field('method','대체 방법','choice',self::METHODS)); $expected=array('method'=>$methods); $rows=array();
        foreach($values as $i=>$value) { $row=array('번호'=>(string)($i+1),'관측값'=>$value); if($times) {$row['시간(초)']=$times[$i];} $rows[]=$row; }
        foreach($replacements as $i=>$value) {
            $key='value-'.$i;
            $fields[]=Q::field($key,($i+1).'번 추정값',is_string($value)?'choice':'number',$categories?array_combine($categories,$categories):array());
            $expected[$key]=is_string($value)?array($value):$value;
        }
        $fields[]=Q::field('provenance','새로 채운 값의 성격','choice',array('estimated'=>'정책으로 계산한 추정/재구성','observed'=>'새로 직접 측정한 관측'));
        $expected['provenance']=array('estimated');
        return Q::make($id,'결측 정책과 추정값',$level,$prompt,array('rows'=>$rows),$fields,$expected,$explanation,'기록된 값과 새로 계산할 값을 구분하세요. 정책과 값은 각각 평가합니다.');
    }
}
