<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Outlier_Detection;
use EduGames\Play\Hands_Board as Base;

final class Hands_Board extends Base {
    public static function create(string $level): array {return self::batch(self::fresh($level));}
    private static function batch(array $s): array {
        $contexts=array(array('같은 교실의 온도','°C',24),array('같은 구간의 통학 시간','분',35),array('같은 품종의 묘목 높이','cm',80),array('같은 규격 상자의 무게','g',140));
        [$s['context'],$s['unit'],$center]=$contexts[self::number($s,0,3)];
        $center+=self::number($s,-5,5);
        $points=array();for($i=0;$i<7;$i++)$points[]=array('value'=>$center+self::number($s,-3,3),'odd'=>false);
        $count=$s['turn']===0?1:self::number($s,0,2);
        $direction=self::number($s,0,1)?1:-1;
        for($i=0;$i<$count;$i++)$points[$i]=array('value'=>$center+($i===0?$direction:-$direction)*self::number($s,22,35),'odd'=>true);
        $points=self::shuffle($s,$points);$s['truth']=array();$s['values']=array();
        foreach($points as $i=>$p){$s['values'][]=$p['value'];if($p['odd'])$s['truth'][]=$i;}
        return $s;
    }
    public static function apply(array $s,array $m): array {
        if(self::expired($s,$m))return self::end($s);
        self::guard($s,$m,array('scan'));
        $ids=self::ids($m['ids']??null,count($s['values']));
        $miss=count(array_diff($s['truth'],$ids));$false=count(array_diff($ids,$s['truth']));
        $hit=count(array_intersect($ids,$s['truth']));$perfect=$miss+$false===0;
        $s['feedback']=array('values'=>$s['values'],'selected'=>$ids,'review'=>$s['truth'],'unit'=>$s['unit'],'missed'=>$miss,'falsePositives'=>$false,'perfect'=>$perfect);
        $text=$perfect?'검토 대상 정확히 분리! 원본은 보존됩니다.':"검토 대상 {$miss}개를 놓쳤고, 모여 있는 값 {$false}개를 따로 골랐습니다.";
        $before=$s['score'];
        $s=self::award($s,$perfect?60:15*$hit-20*$false-15*$miss,$perfect,$text);
        $s['feedback']['delta']=$s['score']-$before;
        return self::batch($s);
    }
}
