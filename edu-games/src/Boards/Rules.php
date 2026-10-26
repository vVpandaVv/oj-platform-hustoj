<?php
declare(strict_types=1);
namespace EduGames\Boards;

final class Rules {
    public static function begin(string $id, array $levels): array {
        if (!isset($levels[$id])) { throw new \InvalidArgumentException('보드를 확인하세요.'); }
        $d=$levels[$id];
        return array('level'=>$id,'status'=>'playing','budget'=>$d['budget'],'spent'=>0,'moves'=>0,'mistakes'=>0,'score'=>0,'message'=>'도전이 시작되었습니다.','evaluation'=>array());
    }
    public static function guard(array $s): void {
        if ($s['status']!=='playing') { throw new \InvalidArgumentException('종료된 도전입니다. 새 도전을 시작하세요.'); }
    }
    public static function charge(array &$s,int $cost): void {
        self::guard($s);
        if ($cost<0 || $s['spent']+$cost>$s['budget']) { throw new \InvalidArgumentException('자원이 부족합니다. 현재 결과로 도전을 종료할 수 있습니다.'); }
        $s['spent']+=$cost; $s['moves']++;
    }
    public static function finish(array &$s,bool $won,array $definition,string $message,int $penalty=0): void {
        $s['status']=$won?'won':'lost';
        $s['score']=$won?max(100,1000-20*max(0,$s['spent']-$definition['referenceCost'])-50*$s['mistakes']-$penalty):0;
        $s['message']=$message;
    }
    public static function after(array $s): array {
        if ($s['status']==='playing' && $s['spent'] >= $s['budget']) { $s['status']='lost';$s['message']='자원을 모두 사용했습니다. 배치를 바꾸어 다시 도전하세요.'; }
        return $s;
    }
    public static function score(array $s): int { return $s['status']==='won'?(int)$s['score']:0; }
    public static function stars(int $score): int { return $score>=975?3:($score>=850?2:($score>0?1:0)); }
    public static function view(array $s,array $d): array {
        return array_intersect_key($s,array_flip(array('level','status','budget','spent','moves','mistakes','score','message','evaluation')))+array('title'=>$d['title'],'chapter'=>$d['chapter']??'기초 사건','goal'=>$d['goal'],'referenceCost'=>$d['referenceCost'],'stars'=>self::stars(self::score($s)));
    }
    public static function choice(array $move,string $key,array $allowed): string {
        $value=$move[$key]??null;
        if (!is_string($value)||!in_array($value,$allowed,true)) { throw new \InvalidArgumentException('선택 항목을 확인하세요: '.$key); }
        return $value;
    }
    public static function index(array $move,string $key,int $count): int {
        $value=$move[$key]??null;
        if (!is_int($value)||$value<0||$value>=$count) { throw new \InvalidArgumentException('위치를 확인하세요: '.$key); }
        return $value;
    }
}
