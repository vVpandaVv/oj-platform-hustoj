<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\Data_Visualization;

use EduGames\Learning\Question as Q;

final class Learning_Content {
    private const CHARTS=array('bar'=>'막대','line'=>'꺾은선','pie'=>'원','stacked100'=>'100% 누적 막대','histogram'=>'히스토그램','scatter'=>'산점도');
    public static function questions(): array {
        $bank=array();
        for($v=0;$v<5;$v++) {
            $n=4+$v;
            $bank[]=self::make("compare-$v",'easy','분류별 개수 비교','분류별 부품 수를 막대로 비교하세요. 가장 많은 분류의 개수를 입력합니다.',array('분류'=>array('A','B','C'),'개수'=>array($n,$n+5,$n+2)),array('bar'),'분류','개수',array(Q::field('value','가장 많은 개수')),array('value'=>$n+5),'B의 막대가 가장 높습니다. 범주 간 크기 비교에는 길이의 기준점 0이 필요합니다.');
            $bank[]=self::make("time-$v",'easy','시간 변화와 증가량','날짜별 모형 식물 높이 변화를 표현하고, 가장 많이 증가한 구간의 시작 날짜와 증가량을 입력하세요. 날짜 순서가 유지된 막대도 허용합니다.',array('날짜'=>array(1,2,3,4),'높이(cm)'=>array(2+$v,3+$v,7+2*$v,8+2*$v)),array('line','bar'),'날짜','높이(cm)',array(Q::field('start','최대 증가 구간 시작 날짜'),Q::field('increase','증가량(cm)')),array('start'=>2,'increase'=>4+$v),'가장 높은 날과 가장 많이 증가한 구간은 다릅니다. 이웃한 날의 차이를 비교합니다.');
            $total=40+4*$v; $first=$total/2;
            $bank[]=self::make("share-$v",'easy','전체와 부분',"총 예산 {$total}단위입니다. 항목은 중복되지 않습니다. 전체 대비 재료비의 비율을 원 또는 100% 누적 막대로 표현하세요.",array('항목'=>array('재료','장소','운반'),'비용'=>array($first,12+$v,8+$v)),array('pie','stacked100'),'항목','비용',array(Q::field('percent','재료비 비율(%)')),array('percent'=>50),'재료비÷전체×100=50%입니다. 분모는 항목 수가 아니라 총예산입니다.');
            $values=array(0,9,10,19,20,30); if($v%2) {$values[]=10;} if($v>1) {$values[]=30;} if($v===4) {$values[]=0;}
            $counts=array(2+($v===4?1:0),2+($v%2?1:0),2+($v>1?1:0));
            $fields=array(Q::field('chart','그래프','choice',self::CHARTS)); $expected=array('chart'=>array('histogram'));
            foreach(array('[0,10)','[10,20)','[20,30]') as $i=>$label) {$fields[]=Q::field('bin-'.$i,$label.' 도수');$expected['bin-'.$i]=$counts[$i];}
            $bank[]=Q::make("histogram-$v",'구간 경계와 도수','normal','측정값을 [0,10), [10,20), [20,30]으로 집계해 히스토그램을 완성하세요. 마지막 구간만 오른쪽 경계를 포함합니다.',array('rows'=>array_map(static fn($n)=>array('측정값'=>$n),$values)),$fields,$expected,'10은 두 번째, 20과 30은 세 번째 구간입니다. 모든 값은 한 번씩만 집계됩니다.','양쪽 끝을 모두 포함하면 경계값을 두 번 셀 수 있습니다.',array('type'=>'chart','histogram'=>true,'labels'=>array('[0,10)','[10,20)','[20,30]')));
            $ys=array(array(2,4,6,8,10),array(12,9,7,5,3),array(2,3,3,6,8),array(10,9,7,7,4),array(5,2,8,2,5))[$v];
            $bank[]=self::make("association-$v",'hard','관찰과 인과','두 가상 측정량을 같은 시점에 관찰했습니다. X와 Y의 관계를 산점도로 표현하고 관계의 방향과 인과 결론을 판단하세요.',array('X'=>array(1,2,3,4,5),'Y'=>$ys),array('scatter'),'X','Y',array(Q::field('direction','관계 방향','choice',array('positive'=>'함께 증가','negative'=>'X가 증가할수록 Y 감소','none'=>'뚜렷한 증가·감소 방향 없음')),Q::field('cause','이 자료만으로 X가 원인이라고 확정할 수 있나요?','choice',array('yes'=>'확정 가능','no'=>'확정 불가'))),array('direction'=>array($v===4?'none':($v%2?'negative':'positive')),'cause'=>array('no')),'관찰값의 관련성만으로 원인 관계를 확정하지 않습니다. 다른 변수와 실험 조건도 검토해야 합니다.');
            $a=90+$v; $b=$a+5;
            $bank[]=self::make("baseline-$v",'hard','오해를 줄이는 축','두 모둠의 제작 개수를 막대 길이로 비교합니다. 길이 비율이 실제 개수 비율을 나타내도록 기준선을 정하고 실제 차이를 입력하세요.',array('모둠'=>array('A','B'),'개수'=>array($a,$b)),array('bar'),'모둠','개수',array(Q::field('baseline','세로축 시작 값'),Q::field('difference','실제 개수 차이')),array('baseline'=>0,'difference'=>5),'막대 길이로 비율을 읽을 때 기준선은 0입니다. 축을 90 근처에서 시작하면 작은 차이가 과장될 수 있습니다.');
        }
        return $bank;
    }

    private static function make(string $id,string $level,string $skill,string $prompt,array $columns,array $charts,string $x,string $y,array $fields,array $expected,string $explanation): array {
        $names=array_keys($columns); $options=array_combine($names,$names); $rows=array();
        foreach($columns[$names[0]] as $i=>$value) { $row=array(); foreach($columns as $name=>$values){$row[$name]=$values[$i];} $rows[]=$row; }
        $common=array(Q::field('chart','그래프','choice',self::CHARTS),Q::field('x','가로축/범주','choice',$options),Q::field('y','세로축/값','choice',$options));
        return Q::make($id,$skill,$level,$prompt,array('rows'=>$rows),array_merge($common,$fields),array_merge(array('chart'=>$charts,'x'=>array($x),'y'=>array($y)),$expected),$explanation,'그래프 이름뿐 아니라 축, 단위, 분모와 이웃한 값의 차이를 확인하세요.',array('type'=>'chart'));
    }
}
