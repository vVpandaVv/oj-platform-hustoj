<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\Data_Structure_Lab;

use EduGames\Learning\Question as Q;

final class Learning_Content {
    public static function questions(): array {
        $bank=array();
        foreach(array('stack','queue','priority') as $type) {
            for($v=0;$v<12;$v++) {
                $a=chr(65+$v%6);$b=chr(71+$v%6);$c=chr(77+$v%6);
                $insert='queue'===$type?'enqueue':'push'; $remove='queue'===$type?'dequeue':'pop'; $peek='queue'===$type?'front':'peek';
                $ops=array(array('op'=>$insert,'value'=>$a,'priority'=>2),array('op'=>$insert,'value'=>$b,'priority'=>5),array('op'=>$peek),array('op'=>$remove),array('op'=>$insert,'value'=>$c,'priority'=>5),array('op'=>$remove));
                if($v%4===1){array_unshift($ops,array('op'=>$remove));}
                if($v%4===2){$ops[2]=array('op'=>$insert,'value'=>'Z','priority'=>5);$ops[]=array('op'=>$remove);}
                if($v%4===3){$ops[]=array('op'=>$remove);$ops[]=array('op'=>$remove);}
                $solution=self::trace($type,$ops); $fields=$expected=$rows=array();
                foreach($ops as $i=>$op){
                    $rows[]=array('단계'=>$i+1,'명령'=>$op['op'],'값'=>$op['value']??'','우선순위'=>'priority'===$type?($op['priority']??''):'');
                    $fields[]=Q::field("state-$i",($i+1).'단계 이후 상태','tokens');$expected["state-$i"]=$solution['states'][$i];
                }
                $fields[]=Q::field('outputs','조회/삭제가 반환한 값 순서','tokens');$expected['outputs']=$solution['outputs'];
                $options=array();foreach($ops as $i=>$op){$options[(string)($i+1)]=($i+1).'단계';}
                $fields[]=Q::field('errors','빈 구조 오류가 난 단계 (없으면 선택하지 않음)','multi',$options);$expected['errors']=$solution['errors'];
                $direction='stack'===$type?'왼쪽 바닥, 오른쪽 top':'왼쪽이 다음 처리 대상';
                $names=array('stack'=>'스택','queue'=>'큐','priority'=>'우선순위 큐');
                $rule='priority'===$type?'숫자가 클수록 먼저, 동률이면 먼저 삽입한 항목부터 처리합니다.':('stack'===$type?'나중에 넣은 항목부터 꺼냅니다.':'먼저 넣은 항목부터 꺼냅니다.');
                $bank[]=Q::make("$type-$v",$names[$type].' 상태 추적',$v<4?'easy':($v<8?'normal':'hard'),"빈 {$names[$type]}에서 시작합니다. {$rule} 상태는 {$direction}입니다. 조회는 삭제하지 않습니다. 빈 구조에서 꺼내거나 조회하면 오류만 기록하고 다음 명령을 계속합니다.",array('rows'=>$rows),$fields,$expected,'각 상태는 해당 명령 직후입니다. 조회는 상태를 바꾸지 않고, 빈 구조 오류는 반환값에 임의의 0을 넣지 않습니다.','한 번에 한 명령씩 적용하세요. 반환값과 남은 상태는 다릅니다.',array('type'=>'structure','structure'=>$type,'operations'=>$ops));
            }
        }
        return $bank;
    }

    public static function trace(string $type,array $operations): array {
        $container=match($type){'stack'=>new \SplStack(),'queue'=>new \SplQueue(),'priority'=>new \SplPriorityQueue(),default=>throw new \InvalidArgumentException('Unknown structure')};
        if($container instanceof \SplPriorityQueue){$container->setExtractFlags(\SplPriorityQueue::EXTR_DATA);}
        $states=$outputs=$errors=array();
        foreach($operations as $i=>$op){
            if(in_array($op['op'],array('push','enqueue'),true)) {
                if($container instanceof \SplPriorityQueue){$container->insert($op['value'],array($op['priority'],-$i));}
                elseif($container instanceof \SplQueue){$container->enqueue($op['value']);}
                else{$container->push($op['value']);}
            } elseif($container->isEmpty()) {$errors[]=(string)($i+1);}
            elseif(in_array($op['op'],array('peek','front'),true)) {$outputs[]=$container instanceof \SplQueue?$container->bottom():$container->top();}
            else {$outputs[]=$container instanceof \SplPriorityQueue?$container->extract():($container instanceof \SplQueue?$container->dequeue():$container->pop());}
            $copy=clone $container; $state=array();
            if($copy instanceof \SplPriorityQueue){while(!$copy->isEmpty()){$state[]=$copy->extract();}}
            else {for($j=0;$j<$copy->count();$j++){$state[]=$copy->offsetGet($j);} if('stack'===$type){$state=array_reverse($state);}}
            $states[]=$state;
        }
        return compact('states','outputs','errors');
    }
}
