<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;

/** Shared room membership and start rules, independent of a game board. */
final class Room_Lobby {
    public static function create(string $code,array $actor,array $config,int $now,string $seed): array {
        $mode=$config['mode']??(Room_Policy::teacher($actor)?'official':'practice');
        if(!in_array($mode,array('official','practice'),true))throw new Failure('경기 종류를 확인하세요.',422);
        if($mode==='official'&&!Room_Policy::teacher($actor))throw new Failure('학생은 연습방만 만들 수 있습니다.',403);
        $assignment=$config['assignment']??'auto';$pace=$config['botPace']??'slow';
        if(!in_array($assignment,array('auto','manual'),true)||!in_array($pace,array('slow','normal','fast'),true))throw new Failure('팀 배정 또는 봇 속도를 확인하세요.',422);
        $teams=Binary_Room::integer($config['teams']??2,2,6);$capacity=Binary_Room::integer($config['capacity']??5,2,6);
        $duration=Binary_Room::integer($config['duration']??180,120,300);
        if(!in_array($duration,array(120,180,300),true))throw new Failure('경기 시간은 2·3·5분 중 선택하세요.',422);
        $difficulty=$config['difficulty']??'standard';
        if(!in_array($difficulty,array('standard','challenge'),true))throw new Failure('팀 난도를 확인하세요.',422);
        if(isset($config['title'])&&!is_string($config['title']))throw new Failure('방 이름을 확인하세요.',422);
        $title=trim($config['title']??'실시간 팀전');
        if($title===''||strlen($title)>180||preg_match('/[<>\x00-\x1f]/u',$title))throw new Failure('방 이름을 확인하세요.',422);
        $s=array('code'=>$code,'title'=>$title,'host'=>(int)$actor['id'],'createdAt'=>$now,'updatedAt'=>$now,'status'=>'lobby','revision'=>1,'round'=>1,'seed'=>$seed,
            'creationKey'=>(string)($config['op']??''),'config'=>array('teams'=>$teams,'capacity'=>$capacity,'duration'=>$duration,'difficulty'=>$difficulty,'mode'=>$mode,'assignment'=>$assignment,'botPace'=>$pace),
            'players'=>array(),'boards'=>array(),'operations'=>array());
        return !empty($config['participate'])?self::command($s,$actor,array('type'=>'join'),$now):$s;
    }
    public static function command(array $s,array $actor,array $c,int $now): ?array {
        Room_Policy::access($s,$actor,$c);
        if(!is_string($c['type']??null))throw new Failure('조작 형식을 확인하세요.',422);
        $uid=(int)$actor['id'];$key=(string)$uid;$type=$c['type'];$host=Room_Policy::controls($s,$actor);
        if($uid<1&&!(isset($s['players'][$key])&&!empty($s['players'][$key]['bot'])&&!empty($actor['bot'])))throw new Failure('로그인이 필요합니다.',403);
        if(isset($c['round'])&&(int)$c['round']!==$s['round'])throw new Failure('새 경기 상태를 불러온 뒤 다시 시도하세요.');
        if($type==='join'){
            if(isset($s['players'][$key]))return $s;
            self::phase($s,'lobby');$counts=array_fill(1,$s['config']['teams'],0);foreach($s['players'] as $p)if($p['team']>0)$counts[$p['team']]++;
            if(count($s['players'])>=$s['config']['capacity']*$s['config']['teams'])throw new Failure('방이 가득 찼습니다.');
            $team=array_search(min($counts),$counts,true);if($counts[$team]>=$s['config']['capacity'])throw new Failure('방이 가득 찼습니다.');
            if(($s['config']['assignment']??'auto')==='manual')$team=0;
            $s['players'][$key]=array('id'=>$uid,'name'=>(string)$actor['name'],'team'=>$team,'ready'=>false,'seen'=>$now,'correct'=>0,'wrong'=>0,'edits'=>0,'bot'=>false);
            return self::unready($s);
        }
        if(!isset($s['players'][$key])&&!$host)throw new Failure('먼저 방에 참가하세요.',403);
        if($type==='ready'){
            self::phase($s,'lobby');if(!isset($s['players'][$key]))throw new Failure('팀에 참가한 뒤 준비하세요.');
            if($s['players'][$key]['team']<1)throw new Failure('팀 배정을 기다려 주세요.');
            $s['players'][$key]['ready']=!empty($c['ready']);return $s;
        }
        if($type==='practice-start'){
            self::phase($s,'lobby');
            foreach($s['players'] as $p)if(empty($p['bot'])&&$p['id']!==$uid)throw new Failure('다른 참가자가 있습니다. 팀 편성 후 모두 준비하고 시작하세요.');
            if(!isset($s['players'][$key])){
                if(count($s['players'])>=$s['config']['teams']*$s['config']['capacity'])foreach($s['players'] as $id=>$p)if(!empty($p['bot'])){unset($s['players'][$id]);break;}
                $s=self::command($s,$actor,array('type'=>'join'),$now);
            }
            if($s['players'][$key]['team']===0)$s['players'][$key]['team']=1;
            $s=self::command($s,$actor,array('type'=>'bot-fill'),$now);
            $s=self::command($s,$actor,array('type'=>'ready','ready'=>true),$now);
            return self::command($s,$actor,array('type'=>'start'),$now);
        }
        if(in_array($type,array('team','balance','kick'),true)){
            self::phase($s,'lobby');
            if($type==='team'){
                $target=(int)($c['user']??$uid);if($target!==$uid&&!$host)throw new Failure('팀을 편성할 권한이 없습니다.',403);
                if(!isset($s['players'][(string)$target]))throw new Failure('참가자를 찾지 못했습니다.');
                $team=Binary_Room::integer($c['team']??0,0,$s['config']['teams']);$count=0;
                foreach($s['players'] as $p)if($p['id']!==$target&&$p['team']===$team)$count++;
                if($team>0&&$count>=$s['config']['capacity'])throw new Failure('그 팀은 가득 찼습니다.');$s['players'][(string)$target]['team']=$team;
            }else{
                if(!$host)throw new Failure('방장만 사용할 수 있습니다.',403);
                if($type==='kick'){unset($s['players'][(string)($c['user']??0)],$s['botState'][(string)($c['user']??0)]);}
                else{
                    $keys=array_keys($s['players']);for($i=count($keys)-1;$i>0;$i--){$j=random_int(0,$i);[$keys[$i],$keys[$j]]=[$keys[$j],$keys[$i]];}
                    foreach($keys as $i=>$id)$s['players'][$id]['team']=1+$i%$s['config']['teams'];
                }
            }
            return self::unready($s);
        }
        if(in_array($type,array('bot-add','bot-fill','bot-clear','bot-level'),true)){
            self::phase($s,'lobby');
            if($type==='bot-level'){
                if(!in_array($c['pace']??'',array('slow','normal','fast'),true))throw new Failure('봇 난이도를 확인하세요.',422);
                $s['config']['botPace']=$c['pace'];$s['botState']=array();
            }
            elseif($type==='bot-clear'){foreach($s['players'] as $id=>$p)if(!empty($p['bot']))unset($s['players'][$id]);$s['botState']=array();}
            elseif($type==='bot-add')$s=self::add_bot($s,Binary_Room::integer($c['team']??0,1,$s['config']['teams']),$now);
            else{
                $counts=array_fill(1,$s['config']['teams'],0);foreach($s['players'] as $p){if($p['team']<1)throw new Failure('미배정 참가자의 팀을 먼저 정하세요.');$counts[$p['team']]++;}
                $target=max(2,max($counts));foreach($counts as $team=>$count)for($i=$count;$i<$target;$i++)$s=self::add_bot($s,$team,$now);
            }
            return self::unready($s);
        }
        if($type==='leave'){
            if(in_array($s['status'],array('playing','paused'),true))throw new Failure('경기 중에는 팀을 바꿀 수 없습니다. 다시 연결하면 이어집니다.');
            unset($s['players'][$key]);return self::unready($s);
        }
        if($type==='start'){
            if(!$host)throw new Failure('방장만 시작할 수 있습니다.',403);
            self::phase($s,'lobby');$counts=array_fill(1,$s['config']['teams'],0);
            foreach($s['players'] as $p){if($p['team']<1)throw new Failure('미배정 참가자의 팀을 먼저 정하세요.');if(!$p['ready']||(empty($p['bot'])&&$p['seen']<$now-15000))throw new Failure('접속한 모든 참가자가 준비해야 합니다.');$counts[$p['team']]++;}
            if(min($counts)<2||count(array_unique($counts))!==1)throw new Failure('각 팀 2명 이상, 같은 인원으로 편성하세요.');
            $s['status']='playing';$s['startedAt']=$now+3000;$s['wallStartedAt']=$now+3000;$s['endsAt']=$s['startedAt']+$s['config']['duration']*1000;$s['pausedMs']=0;$s['botState']=array();
            return $s;
        }
        if($type==='rematch'){
            if(!$host)throw new Failure('방장만 사용할 수 있습니다.',403);self::phase($s,'finished');
            $s['round']++;$s['status']='lobby';$s['boards']=array();$s['seed']=hash('sha256',$s['seed'].':'.$s['round']);
            unset($s['summary'],$s['startedAt'],$s['endsAt'],$s['finishedAt'],$s['pausedAt']);
            foreach($s['players'] as &$p){$p['correct']=0;$p['wrong']=0;$p['edits']=0;unset($p['lastAnswerAt']);}unset($p);
            return self::unready($s);
        }
        return null;
    }
    public static function summary(array $s,int $at,string $reason,string $ruleset): array {
        $teams=array();
        foreach($s['boards'] as $b){$teams[]=array('team'=>$b['team'],'size'=>$b['size'],'score'=>$b['score'],'correct'=>$b['correct'],'wrong'=>$b['wrong'],
            'survivedMs'=>max(0,($b['endedAt']??$at)-$s['startedAt']),'reason'=>$b['endedAt']!==null?'overflow':$reason,'stage'=>$b['stage']??0);}
        usort($teams,static fn($a,$b)=>($b['score']<=>$a['score'])?:($b['correct']<=>$a['correct'])?:($a['wrong']<=>$b['wrong'])?:($b['survivedMs']<=>$a['survivedMs']));
        $last=null;$rank=0;foreach($teams as $i=>&$t){$key=implode(':',array($t['score'],$t['correct'],$t['wrong'],$t['survivedMs']));if($key!==$last)$rank=$i+1;$t['rank']=$rank;$last=$key;}unset($t);
        $players=array_map(static fn($p)=>array_intersect_key($p,array_flip(array('id','name','team','correct','wrong','edits','bot'))),array_values($s['players']));
        return array('id'=>$s['code'].'-'.$s['round'],'code'=>$s['code'],'round'=>$s['round'],'title'=>$s['title'],'host'=>$s['host'],'ruleset'=>$ruleset,'game'=>Room_Rules::game($s),
            'config'=>$s['config'],'mode'=>Room_Policy::mode($s),'hasBots'=>(bool)array_filter($s['players'],static fn($p)=>!empty($p['bot'])),'startedAt'=>$s['wallStartedAt'],'finishedAt'=>$at,'durationMs'=>max(0,$at-$s['startedAt']),
            'reason'=>$reason,'teams'=>$teams,'players'=>$players);
    }

