<?php
declare(strict_types=1);
namespace EduGames\Play;

abstract class Board {
    protected static function begin(string $id): array {
        $levels=static::levels();
        if(!isset($levels[$id]))throw new \InvalidArgumentException('상황을 확인하세요.');
        return array('level'=>$id,'title'=>$levels[$id]['title'],'status'=>'playing','turn'=>0,'score'=>0,'log'=>array(),'message'=>'','metrics'=>array());
    }
    protected static function guard(array $s,array $m,array $types): string {
        if($s['status']!=='playing')throw new \InvalidArgumentException('종료된 도전입니다. 결과를 확인하거나 새로 도전하세요.');
        return self::choice($m['type']??null,$types);
    }
    protected static function choice($value,array $choices): string {
        if(!is_string($value)||!in_array($value,$choices,true))throw new \InvalidArgumentException('행동을 확인하세요.');
        return $value;
    }
    protected static function integer($value,int $min,int $max): int {
        if(!is_int($value)||$value<$min||$value>$max)throw new \InvalidArgumentException('입력 범위를 확인하세요.');
        return $value;
    }
    protected static function note(array $s,string $message): array {
        $s['message']=$message;$s['log'][]=$message;$s['log']=array_slice($s['log'],-12);return $s;
    }
    protected static function finish(array $s,int $score,bool $won,string $message): array {
        $s['score']=max(0,$score);$s['status']=$won?'won':'lost';return self::note($s,$message);
    }
    public static function score(array $s): int {return $s['status']==='playing'?0:max(0,(int)$s['score']);}
    public static function view(array $s): array {unset($s['lastRequest']);return $s;}
}
