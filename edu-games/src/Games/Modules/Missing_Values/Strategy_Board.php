<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Missing_Values;
use EduGames\Boards\Rules as R;

final class Strategy_Board {
    public static function levels(): array {
        $levels=array(
            'board-01'=>array('title'=>'온실 관측망','goal'=>'3일 동안 온도·급수·날씨 관측망의 신뢰도 8 이상을 유지하세요.','budget'=>20,'referenceCost'=>12,'base'=>20,'event'=>false),
            'board-02'=>array('title'=>'집중 호우 감시망','goal'=>'급변하는 날에는 추정보다 재측정을 우선할 곳을 정하세요. 신뢰도 8 이상으로 3일을 버티세요.','budget'=>24,'referenceCost'=>18,'base'=>12,'event'=>true),
            'board-03'=>array('title'=>'산악 기상 중계소','goal'=>'통신 두절로 관측값이 끊기는 날에 대비하세요. 제한된 재측정 예산으로 신뢰도 8 이상을 유지하세요.','budget'=>18,'referenceCost'=>14,'base'=>4,'event'=>false,'blackoutDay'=>1),
        );
        $cases=array(
            4=>array('하천 관측 이웃 검증','number','peers',6,'median'),
            5=>array('정지 상태도 관측값','state','stable',18,'carry'),
            6=>array('자동문 전환 감시','state','event',10,'measure'),
            7=>array('배수 펌프 통신 두절','state','blackout',14,'measure'),
            8=>array('점검 중 일시 정지','state','maintenance',8,'measure'),
            9=>array('맑은 날씨 관측선','category','stable',22,'mode'),
            10=>array('기상 전선 통과','category','event',16,'measure'),
            11=>array('지역 관측 다수결','category','peers',12,'mode'),
            12=>array('판단 보류가 필요한 날','category','uncertain',20,'hold'),
        );
        foreach($cases as $id=>[$title,$focus,$scenario,$base,$tool]){
            $cost=12+($tool==='measure'?2:0);
            $levels[sprintf('board-%02d',$id)]=array('title'=>$title,'goal'=>'3일 뒤 신뢰도 8 이상. 자료형과 관측 근거에 맞게 복구 도구를 배치하고 예산을 아끼세요.','budget'=>$cost+4,'referenceCost'=>$cost,'base'=>$base,'event'=>false,'focus'=>$focus,'scenario'=>$scenario,'repairTool'=>$tool);
        }
        foreach($levels as &$d)$d['chapter']=array('number'=>'연속형 관측','state'=>'상태형 관측','category'=>'범주형 관측')[$d['focus']??'number'];unset($d);
        return $levels;
    }
    public static function create(string $id): array { return R::begin($id,self::levels())+array('day'=>0,'integrity'=>10,'repairs'=>array(),'lastValues'=>array(),'trace'=>array()); }
    private static function nodes(array $s): array {
        $d=self::levels()[$s['level']];$day=$s['day'];$event=$d['event']&&$day===1;$base=$d['base']+$day*4+($d['event']&&$day===2?6:0);
        $left=$day>0?($s['lastValues'][0]===null?null:$s['lastValues'][0]+2):$base;
        $blackout=($d['blackoutDay']??-1)===$day;if($blackout)$left=null;
        $nodes=array(
            array('name'=>'온도 관측소','kind'=>'number','unit'=>'°C','values'=>array($left,null,$base+4),'context'=>$blackout?'통신 두절 · 이전 관측값이 도착하지 않음':($event?'급변 구간 · 중간값을 보장할 수 없음':'선형 변화가 확인된 구간'),'actual'=>$event?$base+8:$base+2,'critical'=>true),
            array('name'=>'급수 밸브','kind'=>'state','unit'=>'0 닫힘 / 1 열림','values'=>array(0,null,$event?1:0),'context'=>$event?'작동 전환 시점 불명':'이 구간에는 작동 전환 없음','actual'=>$event?1:0,'critical'=>true),
            array('name'=>'날씨 관측소','kind'=>'category','unit'=>'날씨','values'=>array('맑음',null,$event?'비':'맑음'),'context'=>$event?'전선 통과 · 반복 상태를 보장할 수 없음':'같은 날씨가 이어지는 구간','actual'=>$event?'비':'맑음','critical'=>false),
        );
        foreach($nodes as &$node){$node['peers']=array();$node['canMeasure']=true;}unset($node);
        if(!isset($d['focus']))return $nodes;
        $i=array_search($d['focus'],array('number','state','category'),true);$scenario=$d['scenario'];$node=&$nodes[$i];
        $node['critical']=$scenario!=='uncertain';
        if($i===1){
            $on=($scenario==='event'&&$day>=1)||($scenario==='maintenance'&&$day===0);
            $node['name']='작동 상태 중계소';$node['actual']=$on?1:0;
            $node['values']=array($day?($s['lastValues'][1]??null):$node['actual'],null,$node['actual']);
            $node['context']='상태는 0 또는 1입니다. 이 구간에는 상태 전환이 없습니다.';
        }elseif($i===2){
            $actual=$scenario==='event'&&$day>=1?'비':($scenario==='peers'&&$day>=1?'흐림':'맑음');
            $node['actual']=$actual;$node['values']=array($day?($s['lastValues'][2]??null):$actual,null,$actual);
            $node['context']='같은 날씨가 계속된 구간입니다. 날씨에는 수치 보간을 적용할 수 없습니다.';
        }
        if($day===1){
            if($scenario==='event')$node['context']='전환이 발생했지만 정확한 시점은 모릅니다. 이전 상태를 그대로 확정할 수 없습니다.';
            if($scenario==='blackout'){$node['values'][0]=null;$node['context']='이전 관측의 전송에 실패했습니다. 이후 상태만 도착했습니다.';}
            if($scenario==='maintenance'){$node['values']=array(1,null,1);$node['actual']=0;$node['context']='양 끝은 가동 상태지만 중간에 정비로 일시 정지한 구간이 있습니다.';}
            if($scenario==='peers'){
                $node['values']=array(null,null,null);
                $node['peers']=$i===0?array($base+2,$base+2,$base+50):array('흐림','맑음','흐림');
                $node['context']=$i===0?'시간축 관측이 끊겼습니다. 같은 조건의 이웃 3곳 중 한 센서의 큰 값이 의심됩니다.':'시간축이 끊겼지만 같은 지역의 이웃 관측소에서 날씨를 받았습니다.';
            }
            if($scenario==='uncertain'){$node['values']=array(null,null,null);$node['peers']=array('맑음','비','흐림');$node['actual']=null;$node['canMeasure']=false;$node['context']='재측정 장비도 연결되지 않았습니다. 이웃의 날씨가 모두 달라 확정 근거가 없습니다. 이 노드는 보류 가능합니다.';}
        }
        unset($node);return $nodes;
    }
    public static function apply(array $s,array $m): array {
        R::guard($s);$d=self::levels()[$s['level']];$type=R::choice($m,'type',array('repair','advance','finish'));
        if($type==='finish'){R::finish($s,false,$d,'관측망 운영을 중단했습니다.');return $s;}
        $nodes=self::nodes($s);
        if($type==='repair') {
            $i=R::index($m,'node',3);$tool=R::choice($m,'tool',array('measure','interpolate','carry','mode','mean','median','hold'));$node=$nodes[$i];$known=array_values(array_filter($node['values'],static fn($v)=>$v!==null));
            if($tool==='measure'&&!$node['canMeasure'])throw new \InvalidArgumentException('현재는 재측정할 수 없습니다. 근거가 없으면 보류하세요.');
            if($tool==='interpolate'&&($node['kind']!=='number'||$node['values'][0]===null||$node['values'][2]===null))throw new \InvalidArgumentException('보간에는 양쪽의 수치 관측값이 필요합니다.');
            if($tool==='carry'&&$node['values'][0]===null)throw new \InvalidArgumentException('이전에 전달된 값이 없어 유지할 수 없습니다.');
            if($tool==='mode'&&$node['kind']!=='category')throw new \InvalidArgumentException('최빈 상태 도구는 날씨 같은 범주 자료에 놓으세요.');
            $reference=$node['peers']?:$known;
            if($tool==='mode'){$counts=array_count_values($reference);arsort($counts);$keys=array_keys($counts);$frequencies=array_values($counts);if(!$counts||(count($counts)>1&&$frequencies[0]===$frequencies[1]))throw new \InvalidArgumentException('최빈 상태가 없거나 동률입니다. 재측정하거나 보류하세요.');}
            if(in_array($tool,array('mean','median'),true)&&($node['kind']!=='number'||!$node['peers']))throw new \InvalidArgumentException('이웃 대표값에는 같은 조건의 수치 관측 자료가 필요합니다.');
            $peers=$node['peers'];sort($peers);$n=count($peers);
            R::charge($s,$tool==='measure'?3:1);
            $value=match($tool){'measure'=>$node['actual'],'interpolate'=>($node['values'][0]+$node['values'][2])/2,'carry'=>$node['values'][0],'mode'=>$keys[0],'mean'=>array_sum($peers)/$n,'median'=>$n%2?$peers[intdiv($n,2)]:($peers[$n/2-1]+$peers[$n/2])/2,default=>null};
            $s['repairs'][$i]=array('tool'=>$tool,'value'=>$value,'estimated'=>$tool!=='measure','held'=>$tool==='hold');$s['message']=$node['name'].'에 복구 도구를 배치했습니다.';
        } else {
            if(count($s['repairs'])!==3)throw new \InvalidArgumentException('각 관측소에 복구 도구 또는 보류 타일을 놓으세요.');
            R::charge($s,1);$damage=0;$values=array();$checks=array();
            foreach($nodes as $i=>$node){$r=$s['repairs'][$i];$values[$i]=$r['value'];$correct=is_numeric($r['value'])&&is_numeric($node['actual'])?abs($r['value']-$node['actual'])<=0.5:$r['value']===$node['actual'];$loss=$correct?0:($r['held']?($node['critical']?2:0):($node['critical']?3:1));$damage+=$loss;$checks[]=array('name'=>$node['name'],'value'=>$r['value'],'actual'=>$node['actual'],'loss'=>$loss,'estimated'=>$r['estimated']);}
            $s['integrity']=max(0,$s['integrity']-$damage);$s['trace'][]=array('day'=>$s['day']+1,'values'=>$values,'damage'=>$damage,'integrity'=>$s['integrity'],'estimated'=>array_map(static fn($r)=>$r['estimated'],$s['repairs']),'checks'=>$checks);$s['lastValues']=$values;$s['day']++;$s['repairs']=array();
            $s['message']=$damage?'관측 결과의 불확실성으로 신뢰도가 '.$damage.' 줄었습니다. 전달한 값이 다음 날의 기준이 됩니다.':'관측망을 유지했습니다. 다음 날의 관측 자료가 도착했습니다.';
            if($s['day']===3||$s['integrity']<8){$s['evaluation']=array('신뢰도'=>$s['integrity'],'운영 일수'=>$s['day']);R::finish($s,$s['day']===3&&$s['integrity']>=8,$d,$s['integrity']>=8?'3일 관측망 운영 완료. 남긴 예산으로 기록을 개선하세요.':'신뢰도가 기준 아래로 내려갔습니다.',(10-$s['integrity'])*50);}
        }
        return R::after($s);
    }
    public static function view(array $s): array {
        $d=self::levels()[$s['level']];$nodes=$s['day']<3?self::nodes($s):array();foreach($nodes as &$n)unset($n['actual']);unset($n);
        return R::view($s,$d)+array('day'=>$s['day'],'integrity'=>$s['integrity'],'nodes'=>$nodes,'repairs'=>$s['repairs'],'trace'=>$s['trace']);
    }
    public static function solution(string $id): array {
        $d=self::levels()[$id];$out=array();for($day=0;$day<3;$day++){foreach(array('interpolate','carry','mode') as $i=>$tool){if(($d['event']&&$day===1)||(($d['blackoutDay']??-1)===$day&&$i===0))$tool='measure';if(isset($d['focus'])&&$day===1&&$i===array_search($d['focus'],array('number','state','category'),true))$tool=$d['repairTool'];$out[]=array('type'=>'repair','node'=>$i,'tool'=>$tool);}$out[]=array('type'=>'advance');}return $out;
    }
}
