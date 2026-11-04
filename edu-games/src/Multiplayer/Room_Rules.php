<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;

final class Room_Rules {
    public const BINARY='binary-conversion',DEVICE='device-matching';
    public static function game(array $s): string {return $s['game']??self::BINARY;}
    public static function engine(string $game): string {
        return match($game){self::BINARY=>Binary_Room::class,self::DEVICE=>Device_Room::class,default=>throw new Failure('팀전 게임을 확인하세요.',422)};
    }
    public static function create(string $code,array $actor,array $config,int $now,string $seed,string $game): array {
        $s=self::engine($game)::create($code,$actor,$config,$now,$seed);$s['game']=$game;return $s;
    }
    public static function command(array $s,array $actor,array $c,int $now): array {return self::engine(self::game($s))::command($s,$actor,$c,$now);}
    public static function tick(array $s,int $now): array {return Bot_Runner::advance($s,$now);}
    public static function view(array $s,array $actor,int $now,int $watch=0): array {
        Room_Policy::access($s,$actor,null);
        return (($actor['scope']??'play')==='play'?self::engine(self::game($s))::view($s,$actor,$now,$watch):Room_Overview::view($s,$actor,$now))+array('game'=>self::game($s));
    }
}
