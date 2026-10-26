<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\AI_Classifier_Lab;
use EduGames\Play\Hands_Board as Base;

final class Hands_Board extends Base {
    public static function create(string $level): array {
        $s=self::fresh($level);$s['line']=array(50,50);$s['samples']=array();return self::batch($s);
    }
    private static function batch(array $s): array {
        $s['incoming']=array();$s['truth']=array();$train=array();
        $profiles=array(array('서류 묶음',64,26),array('완충 포장 묶음',24,72),array('혼합 문구 묶음',82,55),array('소형 물품 묶음',42,18));
        [$s['shipment'],$left,$right]=$profiles[(int)floor($s['turn']/3)%count($profiles)];
        for($i=0;$i<16;$i++){
            $label=$i%2;
            for($attempt=0;$attempt<100;$attempt++){
                $x=self::number($s,8,92);$edge=$left+($right-$left)*$x/100;$y=(int)max(4,min(96,$edge+($label?1:-1)*self::number($s,7,20)));
                $close=false;foreach($train as $p)if(hypot(($p['x']-$x)*6.3,($p['y']-$y)*2.7)<30)$close=true;
                if(!$close||$i>=8)break;
            }
            $p=array('x'=>$x,'y'=>$y);
            if($i<8)$train[]=$p+array('label'=>$label);else{$s['incoming'][]=$p;$s['truth'][]=$label;}
        }
        $s['samples']=$train;return $s;
    }
    public static function prediction(array $p,array $line): int {return $p['y']>=$line[0]+($line[1]-$line[0])*$p['x']/100?1:0;}
    public static function apply(array $s,array $m): array {
        if(self::expired($s,$m))return self::end($s);
        self::guard($s,$m,array('classify'));$line=$m['line']??null;
        if(!is_array($line)||!array_is_list($line)||count($line)!==2)throw new \InvalidArgumentException('경계선의 두 끝을 확인하세요.');
        foreach($line as $v)self::integer($v,0,100);
        $hits=array(0,0);$items=array();foreach($s['incoming'] as $i=>$p){$actual=$s['truth'][$i];$guess=self::prediction($p,$line);if($actual===$guess)$hits[$actual]++;$items[]=$p+array('label'=>$actual,'prediction'=>$guess);}
        $s['line']=$line;$s['feedback']=array('items'=>$items,'hits'=>$hits,'line'=>$line,'shipment'=>$s['shipment']);
        $before=$s['score'];
        $s=self::award($s,20*array_sum($hits)-80,min($hits)===4,'봉투 '.$hits[0].'/4 · 상자 '.$hits[1].'/4 배송 성공.');
        $s['feedback']['delta']=$s['score']-$before;
        return self::batch($s);
    }
}
