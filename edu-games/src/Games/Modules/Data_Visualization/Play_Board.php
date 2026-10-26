<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Data_Visualization;
use EduGames\Play\Board;

final class Play_Board extends Board {
    public static function levels(): array {return array('board-01'=>array('title'=>'아침의 동항'),'board-02'=>array('title'=>'오후의 동항'));}
    private static function demand(string $id): array {
        return $id==='board-01'?array(array(8,2,2),array(2,8,2),array(2,2,8)):array(array(2,2,8),array(2,8,2),array(8,2,2));
    }
    public static function create(string $id): array {
        $s=self::begin($id);return $s+array('objective'=>'두 척의 배로 시간대별 항구 주문을 배송하세요. 출항한 뒤에는 지난 주문을 채울 수 없습니다.','phase'=>'analyst','charts'=>array(array('group'=>'time','kind'=>'bar')),'assign'=>array(array('port'=>0,'cargo'=>6),array('port'=>1,'cargo'=>6)),'delivered'=>0,'waste'=>0,'late'=>0,'history'=>array(),'ports'=>array('동항','남항','서항'),'times'=>array('아침','낮','저녁'),'practice'=>true);
    }
    public static function apply(array $s,array $m): array {
        $type=self::guard($s,$m,array('charts','handoff','assign','sail'));
        if($type==='charts'){
            if($s['phase']!=='analyst')throw new \InvalidArgumentException('분석가 차례에 편집하세요.');
            $charts=$m['charts']??null;if(!is_array($charts)||count($charts)<1||count($charts)>2)throw new \InvalidArgumentException('차트는 1~2개입니다.');
            $s['charts']=array();
            foreach($charts as $c){if(!is_array($c))throw new \InvalidArgumentException('차트를 확인하세요.');$s['charts'][]=array('group'=>self::choice($c['group']??null,array('time','total')),'kind'=>self::choice($c['kind']??null,array('bar','line')));}
            return self::note($s,'원자료와 연결된 차트를 준비했습니다.');
        }
        if($type==='handoff'){
            if($s['phase']!=='analyst')throw new \InvalidArgumentException('이미 지휘관 차례입니다.');
            $s['phase']='navigator';return self::note($s,'지휘관 차례입니다. 차트를 보고 두 배의 목적지를 정하세요.');
        }
        if($s['phase']!=='navigator')throw new \InvalidArgumentException('차트를 전달한 뒤 배를 배치하세요.');
        if($type==='assign'){
            $i=self::integer($m['ship']??null,0,1);$s['assign'][$i]=array('port'=>self::integer($m['port']??null,0,2),'cargo'=>self::integer($m['cargo']??null,0,6));return $s;
        }
        $d=self::demand($s['level'])[$s['turn']];$arrived=array(0,0,0);
        foreach($s['assign'] as $ship)$arrived[$ship['port']]+=$ship['cargo'];
        $good=0;$waste=0;$late=0;
        foreach($d as $i=>$v){$good+=min($v,$arrived[$i]);$waste+=max(0,$arrived[$i]-$v);$late+=max(0,$v-$arrived[$i]);}
        $s['delivered']+=$good;$s['waste']+=$waste;$s['late']+=$late;$s['history'][]=array('time'=>$s['times'][$s['turn']],'demand'=>$d,'arrival'=>$arrived);
        $s['turn']++;$s['metrics']=array('배송'=>$s['delivered'],'미배송'=>$s['late'],'남은 화물'=>$s['waste']);
        $s=self::note($s,$s['times'][$s['turn']-1].' 운항: '.$good.'상자 배송, '.$late.'상자 미배송, '.$waste.'상자 과잉 도착.');
        if($s['turn']===3)return self::finish($s,$s['delivered']*30-$s['waste']*5,$s['delivered']>=24,'운항 종료. 항구별 총량이 같아도 시간대별 수요가 다르면 배치가 달라집니다.');
        return $s;
    }
    public static function view(array $s): array {
        $data=self::demand($s['level']);$s['plots']=array();
        foreach($s['charts'] as $chart){
            if($chart['group']==='total')$s['plots'][]=$chart+array('labels'=>$s['ports'],'series'=>array(array('name'=>'하루 주문','values'=>array_map(static fn($i)=>array_sum(array_column($data,$i)),array(0,1,2)))));
            else $s['plots'][]=$chart+array('labels'=>$s['times'],'series'=>array_map(static fn($i)=>array('name'=>$s['ports'][$i],'values'=>array_column($data,$i)),array(0,1,2)));
        }
        if($s['phase']==='analyst')$s['source']=$data;
        return parent::view($s);
    }
}

