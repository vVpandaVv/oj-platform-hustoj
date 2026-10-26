<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Outlier_Detection;
use EduGames\Play\Board;

final class Play_Board extends Board {
    public static function levels(): array {return array('board-01'=>array('title'=>'서로 다른 계기판'),'board-02'=>array('title'=>'해안에 머문 열기'));}
    public static function create(string $id): array {
        $s=self::begin($id);
        $s+=array('objective'=>'6회 관측 동안 정확한 기온 지도를 유지하고 실제 32°C 이상 지역에 경보를 보내세요.','energy'=>8,'valid'=>0,'bad'=>0,'hits'=>0,'false'=>0,'missed'=>0,'stations'=>array());
        foreach(array('북산','숲','학교','해안','항구','동산') as $i=>$name)$s['stations'][]=array('name'=>$name,'zone'=>$i===3||$i===4?'해안':'내륙','policy'=>'raw','repaired'=>false,'inspected'=>false,'history'=>array(),'published'=>null);
        return $s;
    }
    private static function truth(array $s,int $i,int $turn): float {
        $base=array(18,21,23,24,25,20)[$i]+array(0,1,2,1,0,-1)[$turn%6];
        return (float)($s['level']==='board-02'&&in_array($i,array(3,4),true)&&$turn>=1?$base+10:$base);
    }
    private static function raw(array $s,int $i,int $turn): float {
        $v=self::truth($s,$i,$turn);
        if($s['stations'][$i]['repaired'])return $v;
        if($s['level']==='board-01'&&$i===3)return round($v*1.8+32,1);
        if(($s['level']==='board-01'&&$i===1)||($s['level']==='board-02'&&$i===0))return $v+14;
        return $v;
    }
    public static function apply(array $s,array $m): array {
        $type=self::guard($s,$m,array('inspect','policy','run'));
        if($type==='inspect'){
            $i=self::integer($m['station']??null,0,5);
            if($s['stations'][$i]['inspected'])throw new \InvalidArgumentException('현장 기록을 이미 받았습니다.');
            if($s['energy']<1)throw new \InvalidArgumentException('출동 자원이 부족합니다.');
            $s['energy']--;$s['stations'][$i]['inspected']=true;
            $unit=$s['level']==='board-01'&&$i===3&&!$s['stations'][$i]['repaired']?'°F':'°C';
            $v=self::truth($s,$i,$s['turn']);
            $s['stations'][$i]['evidence']=array('unit'=>$unit,'time'=>$s['turn']+1,'range'=>array($v-1,$v+1));
            return self::note($s,$s['stations'][$i]['name'].' 현장 일지: 독립 온도계 '.($v-1).'~'.($v+1).'°C, 전송 장치 단위 '.$unit.'. 이후 기온은 변할 수 있습니다.');
        }
        if($type==='policy'){
            $i=self::integer($m['station']??null,0,5);
            $p=self::choice($m['policy']??null,array('raw','celsius','repair','hold'));
            if($p==='repair'){
                if($s['stations'][$i]['repaired'])throw new \InvalidArgumentException('이미 교체한 센서입니다.');
                if($s['energy']<3)throw new \InvalidArgumentException('센서 교체에는 자원 3개가 필요합니다.');
                $s['energy']-=3;$s['stations'][$i]['repaired']=true;$p='raw';
            }
            $s['stations'][$i]['policy']=$p;
            return self::note($s,$s['stations'][$i]['name'].' 처리 설정을 이후 관측에도 적용합니다.');
        }
        $n=self::integer($m['steps']??1,1,6);
        for($step=0;$step<$n&&$s['turn']<6;$step++){
            $reports=array();
            foreach($s['stations'] as $i=>&$station){
                $raw=self::raw($s,$i,$s['turn']);$truth=self::truth($s,$i,$s['turn']);
                $value=$station['policy']==='hold'?null:($station['policy']==='celsius'?round(($raw-32)/1.8,1):$raw);
                $station['history'][]=$raw;$station['published']=$value;
                if($value!==null){if(abs($value-$truth)<=2)$s['valid']++;else $s['bad']++;}
                $alarm=$value!==null&&$value>=32;$event=$truth>=32;
                if($alarm&&$event)$s['hits']++;elseif($alarm)$s['false']++;elseif($event)$s['missed']++;
                $station['alarm']=$alarm;
                if($alarm)$reports[]=$station['name'];
            }unset($station);
            $s['turn']++;
            $s=self::note($s,$s['turn'].'회 송신: 경보 '.($reports?implode(', ',$reports):'없음').'.');
        }
        $s['metrics']=array('유효 관측'=>$s['valid'],'잘못된 관측'=>$s['bad'],'실제 경보'=>$s['hits'],'헛경보'=>$s['false'],'놓친 경보'=>$s['missed']);
        if($s['turn']===6){
            $score=max(0,$s['valid']*20+$s['hits']*35-$s['bad']*15-$s['false']*25-$s['missed']*35);
            return self::finish($s,$score,$s['valid']>=30&&$s['false']===0&&$s['missed']===0,'운영 종료. 값이 크다는 이유만으로 보류하면 실제 폭염도 지도에서 사라집니다.');
        }
        return $s;
    }
    public static function view(array $s): array {
        foreach($s['stations'] as $i=>&$station){
            $station['reading']=self::raw($s,$i,min(5,$s['turn']));
            if($s['status']!=='playing')$station['verified']=self::truth($s,$i,5);
        }
        unset($station);return parent::view($s);
    }
}
