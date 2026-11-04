<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;

/** The hub transports commands; PHP remains the only game/score authority. */
final class Socket_Relay {
    public static function signature(string $method,string $route,string $time,string $body,string $secret): string {
        return hash_hmac('sha256',strtoupper($method)."\n".$route."\n".$time."\n".$body,$secret);
    }
    public static function verify(string $raw,string $time,string $signature,string $secret,int $now): void {
        if(strlen($secret)<32||!preg_match('/^\d{9,12}$/D',$time)||abs($now-(int)$time)>30||!preg_match('/^[a-f0-9]{64}$/D',$signature)
            ||!hash_equals(self::signature('POST','/binary-team/sync',$time,$raw,$secret),$signature))throw new Failure('서버 인증에 실패했습니다.',401);
    }
    public static function handle(Repository $store,array $input,string $secret,int $now): array {
        $game=self::game($input['channel']??'');
        if(($input['app']??'')!=='edu-games'||!is_string($input['code']??null)
            ||!preg_match('/^[A-Z2-9]{6}$/D',$input['code'])||!is_array($input['sessions']??null)||count($input['sessions'])<1||count($input['sessions'])>80)throw new Failure('채널 요청을 확인하세요.',422);
        $sessions=array();$errors=array();
        foreach($input['sessions'] as $id=>$session){
            if(!is_string($id)||!preg_match('/^[a-f0-9]{24}$/D',$id)||!is_array($session))throw new Failure('연결 번호를 확인하세요.',422);
            try{
                if(!is_string($session['ticket']??null))throw new Failure('입장권을 확인하세요.',401);
                $sessions[$id]=array('actor'=>Ticket::read($session['ticket'],$secret,(int)floor($now/1000),$game),
                    'watch'=>Binary_Room::integer($session['watch']??0,0,6));
            }catch(Failure $e){$errors[$id]=array('status'=>$e->status,'message'=>$e->getMessage());}
        }
        $service=new Service($store,$game);$reply=null;$action=$input['action']??null;
        if($action!==null){
            if(!is_array($action)||!is_string($action['client']??null)||!is_array($action['command']??null))throw new Failure('조작 형식을 확인하세요.',422);
            $sender=$sessions[$action['client']]??null;
            try{
                if(!$sender)throw new Failure('입장권을 갱신하세요.',401);
                $reply=array('ok'=>true,'data'=>$service->access($input['code'],$sender['actor'],$action['command'],$now,$sender['watch']));
            }catch(Failure $e){$reply=array('ok'=>false,'status'=>$e->status,'message'=>$e->getMessage());}
        }
        // One authoritative tick per room, not one poll per student.
        foreach($sessions as $id=>$session){
            try{$service->access($input['code'],$session['actor'],null,$now,$session['watch']);break;}
            catch(Failure $e){$errors[$id]=array('status'=>$e->status,'message'=>$e->getMessage());unset($sessions[$id]);}
        }
        return array('reply'=>$reply,'views'=>$sessions?$service->snapshots($input['code'],$sessions,$now):array(),'errors'=>$errors);
    }
    public static function game(mixed $channel): string {return match($channel){'binary-team'=>Room_Rules::BINARY,'device-team'=>Room_Rules::DEVICE,default=>throw new Failure('채널 요청을 확인하세요.',422)};}
}
