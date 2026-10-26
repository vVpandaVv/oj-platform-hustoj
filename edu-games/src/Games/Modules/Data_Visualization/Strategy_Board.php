<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Data_Visualization;
use EduGames\Boards\Rules as R;

final class Strategy_Board {
    public static function levels(): array {return Campaign::levels();}
    private static function slots(array $p,array $d): int {return $p['kind']==='histogram'?(int)ceil((max(array_column($d['rows'],'value'))+1)/$p['binWidth']):count($d['rows']);}
    private static function empty_plot(array $d): array {return array('kind'=>'bar','x'=>'name','y'=>'value','baseline'=>0,'binWidth'=>2,'slots'=>array_fill(0,count($d['rows']),array()),'positions'=>array());}
    public static function create(string $id): array {
        $s=R::begin($id,self::levels());$s['plots']=array_map(static fn($d)=>self::empty_plot($d),self::levels()[$id]['panels']);return $s;
    }
    public static function apply(array $s,array $m): array {
        R::guard($s);$d=self::levels()[$s['level']];$type=R::choice($m,'type',array('configure','place','remove','swap','finish'));
        if($type==='finish'){
            R::charge($s,1);$won=true;$s['evaluation']=array();
            foreach($s['plots'] as $i=>$p){$result=self::validate($p,$d['panels'][$i]);foreach($result as $key=>$value){$s['evaluation'][($i+1).'판 '.$key]=$value?'충족':'미충족';$won=$won&&$value;}}
            R::finish($s,$won,$d,$won?'모든 작업판 제작 완료. 목적을 지키면서 배치·수정 비용을 줄여 보세요.':'제작 종료. 작업판별 자료, 축, 순서·구간과 기준선을 확인하세요.');return $s;
        }
        $index=R::index($m+array('panel'=>0),'panel',count($s['plots']));$p=&$s['plots'][$index];$panel=$d['panels'][$index];$count=count($panel['rows']);
        if($type==='configure'){
            $key=R::choice($m,'key',array('kind','x','y','baseline','binWidth'));
            $choices=array('kind'=>array('bar','line','histogram','pie','doughnut','scatter'),'x'=>array('name','time','range','x','y'),'y'=>array('value','count','share','x','y'),'baseline'=>array(0,2),'binWidth'=>array(2,4));$value=$m['value']??null;
            if(!in_array($value,$choices[$key],true)||$p[$key]===$value)throw new \InvalidArgumentException('다른 유효한 타일 설정을 선택하세요.');
            R::charge($s,1);$p[$key]=$value;
            if(in_array($key,array('kind','binWidth'),true)){$p['slots']=array_fill(0,self::slots($p,$panel),array());$p['positions']=array();}
            $s['message']='작업판 '.($index+1).'의 구성을 바꿨습니다.'.(in_array($key,array('kind','binWidth'),true)?' 자료 타일을 다시 배치하세요.':'');
        }elseif($type==='place'){
            $row=R::index($m,'row',$count);
            if($p['kind']==='scatter'){
                $x=R::index($m,'x',11);$y=R::index($m,'y',11);if(isset($p['positions'][$row]))throw new \InvalidArgumentException('이미 놓은 점입니다. 먼저 회수하세요.');
                R::charge($s,1);$p['positions'][$row]=array('x'=>$x,'y'=>$y);
            }else{
                $slot=R::index($m,'slot',self::slots($p,$panel));
                if(in_array($row,array_merge(...$p['slots']),true))throw new \InvalidArgumentException('이미 놓은 기록입니다. 먼저 회수하세요.');
                if($p['kind']!=='histogram'&&$p['slots'][$slot])throw new \InvalidArgumentException('빈 칸을 선택하세요.');
                R::charge($s,1);$p['slots'][$slot][]=$row;
            }
            $s['message']='작업판 '.($index+1).'에 자료를 배치했습니다.';
        }elseif($type==='remove'){
            $row=R::index($m,'row',$count);
            if(!isset($p['positions'][$row])&&!in_array($row,array_merge(...$p['slots']),true))throw new \InvalidArgumentException('놓이지 않은 기록입니다.');
            R::charge($s,1);unset($p['positions'][$row]);foreach($p['slots'] as &$slot)$slot=array_values(array_filter($slot,static fn($id)=>$id!==$row));unset($slot);
        }else{
            if($p['kind']==='scatter')throw new \InvalidArgumentException('좌표의 점은 회수한 뒤 다시 놓으세요.');
            $a=R::index($m,'a',count($p['slots']));$b=R::index($m,'b',count($p['slots']));if($a===$b)throw new \InvalidArgumentException('서로 다른 칸을 선택하세요.');
            R::charge($s,1);[$p['slots'][$a],$p['slots'][$b]]=[$p['slots'][$b],$p['slots'][$a]];
        }
        unset($p);return R::after($s);
    }
    private static function validate(array $p,array $d): array {
        $rows=$d['rows'];$placed=$p['kind']==='scatter'?array_keys($p['positions']):array_merge(...$p['slots']);
        $coverage=count($placed)===count($rows)&&count(array_unique($placed))===count($rows);$structure=false;$order=true;
        $baseline=$p['baseline']===0||($p['kind']==='line'&&$p['baseline']<=min(array_column($rows,'value')));
        if($d['purpose']==='distribution'){
            $structure=$p['kind']==='histogram'&&$p['x']==='range'&&$p['y']==='count';
            foreach($p['slots'] as $bin=>$items)foreach($items as $id)if((int)floor($rows[$id]['value']/$p['binWidth'])!==$bin)$order=false;
        }elseif($d['purpose']==='share'){
            $structure=$p['x']==='name'&&(($p['kind']==='bar'&&$p['y']==='share')||(in_array($p['kind'],array('pie','doughnut'),true)&&in_array($p['y'],array('share','value'),true)));
            $order=array_sum(array_column($rows,'value'))>0&&count(array_filter($p['slots'],static fn($a)=>count($a)===1))===count($rows);
        }elseif($d['purpose']==='relationship'){
            $structure=$p['kind']==='scatter'&&$p['x']==='x'&&$p['y']==='y';
            foreach($p['positions'] as $id=>$point)if($point['x']!==$rows[$id]['x']||$point['y']!==$rows[$id]['y'])$order=false;
        }else{
            $trend=$d['purpose']==='trend';$types=$trend?($d['lineOnly']?array('line'):array('bar','line')):array('bar');
            $structure=in_array($p['kind'],$types,true)&&$p['x']===($trend?'time':'name')&&$p['y']==='value';
            $values=array_map(static fn($id)=>$rows[$id][$trend?'time':'value'],$placed);$sorted=$values;
            if($trend||$d['direction']==='asc')sort($sorted);else rsort($sorted);
            $order=$values===$sorted&&count(array_filter($p['slots'],static fn($a)=>count($a)===1))===count($rows);
        }
        return array('전체 자료'=>$coverage,'차트·축'=>$structure,'배치·구간'=>$order,'기준선'=>$baseline);
    }
    public static function view(array $s): array {
        $d=self::levels()[$s['level']];$plots=array();foreach($s['plots'] as $i=>$p)$plots[]=$p+$d['panels'][$i];
        return R::view($s,$d)+array('plots'=>$plots)+$plots[0];
    }
    public static function solution(string $id): array {
        $out=array();foreach(self::levels()[$id]['panels'] as $i=>$d){
            $settings=match($d['purpose']){'compare'=>array(),'trend'=>$d['lineOnly']?array('kind'=>'line','x'=>'time'):array('x'=>'time'),'distribution'=>array('kind'=>'histogram','x'=>'range','y'=>'count'),'share'=>array('kind'=>'pie','x'=>'name','y'=>'share'),'relationship'=>array('kind'=>'scatter','x'=>'x','y'=>'y')};
            foreach($settings as $key=>$value){if($key==='x'&&$value==='name')continue;$out[]=array('type'=>'configure','key'=>$key,'value'=>$value)+($i?array('panel'=>$i):array());}
            $rows=$d['rows'];$ids=range(0,count($rows)-1);
            if($d['purpose']==='compare')usort($ids,static fn($a,$b)=>($rows[$a]['value']<=>$rows[$b]['value'])*($d['direction']==='asc'?1:-1));
            if($d['purpose']==='trend')usort($ids,static fn($a,$b)=>$rows[$a]['time']<=>$rows[$b]['time']);
            foreach($ids as $slot=>$row){$move=array('type'=>'place','row'=>$row);if($d['purpose']==='relationship')$move+=array('x'=>$rows[$row]['x'],'y'=>$rows[$row]['y']);else $move['slot']=$d['purpose']==='distribution'?(int)floor($rows[$row]['value']/2):$slot;$out[]=$move+($i?array('panel'=>$i):array());}
        }
        $out[]=array('type'=>'finish');return $out;
    }
}
