<?php
declare(strict_types=1);
namespace EduGames\Play;

abstract class Hands_Board extends Board {
    public static function levels(): array {
        return array('board-01'=>array('title'=>'오늘의 도전 · '.\EduGames\Support\Clock::today()));
    }
    protected static function fresh(string $level, int $seconds=120): array {
        $s=parent::begin($level);
        $s['seed']=(int)sprintf('%u',crc32(\EduGames\Support\Clock::today().static::class));
        $s['challenge']=\EduGames\Support\Clock::today();
        $s['duration']=$seconds;
        $s['deadline']=microtime(true)+$seconds;
        $s['combo']=0;
        $s['completed']=0;
        return $s;
    }
    protected static function number(array &$s,int $low,int $high): int {
        $s['seed']=(int)(($s['seed']*1664525+1013904223)%4294967296);
        return $low+intdiv($s['seed'],256)%($high-$low+1);
    }
    protected static function shuffle(array &$s,array $items): array {
        for($i=count($items)-1;$i>0;$i--){$j=self::number($s,0,$i);[$items[$i],$items[$j]]=[$items[$j],$items[$i]];}
        return $items;
    }
    protected static function expired(array $s,array $m): bool {
        if($s['status']!=='playing')throw new \InvalidArgumentException('완료된 도전입니다. 새 도전에서 다시 시작하세요.');
        return microtime(true)>=$s['deadline']||($m['type']??'')==='finish';
    }
    protected static function end(array $s): array {
        $s['status']='won';
        return self::note($s,'도전 종료 · '.$s['score'].'점. 오늘의 같은 데이터로 최고 기록에 다시 도전할 수 있습니다.');
    }
    protected static function award(array $s,int $points,bool $perfect,string $message): array {
        $s['combo']=$perfect?$s['combo']+1:0;
        $s['score']=max(0,$s['score']+$points+($perfect?min(5,$s['combo'])*5:0));
        $s['turn']++;
        if($perfect)$s['completed']++;
        return self::note($s,$message);
    }
    protected static function ids($ids,int $size): array {
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>$size)throw new \InvalidArgumentException('선택한 자료를 확인하세요.');
        foreach($ids as $id)self::integer($id,0,$size-1);
        if(count(array_unique($ids))!==count($ids))throw new \InvalidArgumentException('같은 자료를 두 번 선택할 수 없습니다.');
        return $ids;
    }
    public static function view(array $s): array {
        unset($s['seed'],$s['truth'],$s['lastRequest']);
        $s['remaining']=max(0,round($s['deadline']-microtime(true),2));
        unset($s['deadline']);
        return $s;
    }
}
