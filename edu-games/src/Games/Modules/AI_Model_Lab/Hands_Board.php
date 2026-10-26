<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\AI_Model_Lab;
use EduGames\Play\Hands_Board as Base;

final class Hands_Board extends Base {
    public const CONTENT_VERSION='handwriting-digits-v2';
    public const SETS=array('017','069','358','147','235','268','049','128','456','239','578','024');
    public static function daily_classes(?string $day=null): array {
        $sets=array();for($a=0;$a<8;$a++)for($b=$a+1;$b<9;$b++)for($c=$b+1;$c<10;$c++)$sets[]=array((string)$a,(string)$b,(string)$c);
        // A coprime stride visits all 120 combinations before the daily cycle repeats.
        $index=(int)floor(strtotime(($day??\EduGames\Support\Clock::today()).' UTC')/86400);
        return $sets[(($index*37)%120+120)%120];
    }
    public static function levels(): array {
        $levels=array('board-01'=>array('title'=>'오늘의 조합 · '.implode(' · ',self::daily_classes()).' · '.\EduGames\Support\Clock::today()));
        foreach(self::SETS as $digits)$levels['digits-'.$digits]=array('title'=>'숫자 조합 · '.implode(' · ',str_split($digits)));
        return $levels;
    }
    public static function data(array $classes=array()): array {
        static $data=null;if($data===null)$data=require __DIR__.'/handwriting-data.php';
        if(!$classes)return $data;
        $subset=array('candidates'=>array(),'validation'=>array(),'final'=>array());$map=array();
        foreach($data['candidates'] as $id=>$row)if(in_array($row['label'],$classes,true)){
            $map[$id]=count($subset['candidates']);$subset['candidates'][]=$row;
        }
        // Keep original distance/tie order while mapping to this combination's local photo IDs.
        foreach(array('validation','final') as $key)foreach($data[$key] as $row)if(in_array($row['label'],$classes,true)){
            $neighbors=array();foreach($row['neighbors'] as $id)if(isset($map[$id]))$neighbors[]=$map[$id];
            $row['neighbors']=$neighbors;$subset[$key][]=$row;
        }
        return $subset;
    }
    public static function create(string $level): array {
        $s=self::fresh($level,180);$s['schema']=2;
        $s['classes']=$level==='board-01'?self::daily_classes():str_split(substr($level,7));
        $s['labels']=array();$s['changes']=0;$s['memoryLimit']=9;$s['evaluationCount']=90;return $s;
    }
    public static function predict(array $row,array $labels): ?string {
        foreach($row['neighbors'] as $id)if(isset($labels[$id]))return $labels[$id];
        return null;
    }
    private static function evaluate(array $s): array {
        $labels=$s['classes'];$hits=array_fill(0,count($labels),0);$counts=$hits;
        foreach(self::data($labels)['final'] as $row){$i=array_search($row['label'],$labels,true);$counts[$i]++;if(self::predict($row,$s['labels'])===$row['label'])$hits[$i]++;}
        $recall=array();foreach($counts as $i=>$n)$recall[]=round(100*$hits[$i]/$n,1);
        $s['metrics']=array('recall'=>$recall,'correct'=>array_sum($hits),'total'=>array_sum($counts),'memory'=>count($s['labels']),'changes'=>$s['changes']);
        $s['score']=(int)round(10*array_sum($recall)/count($labels));return self::end($s);
    }
    public static function apply(array $s,array $m): array {
        if(($s['schema']??0)!==2)throw new \InvalidArgumentException('손글씨 자료가 확장되었습니다. 새 도전을 시작하세요.');
        if(self::expired($s,$m))return self::evaluate($s);
        self::guard($s,$m,array('label'));
        $id=self::integer($m['id']??null,0,count(self::data($s['classes'])['candidates'])-1);
        $label=self::choice($m['label']??null,array_merge($s['classes'],array('remove')));
        if($label==='remove')unset($s['labels'][$id]);
        else{
            if(!isset($s['labels'][$id])&&count($s['labels'])>=$s['memoryLimit'])throw new \InvalidArgumentException('로봇의 기억 '.$s['memoryLimit'].'칸이 찼습니다. 기존 사진을 빼거나 이름을 고치세요.');
            $s['labels'][$id]=$label;
        }
        $s['changes']++;
        return self::note($s,$label==='remove'?'기억에서 사진을 뺐습니다.':'사진에 '.$label.' 이름을 붙였습니다. 닮은 사진의 예측이 갱신되었습니다.');
    }
    public static function view(array $s): array {
        $v=parent::view($s);$data=self::data($s['classes']);
        $v['candidates']=array_map(static function($p){unset($p['label'],$p['sourceId']);return $p;},$data['candidates']);
        $v['validation']=array_map(static function($p)use($s){$p['prediction']=self::predict($p,$s['labels']);unset($p['sourceId']);return $p;},$data['validation']);
        $v['labels']=(object)$v['labels'];return $v;
    }
}
