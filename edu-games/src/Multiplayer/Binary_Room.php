<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;

/** Server-owned binary game. No WordPress, transport, or personal-game state. */
final class Binary_Room {
    public const RULESET = 'binary-team-v1';
    public const EXPOSURE_MS = 7000;
    public const LEASE_MS = 9000;

    public static function create(string $code, array $actor, array $config, int $now, string $seed): array {
        return Room_Lobby::create($code,$actor,$config+array('title'=>'이진수 팀전'),$now,$seed);
    }

    public static function difficulty(int $players, int $level, string $kind = 'standard'): array {
        $base = array(2=>3000,3=>2000,4=>1550,5=>1250,6=>1100)[max(2,min(6,$players))];
        $factor = max(0.75, 1 - 0.05 * ($level - 1)) * ($kind === 'challenge' ? 0.88 : 1);
        return array('spawnMs'=>max(1000,(int)round($base*$factor)), 'inverseChance'=>min(0.75,0.5+0.04*($level-1)),
            'bits'=>8, 'capacity'=>8, 'minimumExposureMs'=>self::EXPOSURE_MS);
    }

    public static function command(array $s, array $actor, array $c, int $now): array {
        $handled=Room_Lobby::command($s,$actor,$c,$now);
        $type=$c['type'];$uid=(int)$actor['id'];$key=(string)$uid;$host=Room_Policy::controls($s,$actor);
        if($handled!==null){
            if(!in_array($type,array('start','practice-start'),true))return $handled;
            $s=$handled;$counts=array_fill(1,$s['config']['teams'],0);foreach($s['players'] as $p)$counts[$p['team']]++;
            foreach ($counts as $team=>$size) {
                $s['boards'][$team]=array('team'=>$team,'size'=>$size,'rows'=>array(),'sequence'=>0,'score'=>0,'correct'=>0,'wrong'=>0,'combo'=>0,'level'=>1,'endedAt'=>null,
                    'nextSpawnAt'=>$s['startedAt']+self::difficulty($size,1,$s['config']['difficulty'])['spawnMs']);
                for($i=0;$i<min(4,$size);$i++) $s['boards'][$team]=self::spawn($s,$s['boards'][$team],$s['startedAt']);
            }
            return $s;
        }
        if (in_array($type,array('pause','resume','stop'),true)) {
            if (!$host) throw new Failure('방장만 사용할 수 있습니다.',403);
            if ($type==='pause') { self::phase($s,'playing');$s['status']='paused';$s['pausedAt']=$now;return self::release_all($s); }
            if ($type==='resume') {
                self::phase($s,'paused'); $shift=$now-$s['pausedAt'];$s['startedAt']+=$shift;$s['endsAt']+=$shift;$s['pausedMs']+=$shift;
                foreach($s['boards'] as &$b){$b['nextSpawnAt']+=$shift;if($b['endedAt']!==null)$b['endedAt']+=$shift;foreach($b['rows'] as &$r)$r['bornAt']+=$shift;unset($r);}unset($b);
                $s['status']='playing';unset($s['pausedAt']);return $s;
            }
            if ($type==='stop') {
                if(!in_array($s['status'],array('playing','paused'),true))throw new Failure('진행 중인 경기가 없습니다.');
                if($s['status']==='paused'){$shift=$now-$s['pausedAt'];$s['startedAt']+=$shift;$s['pausedMs']+=$shift;foreach($s['boards'] as &$b)if($b['endedAt']!==null)$b['endedAt']+=$shift;unset($b);}
                return self::finish($s,$now,'host');
            }
        }
        if(!in_array($type,array('claim','release','bit','answer'),true))throw new Failure('지원하지 않는 조작입니다.',422);
        self::phase($s,'playing');
        if($now<$s['startedAt'])throw new Failure('카운트다운이 끝나면 시작합니다.');
        if(!isset($s['players'][$key]))throw new Failure('관전 중에는 조작할 수 없습니다.',403);
        $team=$s['players'][$key]['team'];$b=&$s['boards'][$team];
        if($b['endedAt']!==null)throw new Failure('우리 팀 경기가 종료되었습니다.');
        $index=null;foreach($b['rows'] as $i=>$row)if($row['id']===(int)($c['row']??0)){$index=$i;break;}
        if($index===null)throw new Failure('이미 해결했거나 없는 줄입니다.');
        $r=&$b['rows'][$index];
        if($r['owner'] && $r['owner']!==$uid && $r['leaseUntil']>$now)throw new Failure('다른 팀원이 풀고 있는 줄입니다.');
        if($type==='release') {if($r['owner']===$uid){$r['owner']=0;$r['leaseUntil']=0;}return $s;}
        foreach($b['rows'] as $i=>&$other)if($i!==$index && $other['owner']===$uid){$other['owner']=0;$other['leaseUntil']=0;}unset($other);
        $r['owner']=$uid;$r['leaseUntil']=$now+self::LEASE_MS;
        if($type==='claim')return $s;
        if($type==='bit') {
            if($r['mode']!=='dec-to-bin')throw new Failure('10진수 답을 입력하는 줄입니다.');
            $bit=self::integer($c['bit']??-1,0,7);$value=self::integer($c['value']??-1,0,1);
            if($r['bits'][$bit]!==$value){$r['bits'][$bit]=$value;$s['players'][$key]['edits']++;}
            $solved=bindec(implode('',$r['bits']))===$r['decimal'];
        } else {
            if($r['mode']!=='bin-to-dec')throw new Failure('비트를 조작하는 줄입니다.');
            if(!is_string($c['value']??null)&&!is_int($c['value']??null))throw new Failure('0~255의 10진수를 입력하세요.',422);
            $answer=(string)$c['value'];if(!preg_match('/^\d{1,3}$/D',$answer))throw new Failure('0~255의 10진수를 입력하세요.',422);
            if(($s['players'][$key]['lastAnswerAt']??0)>$now-600)throw new Failure('답을 확인하고 다시 제출하세요.',429);
            $s['players'][$key]['lastAnswerAt']=$now;$solved=(int)$answer===$r['decimal'];
            if(!$solved){$b['wrong']++;$b['combo']=0;$b['score']=max(0,$b['score']-10);$s['players'][$key]['wrong']++;}
        }
        if($solved){
            $speed=max(0,40-(int)floor(max(0,$now-$r['bornAt'])/400));
            $b['combo']++;$b['correct']++;$b['score']+=100+10*($r['level']-1)+min(50,5*intdiv($b['combo'],4))+$speed;
            $s['players'][$key]['correct']++;array_splice($b['rows'],$index,1);
        }
        return $s;
    }

