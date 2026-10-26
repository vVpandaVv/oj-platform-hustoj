<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Data_Structure_Lab;
use EduGames\Play\Hands_Board as Base;

final class Hands_Board extends Base {
    public static function create(string $level): array {return self::batch(self::fresh($level));}
    private static function batch(array $s): array {
        $n=4+min(2,(int)floor($s['completed']/4));$s['incoming']=array();$a=array();$b=array();
        for($i=0;$i<$n;$i++){$item=chr(65+$i).'-'.($s['turn']+1);$s['incoming'][]=$item;if(self::number($s,0,1))$a[]=$item;else $b[]=$item;}
        $s['orders']=array();while($a||$b){if($a&&(!$b||self::number($s,0,1)))$s['orders'][]=array_pop($a);else $s['orders'][]=array_shift($b);}
        $s['stack']=array();$s['queue']=array();$s['buffer']=array();return $s;
    }
    public static function apply(array $s,array $m): array {
        if(self::expired($s,$m))return self::end($s);
        $type=self::guard($s,$m,array('push','pop','return','reset'));
        $before=$s['score'];$s['moves']=($s['moves']??0)+1;
        if($type==='reset'){$s['incoming']=array_merge($s['incoming'],$s['stack'],$s['queue'],$s['buffer']);sort($s['incoming']);$s['stack']=$s['queue']=$s['buffer']=array();$s['score']=max(0,$s['score']-20);$s['combo']=0;$s['feedback']=array('item'=>'남은 물건','from'=>'보관대','to'=>'입고대','delta'=>$s['score']-$before);return self::note($s,'남은 물건을 입고대로 되돌렸습니다. -20점.');}
        $lane=self::choice($m['lane']??null,array('stack','queue'));
        if($type==='push'||$type==='return'){
            if(count($s[$lane])>=5)throw new \InvalidArgumentException('보관대가 가득 찼습니다. 출구 쪽 물건을 먼저 꺼내세요.');
            if($type==='push'){if(!$s['incoming'])throw new \InvalidArgumentException('대기 중인 물건이 없습니다.');$item=array_shift($s['incoming']);}
            else{$id=self::integer($m['id']??null,0,count($s['buffer'])-1);$item=$s['buffer'][$id];array_splice($s['buffer'],$id,1);$s['score']=max(0,$s['score']-3);}
            $s[$lane][]=$item;$s['feedback']=array('item'=>$item,'from'=>$type==='push'?'입고':'임시 보관','to'=>$lane==='stack'?'스택':'큐','delta'=>$s['score']-$before);
            return self::note($s,$item.' 입고 · 다음 출구: '.($lane==='stack'?end($s[$lane]):$s[$lane][0]));
        }
        if(!$s[$lane])throw new \InvalidArgumentException('꺼낼 물건이 없습니다.');
        $item=$lane==='stack'?end($s[$lane]):$s[$lane][0];$match=$item===$s['orders'][0];
        if(!$match&&count($s['buffer'])>=2)throw new \InvalidArgumentException('임시 보관 2칸이 찼습니다. 보관한 물건을 먼저 다시 넣으세요.');
        if($lane==='stack')array_pop($s[$lane]);else array_shift($s[$lane]);
        if(!$match){$s['buffer'][]=$item;$s['score']=max(0,$s['score']-3);$s['combo']=0;$s['feedback']=array('item'=>$item,'from'=>$lane==='stack'?'스택':'큐','to'=>'임시 보관','delta'=>$s['score']-$before);return self::note($s,$item.'은 지금 주문과 달라 임시 보관됩니다. -3점.');}
        array_shift($s['orders']);$s=self::award($s,30,true,$item.' 배송 완료!');
        $s['feedback']=array('item'=>$item,'from'=>$lane==='stack'?'스택':'큐','to'=>'배송 완료','delta'=>$s['score']-$before);
        return $s['orders']?$s:self::batch($s);
    }
}
