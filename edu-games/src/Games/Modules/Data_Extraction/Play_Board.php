<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Data_Extraction;
use EduGames\Play\Board;

final class Play_Board extends Board {
    public static function levels(): array {return array('board-01'=>array('title'=>'두 매장의 큰 상품'),'board-02'=>array('title'=>'서로 다른 주문 조건'));}
    private static function batch(int $turn): array {
        $rows=array();
        for($i=0;$i<8;$i++)$rows[]=array('id'=>$turn*8+$i+1,'name'=>array('공','컵','상자','모자')[($i+$turn)%4],'color'=>($i+$turn)%2===0?'red':'blue','size'=>1+(($i*3+$turn)%5),'private'=>'내부 원가');
        return $rows;
    }
    private static function accepts(array $r,string $rule): bool {
        if($rule==='all')return true;
        if($rule==='red'||$rule==='blue')return isset($r['color'])&&$r['color']===$rule;
        return isset($r['size'])&&($rule==='large'?$r['size']>=3:$r['size']<3);
    }
    private static function wanted(array $r,int $customer,string $id): bool {
        if($id==='board-01')return $r['size']>=3&&$r['color']===($customer===0?'red':'blue');
        return $customer===0?$r['color']==='red':$r['size']>=3;
    }
    public static function create(string $id): array {
        $s=self::begin($id);
        $s+=array('objective'=>'분류기를 연결해 새 입고 3묶음을 두 고객에게 자동 배송하세요. 고객에게는 번호와 이름만 전달해야 합니다.','contracts'=>$id==='board-01'?array('A: 빨강, 크기 3 이상','B: 파랑, 크기 3 이상'):array('A: 빨강 전체','B: 색과 무관하게 크기 3 이상'),'nodes'=>array(),'sinks'=>array(3,5),'correct'=>0,'wrong'=>0,'missing'=>0,'trace'=>array(),'outputs'=>array());
        foreach(array(0,1,2,1,4) as $i=>$source)$s['nodes'][]=array('source'=>$source,'kind'=>'filter','rule'=>'all','fields'=>array('id','name'));
        return $s;
    }
    public static function apply(array $s,array $m): array {
        $type=self::guard($s,$m,array('node','sink','run'));
        if($type==='node'){
            $i=self::integer($m['node']??null,1,5);
            $kind=self::choice($m['kind']??null,array('filter','project'));
            $fields=$m['fields']??array('id','name');
            if(!is_array($fields)||count($fields)<1||count($fields)>5)throw new \InvalidArgumentException('전달할 열을 선택하세요.');
            foreach($fields as $f)self::choice($f,array('id','name','color','size','private'));
            $s['nodes'][$i-1]=array('source'=>self::integer($m['source']??null,0,$i-1),'kind'=>$kind,'rule'=>self::choice($m['rule']??'all',array('all','red','blue','large','small')),'fields'=>array_values(array_unique($fields)));
            return self::note($s,$i.'번 처리기 연결을 바꿨습니다.');
        }
        if($type==='sink'){$i=self::integer($m['customer']??null,0,1);$s['sinks'][$i]=self::integer($m['source']??null,0,5);return $s;}
        $steps=self::integer($m['steps']??1,1,3);
        if($steps>1){for($i=0;$i<$steps&&$s['status']==='playing';$i++)$s=self::apply($s,array('type'=>'run','steps'=>1));return $s;}
        $input=self::batch($s['turn']);$outputs=array($input);$s['trace']=array();
        foreach($s['nodes'] as $i=>$node){
            $rows=$outputs[$node['source']];$filtered=array();
            foreach($rows as $r){
                if($node['kind']==='filter'){if(self::accepts($r,$node['rule']))$filtered[]=$r;}
                else $filtered[]=array_intersect_key($r,array_flip($node['fields']));
            }
            $outputs[]=$filtered;$s['trace'][]=array('node'=>$i+1,'in'=>count($rows),'out'=>count($filtered),'ids'=>array_column($filtered,'id'));
        }
        $s['outputs']=array();
        foreach($s['sinks'] as $c=>$source){
            $expected=array();foreach($input as $r)if(self::wanted($r,$c,$s['level']))$expected[]=$r['id'];
            $sent=$outputs[$source];$accepted=array();$wrong=0;
            foreach($sent as $r){
                $valid=isset($r['id'],$r['name'])&&count($r)===2&&in_array($r['id'],$expected,true)&&!in_array($r['id'],$accepted,true);
                if($valid)$accepted[]=$r['id'];else $wrong++;
            }
            $missing=count(array_diff($expected,$accepted));$s['correct']+=count($accepted);$s['wrong']+=$wrong;$s['missing']+=$missing;
            $s['outputs'][]=array('customer'=>$c===0?'A':'B','accepted'=>$accepted,'wrong'=>$wrong,'missing'=>$missing,'rows'=>$sent);
        }
        $s['turn']++;$s['metrics']=array('정상 배송'=>$s['correct'],'반송'=>$s['wrong'],'누락'=>$s['missing']);
        $s=self::note($s,$s['turn'].'차 자동 배송 완료. 필터에서 사라진 열은 뒤의 처리기가 사용할 수 없습니다.');
        if($s['turn']===3)return self::finish($s,$s['correct']*50-$s['wrong']*30-$s['missing']*20,$s['wrong']===0&&$s['missing']===0,'입고 종료. 같은 연결망이 서로 다른 세 묶음을 처리했습니다.');
        return $s;
    }
    public static function view(array $s): array {$s['input']=self::batch(min(2,$s['turn']));return parent::view($s);}
}