    public static function tick(array $s, int $now): array {
        if($s['status']==='paused'){
            if($now-$s['pausedAt']<=600000)return $s;
            $shift=$now-$s['pausedAt'];$s['startedAt']+=$shift;$s['pausedMs']+=$shift;
            foreach($s['boards'] as &$b)if($b['endedAt']!==null)$b['endedAt']+=$shift;unset($b);
            return self::finish($s,$now,'pause-timeout');
        }
        if($s['status']!=='playing'||$now<$s['startedAt'])return $s;
        $limit=min($now,$s['endsAt']);
        foreach($s['boards'] as &$b){
            foreach($b['rows'] as &$r)if($r['leaseUntil']<=$now){$r['owner']=0;$r['leaseUntil']=0;}unset($r);
            if($b['endedAt']!==null)continue;
            while($b['nextSpawnAt']<=$limit){
                $at=$b['nextSpawnAt'];
                if(count($b['rows'])>=8){
                    $safeAt=$b['rows'][0]['bornAt']+self::EXPOSURE_MS;
                    if($at<$safeAt){$b['nextSpawnAt']=$safeAt;continue;}
                    $b['endedAt']=$at;break;
                }
                $b['level']=1+(int)floor(($at-$s['startedAt'])/30000);
                $b=self::spawn($s,$b,$at);$b['nextSpawnAt']=$at+self::difficulty($b['size'],$b['level'],$s['config']['difficulty'])['spawnMs'];
            }
        }unset($b);
        $alive=array_filter($s['boards'],static fn($b)=>$b['endedAt']===null);
        if(!$alive){$at=max(array_column($s['boards'],'endedAt'));return self::finish($s,$at,'overflow');}
        return $now>=$s['endsAt']?self::finish($s,$s['endsAt'],'time'):$s;
    }

    private static function spawn(array $s, array $b, int $at): array {
        $b['sequence']++;$hash=hash('sha256',$s['seed'].':'.$b['sequence']);$value=0;
        for($i=0;$i<32;$i++){
            $value=hexdec(substr(hash('sha256',$hash.':'.$i),0,6))%256;
            $ones=substr_count(decbin($value),'1');if($ones>=3&&$ones<=6)break;
        }
        $mode=hexdec(substr($hash,6,4))/65536<self::difficulty($b['size'],$b['level'],$s['config']['difficulty'])['inverseChance']?'bin-to-dec':'dec-to-bin';
        $b['rows'][]=array('id'=>$b['sequence'],'mode'=>$mode,'decimal'=>$value,'bits'=>array_fill(0,8,0),'bornAt'=>$at,'level'=>$b['level'],'owner'=>0,'leaseUntil'=>0);
        return $b;
    }

    private static function finish(array $s,int $at,string $reason): array {
        $s=self::release_all($s);$s['status']='finished';$s['finishedAt']=$at;
        $s['summary']=self::summary($s,$at,$reason);return $s;
    }

    public static function summary(array $s,int $at,string $reason): array {
        return Room_Lobby::summary($s,$at,$reason,self::RULESET);
    }

    public static function view(array $s,array $actor,int $now,int $watch=0): array {
        $v=Room_Lobby::view($s,$actor,$now,$watch);$board=$v['board'];
        if($board){foreach($board['rows'] as &$r){$r['prompt']=$r['mode']==='dec-to-bin'?(string)$r['decimal']:str_pad(decbin($r['decimal']),8,'0',STR_PAD_LEFT);unset($r['decimal']);}unset($r);$board['difficulty']=self::difficulty($board['size'],$board['level'],$s['config']['difficulty']);}
        $v['board']=$board;return $v;
    }

    public static function integer(mixed $value,int $min,int $max): int {
        if(filter_var($value,FILTER_VALIDATE_INT)===false||(int)$value<$min||(int)$value>$max)throw new Failure('설정 또는 입력 범위를 확인하세요.',422);
        return (int)$value;
    }
    private static function phase(array $s,string $phase): void {if($s['status']!==$phase)throw new Failure('현재 경기 상태에서 사용할 수 없습니다.');}
    private static function release_all(array $s): array {foreach($s['boards'] as &$b)foreach($b['rows'] as &$r){$r['owner']=0;$r['leaseUntil']=0;}unset($r,$b);return $s;}
}
