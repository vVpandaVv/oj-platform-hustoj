<?php
declare(strict_types=1);
namespace EduGames\Tests;
use EduGames\Multiplayer\Repository;
use EduGames\Multiplayer\Failure;

final class Binary_Memory_Repository implements Repository {
    public array $rooms=array(),$results=array();
    public bool $failPut=false,$failResult=false,$failDelete=false;
    public function locked(string $key,callable $operation): mixed{return $operation();}
    public function get(string $code): ?array{return $this->rooms[$code]??null;}
    public function create(array $room): void{$this->rooms[$room['code']]=$room;}
    public function put(array $room): void{if($this->failPut)throw new Failure('write failed',503);$this->rooms[$room['code']]=$room;}
    public function delete(string $code): void{if($this->failDelete)throw new Failure('delete failed',503);unset($this->rooms[$code]);}
    public function recent(int $user,bool $admin=false): array{return array_values(array_filter($this->rooms,static fn($r)=>$admin||$r['host']===$user||isset($r['players'][(string)$user])));}
    public function save_result(array $result): void{if($this->failResult)throw new Failure('result failed',503);$this->results[$result['id']]??=$result;}
    public function result(string $id): ?array{return $this->results[$id]??null;}
    public function records(int $user,bool $admin,int $page,string $game=\EduGames\Multiplayer\Room_Rules::BINARY,string $mode='all'): array{
        $items=array_values(array_filter($this->results,static fn($r)=>($mode==='all'||\EduGames\Multiplayer\Room_Policy::mode($r)===$mode)&&\EduGames\Multiplayer\Room_Rules::game($r)===$game&&($admin||$r['host']===$user||in_array($user,array_column($r['players'],'id'),true))));
        usort($items,static fn($a,$b)=>$b['finishedAt']<=>$a['finishedAt']);$total=count($items);$pages=max(1,(int)ceil($total/20));$page=max(1,min($page,$pages));
        return array('items'=>array_slice($items,($page-1)*20,20),'pagination'=>array('page'=>$page,'pages'=>$pages,'total'=>$total,'perPage'=>20));
    }
}
