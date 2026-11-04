<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;

/** Short-lived, purpose-bound authentication for the lightweight endpoint. */
final class Ticket {
    public static function issue(array $actor,string $secret,int $now,string $game=Room_Rules::BINARY): string {
        if(strlen($secret)<32||(int)$actor['id']<1)throw new Failure('입장권을 발급할 수 없습니다.',503);
        $payload=array('purpose'=>self::purpose($game),'id'=>(int)$actor['id'],'name'=>(string)$actor['name'],
            'manage'=>!empty($actor['manage']),'admin'=>!empty($actor['admin']),'scope'=>$actor['scope']??'play','room'=>$actor['room']??'','iat'=>$now,'exp'=>$now+90);
        if(!in_array($payload['scope'],array('play','control','board'),true)||($payload['scope']!=='play'&&(!Room_Policy::teacher($actor)||!preg_match('/^[A-Z2-9]{6}$/D',$payload['room']))))throw new Failure('화면 입장권 권한을 확인하세요.',403);
        $body=rtrim(strtr(base64_encode(json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'+/','-_'),'=');
        return $body.'.'.hash_hmac('sha256',$body,$secret);
    }
    public static function read(string $ticket,string $secret,int $now,string $game=Room_Rules::BINARY): array {
        if(strlen($secret)<32||strlen($ticket)>2048||!preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D',$ticket,$m)
            ||!hash_equals(hash_hmac('sha256',$m[1],$secret),$m[2]))throw new Failure('입장권을 다시 발급받아 주세요.',401);
        $raw=base64_decode(strtr($m[1],'-_','+/'),true);$p=$raw===false?null:json_decode($raw,true);
        if(!is_array($p)||($p['purpose']??'')!==self::purpose($game)||!is_int($p['id']??null)||$p['id']<1
            ||!is_int($p['exp']??null)||!is_int($p['iat']??null)||$p['exp']<=$now||$p['iat']>$now+5||$p['exp']-$p['iat']!==90
            ||!is_string($p['name']??null)||!is_bool($p['manage']??null)||!is_bool($p['admin']??null))throw new Failure('입장권이 만료되었거나 올바르지 않습니다.',401);
        $p['scope']??='play';$p['room']??='';
        if(!in_array($p['scope'],array('play','control','board'),true)||($p['scope']!=='play'&&(!Room_Policy::teacher($p)||!is_string($p['room'])||!preg_match('/^[A-Z2-9]{6}$/D',$p['room']))))throw new Failure('화면 입장권이 올바르지 않습니다.',401);
        return array_intersect_key($p,array_flip(array('id','name','manage','admin','scope','room')));
    }
    private static function purpose(string $game): string {return match($game){Room_Rules::BINARY=>'edu-games-binary-team',Room_Rules::DEVICE=>'edu-games-device-team',default=>throw new Failure('팀전 종류를 확인하세요.',422)};}
}
