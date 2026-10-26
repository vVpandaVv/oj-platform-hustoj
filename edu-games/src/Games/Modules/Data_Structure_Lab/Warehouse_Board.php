<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Data_Structure_Lab;

/** A stateful routing puzzle. Only legal structure operations can move cargo. */
final class Warehouse_Board {
    public const VERSION = 'warehouse-1';
    public const GAME = 'data-structure-lab';

    public static function levels(): array {
        $plans = array(
            array('첫 출고', 'ABCD', 'BADC', array('stack', 'queue'), 3),
            array('역순 창고', 'ABCDE', 'EDCBA', array('stack', 'queue'), 3),
            array('엇갈린 주문', 'ABCDE', 'CADBE', array('stack', 'queue'), 3),
            array('작은 보관함', 'ABCDEF', 'CFBEAD', array('stack', 'queue'), 3),
            array('우선 배송', 'ABCDE', 'DAEBC', array('stack', 'queue', 'priority'), 3),
            array('동률의 순서', 'ABCDEF', 'BDEACF', array('stack', 'queue', 'priority'), 3),
            array('우선순위 역전', 'ABCDEF', 'FEDCBA', array('stack', 'queue', 'priority'), 3),
            array('세 갈래 물류', 'ABCDEF', 'ECADFB', array('stack', 'queue', 'priority'), 2),
            array('좁은 통로', 'ABCDEF', 'FDBEAC', array('stack', 'queue', 'priority'), 2),
            array('교차 배송', 'ABCDEF', 'CEBFAD', array('stack', 'queue', 'priority'), 2),
            array('마지막 급행', 'ABCDEF', 'EBDCAF', array('stack', 'queue', 'priority'), 2),
            array('물류 마스터', 'ABCDEF', 'FADEBC', array('stack', 'queue', 'priority'), 2),
        );
        $result = array();
        foreach ($plans as $i => [$title, $input, $target, $lanes, $capacity]) {
            $id = sprintf('route-%02d', $i + 1);
            $result[$id] = array('id'=>$id, 'number'=>$i+1, 'title'=>$title, 'input'=>str_split($input), 'target'=>str_split($target), 'lanes'=>$lanes, 'capacity'=>$capacity, 'priorities'=>array('A'=>1,'B'=>3,'C'=>2,'D'=>3,'E'=>2,'F'=>1));
        }
        return $result;
    }

    public static function create(string $level): array {
        $definition = self::levels()[$level] ?? null;
        if (!$definition) { throw new \InvalidArgumentException('구역을 확인하세요.'); }
        $solutions = require __DIR__.'/warehouse-solutions.php';
        return array('level'=>$level, 'position'=>array('cursor'=>0,'delivered'=>0,'lanes'=>array_fill_keys($definition['lanes'],array())), 'moves'=>0,'mistakes'=>0,'undos'=>0,'history'=>array(),'status'=>'playing','par'=>count($solutions[$level]),'budget'=>count($solutions[$level])+10,'message'=>'출고 주문을 확인하세요.','version'=>self::VERSION);
    }

    private static function head(array $items, string $lane, array $priorities): ?string {
        if (!$items) { return null; }
        if ($lane === 'stack') {
            $stack = new \SplStack();
            foreach ($items as $item) { $stack->push($item); }
            return $stack->pop();
        }
        if ($lane === 'queue') {
            $queue = new \SplQueue();
            foreach ($items as $item) { $queue->enqueue($item); }
            return $queue->dequeue();
        }
        $queue = new \SplPriorityQueue();
        foreach ($items as $i=>$item) { $queue->insert($item, array($priorities[$item], -$i)); }
        return $queue->extract();
    }

    public static function move(array $position, array $definition, array $action): array {
        $type = $action['type'] ?? '';
        $to = $action['to'] ?? '';
        $from = $action['from'] ?? '';
        if (!is_string($type)||!is_string($to)||!is_string($from)) { throw new \InvalidArgumentException('이동 형식이 잘못되었습니다.'); }
        if ($type === 'load' || $type === 'transfer') {
            if (!array_key_exists($to, $position['lanes']) || count($position['lanes'][$to]) >= $definition['capacity']) { throw new \InvalidArgumentException('보관함이 가득 찼거나 없는 보관함입니다.'); }
        }
        if ($type === 'load') {
            $item = $definition['input'][$position['cursor']] ?? null;
            if (null === $item) { throw new \InvalidArgumentException('입고할 화물이 없습니다.'); }
            $position['lanes'][$to][] = $item;
            $position['cursor']++;
            return array($position, false, $item.' 입고');
        }
        if (!in_array($type, array('ship','transfer'), true) || !array_key_exists($from, $position['lanes']) || ($type==='transfer' && $from===$to)) { throw new \InvalidArgumentException('허용되지 않는 이동입니다.'); }
        $item = self::head($position['lanes'][$from], $from, $definition['priorities']);
        if (null === $item) { throw new \InvalidArgumentException('빈 보관함입니다.'); }
        if ($type==='ship' && $item!==$definition['target'][$position['delivered']]) {
            return array($position, true, '지금 꺼낼 수 있는 화물은 '.$item.'입니다. 주문은 '.$definition['target'][$position['delivered']].'입니다. 에너지 1을 사용했습니다.');
        }
        $index = array_search($item, $position['lanes'][$from], true);
        array_splice($position['lanes'][$from], $index, 1);
        if ($type==='ship') { $position['delivered']++; }
        else { $position['lanes'][$to][] = $item; }
        return array($position, false, $item.($type==='ship'?' 출고 완료':' 이동 완료'));
    }

