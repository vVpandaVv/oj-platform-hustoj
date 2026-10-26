<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Missing_Values;
use EduGames\Play\Board;

final class Play_Board extends Board {
    public static function levels(): array {return array('board-01'=>array('title'=>'완만한 평원'),'board-02'=>array('title'=>'끊어진 산길'));}
    private static function terrain(string $id): array {
        return $id==='board-01'?array(1,1,2,2,3,1,1,2,2,3,1,1,2,2,3,1,1,2,2,3,1,1,2,2,3):array(1,1,2,2,2,1,2,9,3,2,1,2,9,3,2,1,2,9,3,2,1,1,1,1,1);
    }
    public static function create(string $id): array {
        $s=self::begin($id);$s+=array('objective'=>'출발지에서 보급소까지 연료를 남겨 도착하세요. ?는 0이 아닌 아직 모르는 이동 비용입니다.','fuel'=>30,'spent'=>0,'scouts'=>0,'pos'=>10,'goal'=>14,'path'=>array(10),'cells'=>array(),'estimates'=>array(),'visited'=>array(10));
        $truth=self::terrain($id);
        foreach($truth as $i=>$v)$s['cells'][]=array('value'=>in_array($i,array(7,11,12,13,17),true)?null:$v,'rough'=>$id==='board-02'&&$i%5===2&&$i>=5&&$i<20,'source'=>in_array($i,array(7,11,12,13,17),true)?'unknown':'observed');
        return $s;
    }
    private static function adjacent(int $a,int $b): bool {return abs(intdiv($a,5)-intdiv($b,5))+abs($a%5-$b%5)===1;}
    public static function apply(array $s,array $m): array {
        $type=self::guard($s,$m,array('estimate','scout','walk'));
        $i=self::integer($m['cell']??null,0,24);
        if(!self::adjacent($s['pos'],$i))throw new \InvalidArgumentException('현재 위치와 맞닿은 칸을 선택하세요.');
        $truth=self::terrain($s['level'])[$i];
        if($type==='estimate'){
            if($s['cells'][$i]['value']!==null)throw new \InvalidArgumentException('이미 관측된 칸입니다.');
            $lo=self::integer($m['low']??null,1,9);$hi=self::integer($m['high']??null,$lo,9);
            $s['estimates'][$i]=array($lo,$hi);
            return self::note($s,'이동 비용을 '.$lo.'~'.$hi.'로 추정했습니다. 실제 지형은 바뀌지 않습니다.');
        }
        if($type==='scout'){
            if($s['cells'][$i]['value']!==null)throw new \InvalidArgumentException('이미 확인한 칸입니다.');
            if($s['fuel']<2)throw new \InvalidArgumentException('정찰 연료가 부족합니다.');
            $s['fuel']-=2;$s['spent']+=2;$s['scouts']++;
            $s['cells'][$i]=array('value'=>$truth,'rough'=>$s['cells'][$i]['rough'],'source'=>'scouted');
            $s=self::note($s,$truth===9?'정찰 결과: 통과할 수 없는 절벽입니다.':'정찰 결과: 이동 비용 '.$truth.'.');
        }else{
            if($s['cells'][$i]['value']===9)throw new \InvalidArgumentException('절벽은 통과할 수 없습니다. 우회하세요.');
            if($s['cells'][$i]['value']===null&&!isset($s['estimates'][$i]))throw new \InvalidArgumentException('모르는 칸은 범위를 추정하거나 정찰한 뒤 이동하세요.');
            $known=$s['cells'][$i]['value']!==null;
            $s['cells'][$i]=array('value'=>$truth,'rough'=>$s['cells'][$i]['rough'],'source'=>'visited');
            if($s['fuel']<($truth===9?3:$truth)){
                $s['spent']+=$s['fuel'];$s['fuel']=0;
                $s['metrics']=array('남은 연료'=>0,'이동 횟수'=>$s['turn'],'정찰 횟수'=>$s['scouts']);
                return self::finish($s,0,false,'실제 이동 비용을 감당할 연료가 부족합니다. 마지막 위치에서 도전이 끝났습니다.');
            }
            if($truth===9){$cost=3;$s=self::note($s,'길이 끊겼습니다. 출발 칸으로 돌아오며 연료 3을 사용했습니다.');}
            else{$cost=$truth;$s['pos']=$i;$s['path'][]=$i;$s['visited'][]=$i;
                $range=$s['estimates'][$i]??array($truth,$truth);
                $s=self::note($s,'이동 비용 '.$truth.'. '.(!$known?($truth>=$range[0]&&$truth<=$range[1]?'추정 범위 안입니다.':'추정 범위 밖입니다. 주변 지형이 같은지 다시 살펴보세요.'):''));
            }
            $s['fuel']-=$cost;$s['spent']+=$cost;$s['turn']++;
        }
        $s['metrics']=array('남은 연료'=>max(0,$s['fuel']),'이동 횟수'=>$s['turn'],'정찰 횟수'=>$s['scouts']);
        if($s['pos']===$s['goal'])return self::finish($s,300+max(0,$s['fuel'])*20,true,'보급품 도착! 모르는 값은 추정하되, 다른 지형에서는 확인하거나 우회할 수 있습니다.');
        if($s['fuel']<=0)return self::finish($s,0,false,'연료 소진. 경로와 정찰 위치를 바꿔 다시 도전하세요.');
        return $s;
    }
}
