<?php
declare(strict_types=1);
namespace EduGames\Games\Modules\Data_Structure_Lab;

use EduGames\Play\Hands_Board as Base;

final class Hands_Board extends Base {
    public const CONTENT_VERSION = 'warehouse-rush-v2';
    private const CAPACITY = 5;
    private const BUFFER = 2;

    public static function create(string $level): array {
        $s = self::fresh($level);
        $s += array('incoming'=>array(), 'stack'=>array(), 'queue'=>array(), 'buffer'=>array(),
            'orders'=>array(), 'nextOrder'=>1, 'nextCargo'=>1, 'contracts'=>0, 'moves'=>0,
            'relocations'=>0, 'rescues'=>0, 'efficient'=>0, 'bestCombo'=>0, 'handling'=>array(),
            'earnings'=>array('delivery'=>0, 'efficiency'=>0, 'combo'=>0, 'contracts'=>0, 'express'=>0, 'cost'=>0));
        for ($i=0; $i<3; $i++) $s = self::newOrder($s, $i, 0);
        // Interleave the three destinations so storage choices matter from the first arrival.
        $s['incoming'] = self::shuffle($s, $s['incoming']);
        return self::note($s, '출고장 3곳이 문을 열었습니다. 정확한 배송과 효율적인 적재로 최고 기록에 도전하세요.');
    }

    private static function elapsed(array $s): float {
        return max(0, $s['duration'] - ($s['deadline'] - microtime(true)));
    }

    private static function newOrder(array $s, int $slot, float $elapsed): array {
        $id = $s['nextOrder']++;
        $size = 3 + min(1, intdiv($s['contracts'], 4));
        $cargo = array();
        for ($i=0; $i<$size; $i++) {
            $n = $s['nextCargo']++;
            $item = chr(65 + ($n-1)%6).'-'.str_pad((string)$n, 2, '0', STR_PAD_LEFT);
            $cargo[] = $item;
            $s['handling'][$item] = 0;
        }
        $s['incoming'] = array_merge($s['incoming'], self::shuffle($s, $cargo));
        $express = $id%3 === 2;
        $window = $id <= 3 ? 45 + $slot*15 : 40;
        $s['orders'][$slot] = array('id'=>$id, 'manifest'=>$cargo, 'items'=>$cargo,
            'express'=>$express, 'window'=>$window, 'due'=>round($elapsed+$window, 2));
        return $s;
    }

    private static function orderIndex(array $s, array $m): int {
        $id = self::integer($m['order']??null, 1, PHP_INT_MAX);
        foreach ($s['orders'] as $i=>$order) if ($order['id'] === $id) return $i;
        throw new \InvalidArgumentException('이 주문은 이미 출고되었습니다. 현재 출고장을 다시 선택하세요.');
    }

    private static function changed(array $s, string $item, string $from, string $to, int $before, string $kind, string $message): array {
        $s['moves']++;
        $s['turn']++;
        $s['feedback'] = array('item'=>$item, 'from'=>$from, 'to'=>$to,
            'delta'=>$s['score']-$before, 'kind'=>$kind, 'move'=>$s['moves']);
        return self::note($s, $message);
    }

    private static function cost(array $s, int $points): array {
        $deduction = min($s['score'], $points);
        $s['score'] -= $deduction;
        $s['earnings']['cost'] += $deduction;
        $s['combo'] = 0;
        return $s;
    }

    private static function deliver(array $s, string $item, int $slot, string $from, int $before): array {
        $order = $s['orders'][$slot];
        if ($item !== $order['items'][0]) throw new \InvalidArgumentException('이 출고장의 다음 물건은 '.$order['items'][0].'입니다.');
        $clean = $s['handling'][$item] <= 2;
        $s['combo']++;
        $s['bestCombo'] = max($s['bestCombo'], $s['combo']);
        $combo = min(5, $s['combo'])*2;
        $s['score'] += 30 + ($clean ? 10 : 0) + $combo;
        $s['earnings']['delivery'] += 30;
        $s['earnings']['efficiency'] += $clean ? 10 : 0;
        $s['earnings']['combo'] += $combo;
        $s['completed']++;
        $s['efficient'] += $clean ? 1 : 0;
        unset($s['handling'][$item]);
        array_shift($s['orders'][$slot]['items']);
        $message = $item.' 배송 완료'.($clean ? ' · 바로 배송 +10' : '').' · 연속 +'.$combo;
        if (!$s['orders'][$slot]['items']) {
            $s['contracts']++;
            $s['score'] += 50;
            $s['earnings']['contracts'] += 50;
            $left = max(0, $order['due']-self::elapsed($s));
            $bonus = $order['express'] ? (int)floor(50*min(1, $left/$order['window'])) : 0;
            $s['score'] += $bonus;
            $s['earnings']['express'] += $bonus;
            $message .= ' · 주문 완수 +50'.($bonus ? ' · 급행 +'.$bonus : '');
            $s = self::newOrder($s, $slot, self::elapsed($s));
        }
        return self::changed($s, $item, $from, 'dock-'.$slot, $before, 'delivery', $message);
    }