    public static function apply(array $state, array $action): array {
        if ($state['status']!=='playing') { throw new \InvalidArgumentException('끝난 도전입니다. 다시 도전하세요.'); }
        if (($action['type']??null)==='undo') {
            if (!$state['history'] || $state['undos']>=3) { throw new \InvalidArgumentException('되돌리기를 사용할 수 없습니다.'); }
            $state['position'] = array_pop($state['history']);
            $state['undos']++;
            $state['message'] = '배치를 되돌렸습니다. 사용한 에너지는 복구되지 않습니다.';
        } else {
            [$position, $wrong, $message] = self::move($state['position'], self::levels()[$state['level']], $action);
            if (!$wrong) { $state['history'][] = $state['position']; }
            $state['position'] = $position;
            $state['mistakes'] += (int)$wrong;
            $state['message'] = $message;
        }
        $state['moves']++;
        $count = count(self::levels()[$state['level']]['target']);
        if ($state['position']['delivered']===$count) { $state['status']='won'; }
        elseif ($state['moves'] >= $state['budget']) { $state['status']='lost'; $state['message']='에너지를 모두 사용했습니다. 같은 배치로 다시 도전할 수 있습니다.'; }
        return $state;
    }

    public static function score(array $state): int {
        if ($state['status']!=='won') { return 0; }
        return max(100, 1000-25*max(0,$state['moves']-$state['par'])-80*$state['mistakes']-100*$state['undos']);
    }

    public static function stars(int $score): int { return $score>=975?3:($score>=850?2:($score>0?1:0)); }

    public static function view(array $state): array {
        $definition = self::levels()[$state['level']];
        $heads = array();
        foreach ($state['position']['lanes'] as $lane=>$items) { $heads[$lane]=self::head($items,$lane,$definition['priorities']); }
        $view = array_intersect_key($state,array_flip(array('level','position','moves','mistakes','undos','status','par','budget','message','version')));
        return $view + array('definition'=>$definition,'heads'=>$heads,'canUndo'=>$state['status']==='playing'&&$state['undos']<3&&(bool)$state['history'],'score'=>self::score($state),'stars'=>self::stars(self::score($state)));
    }

    /** Breadth-first search is used by the offline catalog verifier, never on the REST hot path. */
    public static function solve(array $definition, int $limit=350000): array {
        $start = array('cursor'=>0,'delivered'=>0,'lanes'=>array_fill_keys($definition['lanes'],array()));
        $queue = new \SplQueue(); $queue->enqueue(0);
        $nodes = array(array($start, -1, null)); $seen = array(json_encode($start)=>true);
        while (!$queue->isEmpty()) {
            $n=$queue->dequeue(); $position=$nodes[$n][0];
            if ($position['delivered']===count($definition['target'])) {
                $path=array();
                while ($nodes[$n][1]!==-1) { $path[]=$nodes[$n][2]; $n=$nodes[$n][1]; }
                return array_reverse($path);
            }
            $actions=array();
            foreach ($definition['lanes'] as $lane) {
                $actions[]=array('type'=>'load','to'=>$lane);
                $actions[]=array('type'=>'ship','from'=>$lane);
                foreach ($definition['lanes'] as $to) { if ($to!==$lane) { $actions[]=array('type'=>'transfer','from'=>$lane,'to'=>$to); } }
            }
            foreach ($actions as $action) {
                try { [$next,$wrong]=self::move($position,$definition,$action); } catch (\InvalidArgumentException $e) { continue; }
                if ($wrong) { continue; }
                $key=json_encode($next);
                if (isset($seen[$key])) { continue; }
                $seen[$key]=true; $nodes[]=array($next,$n,$action); $queue->enqueue(count($nodes)-1);
                if (count($nodes)>$limit) { throw new \RuntimeException('Solver limit reached: '.$definition['id']); }
            }
        }
        throw new \RuntimeException('Unsolvable board: '.$definition['id']);
    }
}