    public static function view(array $s,array $actor,int $now,int $watch=0): array {
        $uid=(int)$actor['id'];$host=$s['host']===$uid||!empty($actor['admin']);$player=$s['players'][(string)$uid]??null;
        if(!$host&&!$player)throw new Failure('이 방의 참가자가 아닙니다.',403);
        $canWatch=(!empty($actor['admin'])||($host&&!empty($actor['manage'])))
            &&(Room_Policy::mode($s)==='practice'||in_array($actor['scope']??'play',array('control','board'),true));
        $team=$canWatch&&$watch>0?$watch:($player['team']??($canWatch?1:0));$board=$s['boards'][$team]??null;
        $teams=array();for($t=1;$t<=$s['config']['teams'];$t++){$b=$s['boards'][$t]??null;$teams[]=array('team'=>$t,'score'=>$b['score']??0,'correct'=>$b['correct']??0,'occupied'=>$b?(isset($b['rows'])?count($b['rows']):($b['pairs']-$b['stageCorrect'])):0,'ended'=>$b&&$b['endedAt']!==null,'stage'=>$b['stage']??0,'pairs'=>$b['pairs']??0);}
        $players=array_values($s['players']);foreach($players as &$p){$p['online']=!empty($p['bot'])||$p['seen']>$now-15000;unset($p['seen'],$p['lastAnswerAt']);}unset($p);
        return array_intersect_key($s,array_flip(array('code','title','status','revision','round','config','startedAt','endsAt','pausedAt','summary')))
            +array('host'=>Room_Policy::controls($s,$actor)&&(Room_Policy::mode($s)==='practice'||($actor['scope']??'play')==='control'),'canWatch'=>$canWatch,'canOverview'=>Room_Policy::overview($s,$actor),'mode'=>Room_Policy::mode($s),'me'=>$uid,'myTeam'=>$player['team']??0,'watchTeam'=>$team,'players'=>$players,'teams'=>$teams,'board'=>$board,'serverNow'=>$now);
    }
    public static function phase(array $s,string $phase): void {if($s['status']!==$phase)throw new Failure('현재 경기 상태에서 사용할 수 없습니다.');}
    private static function unready(array $s): array {foreach($s['players'] as &$p)$p['ready']=!empty($p['bot']);unset($p);return $s;}
    private static function add_bot(array $s,int $team,int $now): array {
        $count=0;foreach($s['players'] as $p)if($p['team']===$team)$count++;
        if($count>=$s['config']['capacity'])throw new Failure('그 팀은 가득 찼습니다.');
        $serial=($s['botSerial']??0)+1;$s['botSerial']=$serial;$id=-$serial;
        $s['players'][(string)$id]=array('id'=>$id,'name'=>'연습봇 '.$serial,'team'=>$team,'ready'=>true,'seen'=>$now,'correct'=>0,'wrong'=>0,'edits'=>0,'bot'=>true);
        return $s;
    }
}
