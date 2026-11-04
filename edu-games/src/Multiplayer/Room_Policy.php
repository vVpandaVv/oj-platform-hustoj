<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;

final class Room_Policy {
    public static function teacher(array $actor): bool {return !empty($actor['manage'])||!empty($actor['admin']);}
    public static function mode(array $s): string {return $s['mode']??$s['config']['mode']??'practice';}
    public static function controls(array $s,array $actor): bool {
        return ($actor['scope']??'play')!=='board'&&($s['host']===(int)$actor['id']||!empty($actor['admin']))
            &&(self::mode($s)==='practice'||self::teacher($actor));
    }
    public static function overview(array $s,array $actor): bool {
        return self::teacher($actor)&&($s['host']===(int)$actor['id']||!empty($actor['admin']));
    }
    public static function access(array $s,array $actor,?array $command): void {
        $scope=$actor['scope']??'play';
        if(!in_array($scope,array('play','control','board'),true))throw new Failure('화면 권한을 확인하세요.',403);
        if($scope!=='play'&&(!self::overview($s,$actor)||($actor['room']??'')!==$s['code']))throw new Failure('이 경기의 교사 화면에 접근할 수 없습니다.',403);
        if($scope==='board'&&$command!==null)throw new Failure('현황판에서는 경기를 조작할 수 없습니다.',403);
        $type=$command['type']??'';
        if(self::mode($s)==='official'&&in_array($type,array('team','balance','kick','start','pause','resume','stop','rematch','close'),true)&&!self::controls($s,$actor))throw new Failure('정식 경기 운영과 팀 배정은 담당 교사만 할 수 있습니다.',403);
        if(in_array($type,array('bot-add','bot-fill','bot-clear','bot-level','practice-start'),true)&&(!self::controls($s,$actor)||self::mode($s)!=='practice'))throw new Failure('연습방의 방장만 봇 연습을 설정할 수 있습니다.',403);
    }
}
