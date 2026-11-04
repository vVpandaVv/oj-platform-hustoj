<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;

final class Relay {
    public static function handle(Repository $store,array $input,string $secret,int $now): array {
        if(!is_string($input['ticket']??null))throw new Failure('입장권을 확인하세요.',401);
        if(!is_string($input['code']??null))throw new Failure('방 코드를 확인하세요.',422);
        $game=$input['game']??Room_Rules::BINARY;if(!is_string($game))throw new Failure('팀전 종류를 확인하세요.',422);Room_Rules::engine($game);
        $actor=Ticket::read($input['ticket'],$secret,(int)floor($now/1000),$game);
        $command=$input['command']??null;
        if($command!==null&&!is_array($command))throw new Failure('요청 형식을 확인하세요.',422);
        $watch=Binary_Room::integer($input['watch']??0,0,6);
        return (new Service($store,$game))->access($input['code'],$actor,$command,$now,$watch);
    }
}