    public static function apply(array $s, array $m): array {
        if (self::expired($s, $m)) {
            $s = self::end($s);
            $s['metrics'] = array('deliveries'=>$s['completed'], 'contracts'=>$s['contracts'],
                'efficient'=>$s['efficient'], 'relocations'=>$s['relocations'], 'moves'=>$s['moves'],
                'bestCombo'=>$s['bestCombo'], 'earnings'=>$s['earnings']);
            return $s;
        }
        $type = self::guard($s, $m, array('push', 'return', 'pop', 'park', 'ship-buffer', 'reset'));
        $before = $s['score'];
        if ($type === 'reset') {
            $items = array_merge($s['stack'], $s['queue'], $s['buffer']);
            if (!$items) throw new \InvalidArgumentException('재입고할 물건이 없습니다.');
            $s['incoming'] = array_merge($items, $s['incoming']);
            $s['stack'] = $s['queue'] = $s['buffer'] = array();
            foreach ($items as $item) $s['handling'][$item]++;
            $s['rescues']++;
            $s = self::cost($s, 25);
            return self::changed($s, '남은 물건', 'storage', 'incoming', $before, 'rescue', '긴급 재입고 · 보관 물건을 입고열 앞으로 이동 · 25점 비용');
        }
        if ($type === 'ship-buffer') {
            $id = self::integer($m['id']??null, 0, count($s['buffer'])-1);
            $slot = self::orderIndex($s, $m);
            $item = $s['buffer'][$id];
            if ($item !== $s['orders'][$slot]['items'][0]) throw new \InvalidArgumentException('이 출고장의 다음 물건은 '.$s['orders'][$slot]['items'][0].'입니다.');
            array_splice($s['buffer'], $id, 1);
            $s['handling'][$item]++;
            return self::deliver($s, $item, $slot, 'buffer', $before);
        }
        $lane = self::choice($m['lane']??null, array('stack', 'queue'));
        if ($type === 'push' || $type === 'return') {
            if (count($s[$lane]) >= self::CAPACITY) throw new \InvalidArgumentException('적재 공간 5칸이 찼습니다. 출구의 물건을 먼저 이동하세요.');
            if ($type === 'push') {
                if (!$s['incoming']) throw new \InvalidArgumentException('입고 대기 물건이 없습니다.');
                $item = array_shift($s['incoming']);
            } else {
                $id = self::integer($m['id']??null, 0, count($s['buffer'])-1);
                $item = $s['buffer'][$id];
                array_splice($s['buffer'], $id, 1);
                $s = self::cost($s, 3);
                $s['relocations']++;
            }
            $s[$lane][] = $item;
            $s['handling'][$item]++;
            return self::changed($s, $item, $type === 'push' ? 'incoming' : 'buffer', $lane, $before, 'store', $item.' → '.($lane === 'stack' ? '스택 맨 위' : '큐 맨 뒤').($type === 'return' ? ' · 재배치 3점 비용' : ''));
        }
        if (!$s[$lane]) throw new \InvalidArgumentException('출구에 물건이 없습니다.');
        $slot = $type === 'pop' ? self::orderIndex($s, $m) : null;
        $item = $lane === 'stack' ? end($s[$lane]) : $s[$lane][0];
        $match = $slot !== null && $item === $s['orders'][$slot]['items'][0];
        if (!$match && count($s['buffer']) >= self::BUFFER) throw new \InvalidArgumentException('임시 보관 2칸이 찼습니다. 보관 물건을 배송하거나 다시 적재하세요.');
        if ($lane === 'stack') array_pop($s[$lane]); else array_shift($s[$lane]);
        $s['handling'][$item]++;
        if ($match) return self::deliver($s, $item, $slot, $lane, $before);
        $s['buffer'][] = $item;
        $s['relocations']++;
        $s = self::cost($s, 3);
        return self::changed($s, $item, $lane, 'buffer', $before, 'park', $item.' → 임시 보관 · '.($type === 'pop' ? '선택한 주문의 다음 물건과 다릅니다 · ' : '').'3점 비용');
    }

    public static function view(array $s): array {
        $elapsed = self::elapsed($s);
        $v = parent::view($s);
        unset($v['handling'], $v['nextCargo'], $v['nextOrder']);
        foreach ($v['orders'] as &$order) {
            $order['remaining'] = round(max(0, $order['due']-$elapsed), 2);
            unset($order['due']);
        }
        unset($order);
        $v['capacity'] = self::CAPACITY;
        $v['bufferCapacity'] = self::BUFFER;
        return $v;
    }
}
