<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;
use EduGames\Arcade\Vault_Rules;
final class Vault_Room {
    public const RULESET='vault-team-v1', CLAIM_MS=30000;
    public const COSTS=['shuffle'=>3,'erase'=>4,'key'=>5,'shield'=>2,'wrench'=>2];
    public static function create(string $code,array $actor,array $config,int $now,string $seed): array {return Room_Lobby::create($code,$actor,$config+['title'=>'암호 금고 팀전'],$now,$seed)+['game'=>'cipher-vault'];}
    public static function command(array $s,array $actor,array $c,int $now): array {
        $type=$c['type']??'';$handled=Room_Lobby::command($s,$actor,$c,$now);
        if($handled!==null){$s=$handled;if(in_array($type,['start','practice-start'],true)){
            $counts=array_fill(1,$s['config']['teams'],0);foreach($s['players'] as $p)$counts[$p['team']]++;
            foreach($counts as $team=>$size){$vaults=Vault_Rules::vaults($s['seed'],'challenge',max(24,$size*8));if($s['config']['difficulty']==='challenge')foreach($vaults as &$v){$v['text'].=' '.($v['id']%10);$v['order']=range(0,strlen($v['text'])-1);}unset($v);
                $s['boards'][$team]=['team'=>$team,'size'=>$size,'score'=>0,'correct'=>0,'wrong'=>0,'combo'=>0,'stage'=>1,'stageCorrect'=>0,'pairs'=>count($vaults),'endedAt'=>null,'vaults'=>$vaults,'coins'=>0,'shield'=>false,'items'=>['shuffle'=>1,'erase'=>1,'key'=>0,'shield'=>0,'wrench'=>1],'event'=>null,'eventId'=>0];}
        }return $s;}
        if(in_array($type,['pause','resume','stop'],true)){
            if(!Room_Policy::controls($s,$actor))throw new Failure('방장만 사용할 수 있습니다.',403);
            if($type==='pause'){Room_Lobby::phase($s,'playing');$s['status']='paused';$s['pausedAt']=$now;return $s;}
            if($type==='resume'){Room_Lobby::phase($s,'paused');$s=self::shift($s,$now-$s['pausedAt']);$s['status']='playing';unset($s['pausedAt']);return $s;}
            if(!in_array($s['status'],['playing','paused'],true))throw new Failure('진행 중인 경기가 없습니다.');if($s['status']==='paused')$s=self::shift($s,$now-$s['pausedAt']);return self::finish($s,$now,'host');
        }
        Room_Lobby::phase($s,'playing');if($now<$s['startedAt'])throw new Failure('카운트다운이 끝나면 시작합니다.');$uid=(int)$actor['id'];$key=(string)$uid;
        if(!isset($s['players'][$key]))throw new Failure('관전 중에는 조작할 수 없습니다.',403);$team=$s['players'][$key]['team'];$b=&$s['boards'][$team];
        if($type==='close'){foreach($b['vaults'] as &$v)if($v['owner']===$uid){$v['owner']=0;$v['leaseUntil']=0;}unset($v);return $s;}
        if($type==='buy'){$item=$c['item']??'';$cost=self::COSTS[$item]??0;if(!$cost||$b['coins']<$cost)throw new Failure('아이템과 동전을 확인하세요.');$b['coins']-=$cost;$b['items'][$item]++;return $s;}
        if($type==='item')return self::item($s,$uid,$team,$c,$now);
        $id=$c['vault']??null;if(!is_int($id)||!isset($b['vaults'][$id]))throw new Failure('금고를 확인하세요.',422);
        if($type==='open'){
            $v=$b['vaults'][$id];if($v['opened'])throw new Failure('이미 열린 금고입니다.');if($v['owner']&&$v['owner']!==$uid&&$v['leaseUntil']>$now)throw new Failure('다른 팀원이 작업하고 있습니다.');
            foreach($b['vaults'] as &$other)if($other['owner']===$uid){$other['owner']=0;$other['leaseUntil']=0;}unset($other);$b['vaults'][$id]['owner']=$uid;$b['vaults'][$id]['leaseUntil']=$now+self::CLAIM_MS;$b['vaults'][$id]['startedAt']=$b['vaults'][$id]['startedAt']?:$now;return $s;
        }
        $v=&$b['vaults'][$id];if($v['opened']||$v['owner']!==$uid||$v['leaseUntil']<=$now)throw new Failure('금고를 다시 선택하세요.');
        $v['leaseUntil']=$now+self::CLAIM_MS;if($type==='touch')return $s;
        if(($v['cooldownUntil']??0)>$now)throw new Failure('오답 잠금이 끝난 뒤 다시 입력하세요.');$s['players'][$key]['edits']++;
        if($type==='swap'){
            if($v['effect']!=='shuffle')throw new Failure('순서 교란 중인 금고가 아닙니다.');$first=$c['first']??null;$second=$c['second']??null;
            if(!is_int($first)||!is_int($second)||!isset($v['order'][$first],$v['order'][$second]))throw new Failure('교환할 두 패킷을 선택하세요.',422);
            [$v['order'][$first],$v['order'][$second]]=[$v['order'][$second],$v['order'][$first]];if($v['order']===range(0,strlen($v['text'])-1)){$v['effect']=null;$v['repaired']=true;}return $s;
        }
        if($type==='repair'){
            if(($v['mode']==='rebuild'&&!$v['repaired'])||$v['effect']==='erase'){
                $code=$c['code']??null;if(is_string($code)&&str_pad(trim($code),2,'0',STR_PAD_LEFT)===Vault_Rules::codes($v)[$v['repairIndex']]){$v['repaired']=true;$v['effect']=null;return $s;}
                return self::wrong($s,$team,$uid,$id,$now);
            }throw new Failure('복구할 코드가 없습니다.');
        }
        if($type!=='answer')throw new Failure('지원하지 않는 조작입니다.',422);
        if(!$v['repaired']||$v['effect'])throw new Failure('코드 또는 순서 복구를 먼저 완료하세요.');
        if(!Vault_Rules::valid_answer($v,$c['answer']??null))return self::wrong($s,$team,$uid,$id,$now);
        $s['players'][$key]['correct']++;return self::unlock($s,$team,$id,$now,false);
    }
    private static function wrong(array $s,int $team,int $uid,int $id,int $now): array {
        $b=&$s['boards'][$team];$b['wrong']++;$b['combo']=0;$b['vaults'][$id]['cooldownUntil']=$now+2000;$s['players'][(string)$uid]['wrong']++;
        $b['event']=['id'=>++$b['eventId'],'type'=>'wrong','text'=>'잠금 유지 · 2초 뒤 재시도'];return $s;
    }
    private static function unlock(array $s,int $team,int $id,int $now,bool $key): array {
        $b=&$s['boards'][$team];$v=&$b['vaults'][$id];if($v['opened'])return $s;$v['opened']=true;$v['owner']=0;$v['leaseUntil']=0;$v['points']=$key?50:$v['tier']*100;$b['score']+=$v['points'];$coins=$key?1:$v['tier']+1;$b['coins']+=$coins;$b['stageCorrect']++;
        if(!$key){$b['correct']++;$b['combo']++;$drop=['shuffle','erase','shield','key','wrench'][$id%5];$b['items'][$drop]++;}
        $b['event']=['id'=>++$b['eventId'],'type'=>'open','text'=>($id+1).'번 열림 · +'.$v['points'].'점 · 동전 +'.$coins.($key?' · 열쇠 사용':' · 아이템 획득')];
        return $b['stageCorrect']===$b['pairs']?self::finish($s,$now,'cleared'):$s;
    }
    private static function item(array $s,int $uid,int $team,array $c,int $now): array {
        $item=$c['item']??'';$b=&$s['boards'][$team];if(!isset(self::COSTS[$item])||$b['items'][$item]<1)throw new Failure('사용할 아이템이 없습니다.');
        if($item==='shield'){if($b['shield'])throw new Failure('방어막이 이미 준비되어 있습니다.');$b['items'][$item]--;$b['shield']=true;return $s;}
        if($item==='key'){$id=null;foreach($b['vaults'] as $v)if(!$v['opened']&&!$v['owner']&&$v['tier']===1){$id=$v['id'];break;}if($id===null)throw new Failure('열쇠로 열 수 있는 미작업 기본 금고가 없습니다.');$b['items'][$item]--;return self::unlock($s,$team,$id,$now,true);}
        if($item==='wrench'){$id=null;foreach($b['vaults'] as $v)if(!$v['opened']&&$v['effect']){$id=$v['id'];break;}if($id===null)throw new Failure('공격받은 금고가 없습니다.');$b['items'][$item]--;$b['vaults'][$id]['effect']=null;$b['vaults'][$id]['repaired']=true;$b['vaults'][$id]['order']=range(0,strlen($b['vaults'][$id]['text'])-1);return $s;}
        $target=$c['targetTeam']??null;$id=$c['targetVault']??null;if(!is_int($target)||$target===$team||!isset($s['boards'][$target])||!is_int($id)||!isset($s['boards'][$target]['vaults'][$id]))throw new Failure('공격할 상대 금고를 선택하세요.',422);
        $other=&$s['boards'][$target];$v=&$other['vaults'][$id];if($v['opened']||$v['owner']||$v['effect']||$v['mode']!=='decode'||($v['attackProtectUntil']??0)>$now)throw new Failure('다른 미작업 복호화 금고를 선택하세요.');
        $b['items'][$item]--;if($other['shield']){$other['shield']=false;$b['event']=['id'=>++$b['eventId'],'type'=>'blocked','text'=>'상대 방어막이 공격을 차단했습니다.'];return $s;}
        $v['effect']=$item;$v['repaired']=false;$v['attackProtectUntil']=$now+10000;if($item==='shuffle')$v['order']=array_merge(array_slice($v['order'],1),[$v['order'][0]]);
        $b['event']=['id'=>++$b['eventId'],'type'=>'attack','text'=>$target.'팀 '.($id+1).'번 금고에 '.($item==='shuffle'?'순서 교란':'숫자 가림').' 적용'];return $s;
    }
    public static function tick(array $s,int $now): array {
        if($s['status']==='paused')return $now-$s['pausedAt']>600000?self::finish(self::shift($s,$now-$s['pausedAt']),$now,'pause-timeout'):$s;
        if($s['status']!=='playing'||$now<$s['startedAt'])return $s;if($now>=$s['endsAt'])return self::finish($s,$s['endsAt'],'time');
        foreach($s['boards'] as &$b)foreach($b['vaults'] as &$v)if($v['owner']&&$v['leaseUntil']<=$now){$v['owner']=0;$v['leaseUntil']=0;}unset($v,$b);return $s;
    }
    public static function view(array $s,array $actor,int $now,int $watch=0): array {
        $v=Room_Lobby::view($s,$actor,$now,$watch);$b=$v['board'];if(!$b)return $v;$uid=(int)$actor['id'];$active=null;$problem=null;
        foreach($b['vaults'] as $x)if($x['owner']===$uid&&!$x['opened']&&$x['leaseUntil']>$now){$active=$x['id'];$problem=Vault_Rules::problem($x);break;}
        $b['vaults']=array_map(static fn($x)=>array_intersect_key($x,array_flip(['id','tier','mode','opened','points','owner','effect'])),$b['vaults']);$b['active']=$active;$b['problem']=$problem;$v['board']=$b;$v['codebook']=Vault_Rules::codebook();$v['targets']=[];
        foreach($s['boards'] as $team=>$other)if($team!==$b['team'])foreach($other['vaults'] as $x)if(!$x['opened']&&!$x['owner']&&!$x['effect']&&$x['mode']==='decode'&&($x['attackProtectUntil']??0)<=$now)$v['targets'][]=['team'=>$team,'vault'=>$x['id']];
        return $v;
    }
    private static function shift(array $s,int $ms): array {$s['startedAt']+=$ms;$s['endsAt']+=$ms;$s['pausedMs']+=$ms;foreach($s['boards'] as &$b)foreach($b['vaults'] as &$v){foreach(['leaseUntil','cooldownUntil','attackProtectUntil','startedAt'] as $key)if(!empty($v[$key]))$v[$key]+=$ms;}unset($v,$b);return $s;}
    private static function finish(array $s,int $at,string $reason): array {$s['status']='finished';$s['finishedAt']=$at;foreach($s['boards'] as &$b)foreach($b['vaults'] as &$v){$v['owner']=0;$v['leaseUntil']=0;}unset($v,$b);$s['summary']=Room_Lobby::summary($s,$at,$reason,self::RULESET);return $s;}
}
