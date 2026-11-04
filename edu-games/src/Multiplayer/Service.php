<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;

final class Service {
    public function __construct(private Repository $store,private string $game=Room_Rules::BINARY) {Room_Rules::engine($game);}

    public function create(array $actor,array $config,int $now): array {
        self::operation($config);
        return $this->store->locked('create-'.$actor['id'],function()use($actor,$config,$now){
            $recent=$this->store->recent($actor['id']);$active=0;
            foreach($recent as $room)if($room['host']===$actor['id']){
                if($room['creationKey']===$config['op']&&Room_Rules::game($room)===$this->game)return Room_Rules::view($room,$actor,$now);
                if($room['createdAt']>$now-3600000&&$room['status']!=='finished')$active++;
            }
            $maximum=!empty($actor['manage'])||!empty($actor['admin'])?12:3;
            if($active>=$maximum)throw new Failure('진행·대기 중인 방은 한 시간에 최대 '.$maximum.'개까지 만들 수 있습니다.',429);
            $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            for($try=0;$try<8;$try++){
                $code='';for($i=0;$i<6;$i++)$code.=$alphabet[random_int(0,strlen($alphabet)-1)];
                if($this->store->get($code)!==null||$this->store->result($code.'-1')!==null)continue;
                $room=Room_Rules::create($code,$actor,$config,$now,bin2hex(random_bytes(16)),$this->game);
                $this->store->create($room);return Room_Rules::view($room,$actor,$now);
            }
            throw new Failure('방 코드를 만들지 못했습니다. 다시 시도하세요.',503);
        });
    }

