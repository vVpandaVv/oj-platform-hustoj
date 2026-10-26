<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Data_Extraction;
use EduGames\Play\Hands_Board as Base;

final class Hands_Board extends Base {
    public static function create(string $level): array {return self::batch(self::fresh($level));}
    private static function batch(array $s): array {
        $s['items']=array();for($i=0;$i<12;$i++)$s['items'][]=array('id'=>$s['turn']*12+$i+1,'color'=>self::number($s,0,1)?'red':'blue','shape'=>self::number($s,0,1)?'triangle':'circle');
        for($i=0;$i<4;$i++){$s['items'][$i]['color']=$i<2?'red':'blue';$s['items'][$i]['shape']=$i%2?'triangle':'circle';}
        $s['order']=array('color'=>self::number($s,0,1)?'red':'blue','shape'=>self::number($s,0,1)?'triangle':'circle','join'=>$s['turn']===0?'and':(self::number($s,0,1)?'and':'or'),'fields'=>$s['turn']%2?array('id','shape'):array('id','color'));
        return $s;
    }
    public static function selected(array $items,array $gates): array {
        return array_values(array_filter($items,static function($item)use($gates){
            $a=$item['color']===$gates['color'];$b=$item['shape']===$gates['shape'];return $gates['join']==='and'?$a&&$b:$a||$b;
        }));
    }
    public static function apply(array $s,array $m): array {
        if(self::expired($s,$m))return self::end($s);
        self::guard($s,$m,array('ship'));
        $g=array('color'=>self::choice($m['color']??null,array('red','blue')),'shape'=>self::choice($m['shape']??null,array('triangle','circle')),'join'=>self::choice($m['join']??null,array('and','or')));
        $fields=$m['fields']??null;if(!is_array($fields)||!array_is_list($fields)||count(array_unique($fields))!==count($fields))throw new \InvalidArgumentException('출고 열을 확인하세요.');
        foreach($fields as $f)self::choice($f,array('id','color','shape'));
        $wanted=array_column(self::selected($s['items'],$s['order']),'id');$actual=array_column(self::selected($s['items'],$g),'id');
        $errors=count(array_diff($wanted,$actual))+count(array_diff($actual,$wanted));$columnErrors=count(array_diff($s['order']['fields'],$fields))+count(array_diff($fields,$s['order']['fields']));
        $s['gates']=$g+array('fields'=>$fields);
        $s['feedback']=array('wanted'=>$wanted,'actual'=>$actual,'errors'=>$errors,'columnErrors'=>$columnErrors,'items'=>$s['items'],'fields'=>$fields,'wantedFields'=>$s['order']['fields']);
        $before=$s['score'];
        $s=self::award($s,70-15*$errors-15*$columnErrors,$errors+$columnErrors===0,'출고 행 오류 '.$errors.'개 · 출고 열 오류 '.$columnErrors.'개.');
        $s['feedback']['delta']=$s['score']-$before;
        return self::batch($s);
    }
}
