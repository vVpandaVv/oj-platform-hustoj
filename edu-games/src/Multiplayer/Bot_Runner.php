<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;

/** Bots use the same commands, leases and scoring as people; memory sees open cards only. */
final class Bot_Runner {
    private const PROFILES=array(
        'slow'=>array('binaryMs'=>1500,'cardMs'=>2200,'readMs'=>3200,'thinkMs'=>2400,'mistake'=>28,'idle'=>20,'memory'=>4,'forgetMs'=>10000,'recall'=>55),
        'normal'=>array('binaryMs'=>1150,'cardMs'=>1750,'readMs'=>2800,'thinkMs'=>1800,'mistake'=>18,'idle'=>14,'memory'=>8,'forgetMs'=>18000,'recall'=>75),
        'fast'=>array('binaryMs'=>850,'cardMs'=>1350,'readMs'=>2200,'thinkMs'=>1300,'mistake'=>10,'idle'=>8,'memory'=>12,'forgetMs'=>25000,'recall'=>90));
    public static function advance(array $s,int $now): array {
        $engine=Room_Rules::engine(Room_Rules::game($s));
        $bots=array_filter($s['players'],static fn($p)=>!empty($p['bot']));
        if(!$bots||Room_Policy::mode($s)!=='practice'||$s['status']!=='playing'||$now<$s['startedAt'])return $engine::tick($s,$now);
        $profile=self::PROFILES[$s['config']['botPace']??'slow'];$device=Room_Rules::game($s)===Room_Rules::DEVICE;
        $delay=$profile[$device?'cardMs':'binaryMs'];
        $floor=max($s['startedAt'],min($now,$s['endsAt'])-2500);
        foreach($bots as $id=>$p){$s['botState'][$id]??=array('next'=>$s['startedAt']+$profile['readMs']+self::roll($s['seed'].':'.$id,700),'memory'=>array(),'stage'=>0,'step'=>0);$s['botState'][$id]['next']=max($floor,$s['botState'][$id]['next']);}
        for($n=0;$n<96&&$s['status']==='playing';$n++){
            $id=null;$at=PHP_INT_MAX;foreach($bots as $key=>$p)if($s['botState'][$key]['next']<$at){$id=$key;$at=$s['botState'][$key]['next'];}
            if($id===null||$at>$now||$at>=$s['endsAt'])break;
            $s=$engine::tick($s,$at);if($s['status']!=='playing')break;
            $p=$bots[$id];$b=$s['boards'][$p['team']];$state=$s['botState'][$id];$state['step']++;
            $salt=$s['seed'].':'.$id.':'.$state['step'];
            $command=self::roll($salt.':idle',100)<$profile['idle']?null:($device?self::card($b,(int)$id,$state,$profile,$s['seed'],$at):self::binary($b,(int)$id,$at,$state,$profile,$s['seed']));
            $state['next']=$at+$delay+self::roll($salt.':jitter',(int)($delay*.45));$s['botState'][$id]=$state;
            if($command)try{$s=$engine::command($s,array('id'=>(int)$id,'name'=>$p['name'],'bot'=>true),$command,$at);}catch(Failure $e){}
        }
        return $engine::tick($s,$now);
    }
    private static function roll(string $key,int $range): int {return hexdec(substr(hash('sha256',$key),0,7))%max(1,$range);}
    private static function binary(array $b,int $id,int $now,array &$state,array $profile,string $seed): ?array {
        if($b['endedAt']!==null)return null;
        $rows=$b['rows'];usort($rows,static fn($a,$b)=>($b['owner']===$id)<=>($a['owner']===$id));
        foreach($rows as $r){
            if(($r['owner']!==0&&$r['owner']!==$id&&$r['leaseUntil']>$now)||$r['bornAt']>$now-$profile['readMs'])continue;
            if($r['owner']!==$id){$state['workRow']=$r['id'];$state['thoughtAt']=$now+$profile['thinkMs'];$state['tried']=false;return array('type'=>'claim','row'=>$r['id']);}
            if(($state['workRow']??0)!==$r['id']){$state['workRow']=$r['id'];$state['thoughtAt']=$now+$profile['thinkMs'];$state['tried']=false;}
            if($now<($state['thoughtAt']??0))return null;
            $mistake=empty($state['tried'])&&self::roll($seed.':'.$id.':'.$r['id'].':mistake',100)<$profile['mistake'];$state['tried']=true;
            if($r['mode']==='bin-to-dec')return array('type'=>'answer','row'=>$r['id'],'value'=>(string)($mistake?($r['decimal']+16)%256:$r['decimal']));
            $bits=str_pad(decbin($r['decimal']),8,'0',STR_PAD_LEFT);
            if($mistake)foreach($r['bits'] as $i=>$v)if($v===(int)$bits[$i])return array('type'=>'bit','row'=>$r['id'],'bit'=>$i,'value'=>1-$v);
            foreach($r['bits'] as $i=>$v)if($v!==(int)$bits[$i])return array('type'=>'bit','row'=>$r['id'],'bit'=>$i,'value'=>(int)$bits[$i]);
        }return null;
    }
    private static function card(array $b,int $id,array &$state,array $profile,string $seed,int $now): ?array {
        if($state['stage']!==$b['stage']){$state['memory']=array();$state['stage']=$b['stage'];}
        foreach($state['memory'] as $key=>$m)if(($m['seen']??0)<$now-$profile['forgetMs'])unset($state['memory'][$key]);
        foreach($b['cards'] as $c){
            if($c['state']==='matched')unset($state['memory'][$c['id']]);
            elseif(in_array($c['state'],array('open','miss'),true)&&self::roll($seed.':'.$id.':'.$c['id'].':'.$state['step'].':notice',100)<$profile['recall']){
                unset($state['memory'][$c['id']]);$state['memory'][$c['id']]=array('device'=>$c['device'],'kind'=>$c['kind'],'seen'=>$now);
            }
        }
        $state['memory']=array_slice($state['memory'],-$profile['memory'],null,true);
        if($b['clearAt'])return null;
        $turn=$b['turns'][(string)$id]??null;if($turn&&count($turn['cards'])===2)return null;
        $down=array_values(array_filter($b['cards'],static fn($c)=>$c['state']==='down'));if(!$down)return null;
        $choice=null;$memory=$state['memory'];
        $recall=self::roll($seed.':'.$id.':'.$state['step'].':recall',100)<$profile['recall'];
        if($turn&&$recall){$first=$b['cards'][$turn['cards'][0]];$known=$memory[$first['id']]??null;
            foreach($down as $c){$m=$memory[$c['id']]??null;if($m&&$known&&$m['device']===$known['device']&&$m['kind']!==$known['kind']){$choice=$c;break;}}
        }elseif(!$turn&&$recall){
            foreach($down as $a){$m=$memory[$a['id']]??null;if(!$m)continue;foreach($down as $c){$other=$memory[$c['id']]??null;if($other&&$other['device']===$m['device']&&$other['kind']!==$m['kind']){$choice=$a;break 2;}}}
        }
        if(!$choice){$unseen=array_values(array_filter($down,static fn($c)=>!isset($memory[$c['id']])));$pool=$unseen?:$down;$choice=$pool[hexdec(substr(hash('sha256',$seed.':'.$id.':'.$state['step']),0,7))%count($pool)];}
        return array('type'=>'flip','stage'=>$b['stage'],'card'=>$choice['id']);
    }
}