    public function access(string $code,array $actor,?array $command,int $now,int $watch=0): array {
        $code=strtoupper($code);
        if(!preg_match('/^[A-Z2-9]{6}$/D',$code))throw new Failure('6자리 방 코드를 확인하세요.',422);
        if($command!==null)self::operation($command);
        if($command!==null&&($actor['scope']??'play')==='board')throw new Failure('현황판에서는 경기를 조작할 수 없습니다.',403);
        if($command!==null&&($command['type']??'')!=='join')Binary_Room::integer($command['round']??0,1,1000000);
        return $this->store->locked($code,function()use($code,$actor,$command,$now,$watch){
            $room=$this->store->get($code);
            if($room===null&&in_array($command['type']??'',array('close','leave'),true))return array('left'=>true,'closed'=>true);
            if($room===null||Room_Rules::game($room)!==$this->game)throw new Failure('방을 찾지 못했습니다.',404);
            Room_Policy::access($room,$actor,$command);
            $uid=(string)$actor['id'];$host=$room['host']===$actor['id']||!empty($actor['admin']);
            if(!isset($room['players'][$uid])&&!$host&&($command['type']??'')!=='join')throw new Failure('먼저 방 코드로 참가하세요.',403);
            $closing=($command['type']??'')==='close'||(($command['type']??'')==='leave'&&Room_Policy::controls($room,$actor));
            if($closing&&!Room_Policy::controls($room,$actor))throw new Failure('방장만 방을 닫을 수 있습니다.',403);
            if(!$closing&&$room['status']==='lobby'&&$room['updatedAt']<$now-86400000)throw new Failure('만료된 방입니다. 새 방을 만들어 주세요.',410);
            $before=$room;
            // A saved result wins if a previous request lost its final room write.
            $saved=$this->store->result($code.'-'.$room['round']);
            if($saved&&$room['status']!=='lobby'){$room['status']='finished';$room['summary']=$saved;$room['finishedAt']=$saved['finishedAt'];}
            $room=Room_Rules::tick($room,$now);$failure=null;
            if($closing){
                if((int)$command['round']!==$room['round'])throw new Failure('새 경기 상태를 불러온 뒤 다시 시도하세요.');
                if(in_array($room['status'],array('playing','paused'),true))$room=Room_Rules::command($room,$actor,array('type'=>'stop'),$now);
                if($room['status']==='finished')$this->store->save_result($room['summary']);
                $this->store->delete($code);return array('left'=>true,'closed'=>true);
            }
            if($room['status']==='finished')$this->store->save_result($room['summary']);
            if($command!==null&&!in_array($command['op'],$room['operations'][$uid]??array(),true)){
                try{
                    $next=Room_Rules::command($room,$actor,$command,$now);
                    $room=$next;$room['operations'][$uid][]= $command['op'];$room['operations'][$uid]=array_slice($room['operations'][$uid],-32);
                }catch(Failure $e){$failure=$e;}
            }
            if(($actor['scope']??'play')!=='board'&&isset($room['players'][$uid])&&$room['players'][$uid]['seen']<=$now-3000)$room['players'][$uid]['seen']=$now;
            if($room['status']==='finished')$this->store->save_result($room['summary']);
            if($room!==$before){$room=Room_Overview::events($before,$room,$now);$room['revision']++;$room['updatedAt']=$now;$this->store->put($room);}
            if($failure)throw $failure;
            if(($command['type']??'')==='leave'&&!isset($room['players'][$uid])&&!$host)return array('left'=>true);
            return Room_Rules::view($room,$actor,$now,$watch);
        });
    }
    public function snapshots(string $code,array $sessions,int $now): array {
        return $this->store->locked($code,function()use($code,$sessions,$now){
            $room=$this->store->get($code);if(!$room||Room_Rules::game($room)!==$this->game)throw new Failure('방을 찾지 못했습니다.',404);
            $before=$room;$views=array();
            foreach($sessions as $id=>$session){
                try{
                    Room_Rules::view($room,$session['actor'],$now,$session['watch']);
                    $uid=(string)$session['actor']['id'];
                    if(($session['actor']['scope']??'play')!=='board'&&isset($room['players'][$uid])&&$room['players'][$uid]['seen']<=$now-3000)$room['players'][$uid]['seen']=$now;
                }catch(Failure $e){}
            }
            if($before!==$room){$room['revision']++;$room['updatedAt']=$now;$this->store->put($room);}
            foreach($sessions as $id=>$session){
                try{$views[$id]=array('data'=>Room_Rules::view($room,$session['actor'],$now,$session['watch']));}
                catch(Failure $e){$views[$id]=array('error'=>array('status'=>$e->status,'message'=>$e->getMessage()));}
            }
            return $views;
        });
    }
    public function rooms(array $actor,int $now): array {
        $items=array();foreach($this->store->recent($actor['id'],!empty($actor['admin'])) as $r){
            if($r['updatedAt']<$now-86400000||Room_Rules::game($r)!==$this->game)continue;
            $items[]=array('code'=>$r['code'],'round'=>$r['round'],'title'=>$r['title'],'status'=>$r['status'],'mode'=>Room_Policy::mode($r),'players'=>count($r['players']),'config'=>$r['config'],'host'=>$r['host']===$actor['id']);
        }return array('items'=>$items);
    }
    public function records(array $actor,int $page,?int $now=null,string $mode='all'): array {
        if(!in_array($mode,array('all','official','practice'),true))throw new Failure('기록 종류를 확인하세요.',422);
        if($now!==null)foreach($this->store->recent($actor['id'],!empty($actor['admin'])) as $room){
            if(Room_Rules::game($room)===$this->game&&in_array($room['status'],array('playing','paused'),true))$this->access($room['code'],$actor,null,$now);
        }
        return $this->store->records($actor['id'],!empty($actor['admin']),max(1,$page),$this->game,$mode);
    }
    public function result(string $id,array $actor): array {
        if(!preg_match('/^[A-Z2-9]{6}-\d{1,6}$/D',$id))throw new Failure('기록 번호를 확인하세요.',422);
        $r=$this->store->result($id);if(!$r||Room_Rules::game($r)!==$this->game)throw new Failure('기록이 없습니다.',404);
        $uids=array_column($r['players'],'id');
        if(empty($actor['admin'])&&$r['host']!==$actor['id']&&!in_array($actor['id'],$uids,true))throw new Failure('내 경기 기록만 볼 수 있습니다.',403);
        return $r;
    }
    private static function operation(array $c): void {
        if(!is_string($c['op']??null)||!preg_match('/^[a-zA-Z0-9_-]{8,64}$/D',$c['op']))throw new Failure('요청 번호를 확인하세요.',422);
    }
}
