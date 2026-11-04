<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;
use EduGames\Games\Modules\Device_Matching\Device_Matching_Module;

final class Device_Room {
    public const RULESET='device-team-v1',REVEAL_MS=1100,CLAIM_MS=12000;
    public static function create(string $code,array $actor,array $config,int $now,string $seed): array {
        return Room_Lobby::create($code,$actor,$config+array('title'=>'장치 카드 팀전'),$now,$seed)
            +array('game'=>Room_Rules::DEVICE,'deviceUsage'=>array(),'devicePrevious'=>array(),'decks'=>array());
    }
    public static function pairs(int $size,int $stage,string $difficulty='standard'): int {
        $levels=array(12,16,20,24,28,32);$index=max(0,min(4,$size-2))+min(2,max(0,$stage-1))+($difficulty==='challenge'?1:0);
        return $levels[min(count($levels)-1,$index)];
    }
    public static function command(array $s,array $actor,array $c,int $now): array {
        $handled=Room_Lobby::command($s,$actor,$c,$now);$uid=(int)$actor['id'];$key=(string)$uid;$type=$c['type'];
        if($handled!==null){
            $s=$handled;
            if($type==='rematch'){$s['decks']=array();return $s;}
            if(!in_array($type,array('start','practice-start'),true))return $s;
            $counts=array_fill(1,$s['config']['teams'],0);foreach($s['players'] as $p)$counts[$p['team']]++;
            foreach($counts as $team=>$size){
                $b=array('team'=>$team,'size'=>$size,'score'=>0,'correct'=>0,'wrong'=>0,'combo'=>0,'stage'=>0,'endedAt'=>null,'turns'=>array(),'cards'=>array(),'event'=>null,'eventId'=>0);
                $s['boards'][$team]=self::next_stage($s,$b,$s['startedAt']);
            }
            return $s;
        }
        $host=Room_Policy::controls($s,$actor);
        if(in_array($type,array('pause','resume','stop'),true)){
            if(!$host)throw new Failure('방장만 사용할 수 있습니다.',403);
            if($type==='pause'){Room_Lobby::phase($s,'playing');$s['status']='paused';$s['pausedAt']=$now;return $s;}
            if($type==='resume'){Room_Lobby::phase($s,'paused');$s=self::shift($s,$now-$s['pausedAt']);$s['status']='playing';unset($s['pausedAt']);return $s;}
            if(!in_array($s['status'],array('playing','paused'),true))throw new Failure('진행 중인 경기가 없습니다.');
            if($s['status']==='paused')$s=self::shift($s,$now-$s['pausedAt']);
            return self::finish($s,$now,'host');
        }
        if(!in_array($type,array('flip','release-cards'),true))throw new Failure('지원하지 않는 조작입니다.',422);
        Room_Lobby::phase($s,'playing');
        if($now<$s['startedAt'])throw new Failure('카운트다운이 끝나면 시작합니다.');
        if(!isset($s['players'][$key]))throw new Failure('관전 중에는 조작할 수 없습니다.',403);
        $team=$s['players'][$key]['team'];$b=&$s['boards'][$team];
        if(($c['stage']??null)!==$b['stage'])throw new Failure('다음 판이 열렸습니다. 새 카드를 확인하세요.');
        if($b['clearAt'])throw new Failure('다음 판을 준비하고 있습니다.');
        $turn=$b['turns'][$key]??null;
        if($type==='release-cards'){
            if($turn&&count($turn['cards'])===1){foreach($turn['cards'] as $index)self::close_card($b['cards'][$index]);unset($b['turns'][$key]);}
            return $s;
        }
        if(!is_string($c['card']??null))throw new Failure('카드를 확인하세요.',422);
        if($turn&&count($turn['cards'])===2)throw new Failure('오답 카드가 닫힌 뒤 다시 선택하세요.');
        $index=null;foreach($b['cards'] as $i=>$card)if($card['id']===$c['card']){$index=$i;break;}
        if($index===null)throw new Failure('현재 판의 카드가 아닙니다.');
        $card=&$b['cards'][$index];
        if($card['state']!=='down')throw new Failure('이미 열렸거나 다른 팀원이 선택한 카드입니다.');
        $card['state']='open';$card['owner']=$uid;$s['players'][$key]['edits']++;
        $turn??=array('cards'=>array(),'startedAt'=>$now,'until'=>$now+self::CLAIM_MS);
        $turn['cards'][]=$index;$turn['until']=$now+self::CLAIM_MS;
        if(count($turn['cards'])===1){$b['turns'][$key]=$turn;return $s;}
        [$first,$second]=$turn['cards'];$match=$b['cards'][$first]['device']===$b['cards'][$second]['device']&&$b['cards'][$first]['kind']!==$b['cards'][$second]['kind'];
        if($match){
            $b['combo']++;$b['correct']++;$b['stageCorrect']++;$s['players'][$key]['correct']++;
            $gained=100+10*($b['stage']-1)+min(60,10*($b['combo']-1))+max(0,30-(int)floor(($now-$turn['startedAt'])/500));$b['score']+=$gained;
            foreach($turn['cards'] as $i){$b['cards'][$i]['state']='matched';$b['cards'][$i]['matchedAt']=$now;}
            unset($b['turns'][$key]);
            $b['event']=array('id'=>++$b['eventId'],'type'=>'match','player'=>$uid,'name'=>self::catalog()[$card['device']]['name'],'points'=>$gained);
            if($b['stageCorrect']===$b['pairs']){$bonus=150+4*$b['pairs'];$b['score']+=$bonus;$b['clearAt']=$now+2200;$b['event']['bonus']=$bonus;}
        }else{
            $b['wrong']++;$b['combo']=0;$b['score']=max(0,$b['score']-20);$s['players'][$key]['wrong']++;
            foreach($turn['cards'] as $i)$b['cards'][$i]['state']='miss';
            $turn['until']=$now+self::REVEAL_MS;$b['turns'][$key]=$turn;
            $b['event']=array('id'=>++$b['eventId'],'type'=>'miss','player'=>$uid,'points'=>-20);
        }
        return $s;
    }
    public static function tick(array $s,int $now): array {
        if($s['status']==='paused')return $now-$s['pausedAt']>600000?self::finish(self::shift($s,$now-$s['pausedAt']),$now,'pause-timeout'):$s;
        if($s['status']!=='playing'||$now<$s['startedAt'])return $s;
        if($now>=$s['endsAt'])return self::finish($s,$s['endsAt'],'time');
        foreach(array_keys($s['boards']) as $team){
            $b=$s['boards'][$team];
            foreach($b['turns'] as $uid=>$turn)if($turn['until']<=$now){foreach($turn['cards'] as $i)self::close_card($b['cards'][$i]);unset($b['turns'][$uid]);}
            if($b['clearAt']&&$b['clearAt']<=$now)$b=self::next_stage($s,$b,$b['clearAt']);
            $s['boards'][$team]=$b;
        }
        return $s;
    }
    private static function next_stage(array &$s,array $b,int $at): array {
        $stage=$b['stage']+1;$pairs=self::pairs($b['size'],$stage,$s['config']['difficulty']);
        if(!isset($s['decks'][$stage])){
            $ids=array_keys(self::catalog());$usage=$s['deviceUsage'];$previous=$s['devicePrevious'];$seed=$s['seed'].':'.$stage;
            usort($ids,static fn($a,$c)=>(in_array($a,$previous,true)<=>in_array($c,$previous,true))?:(($usage[$a]??0)<=>($usage[$c]??0))?:strcmp(hash('sha256',$seed.$a),hash('sha256',$seed.$c)));
            $chosen=array_slice($ids,0,$pairs);if(count($chosen)!==$pairs)throw new Failure('장치 자료가 부족합니다.',503);
            $s['decks'][$stage]=$chosen;$s['devicePrevious']=$chosen;foreach($chosen as $id)$s['deviceUsage'][$id]=($s['deviceUsage'][$id]??0)+1;
        }
        $cards=array();foreach($s['decks'][$stage] as $id)foreach(array('photo','text') as $kind)$cards[]=array(
            'id'=>substr(hash('sha256',$s['seed'].':'.$stage.':'.$id.':'.$kind),0,24),'device'=>$id,'kind'=>$kind,'state'=>'down','owner'=>0,'matchedAt'=>0);
        usort($cards,static fn($a,$c)=>strcmp(hash('sha256',$s['seed'].':order:'.$a['id']),hash('sha256',$s['seed'].':order:'.$c['id'])));
        return array_replace($b,array('stage'=>$stage,'pairs'=>$pairs,'stageCorrect'=>0,'stageStartedAt'=>$at,'clearAt'=>0,'cards'=>$cards,'turns'=>array(),'event'=>null));
    }
    public static function view(array $s,array $actor,int $now,int $watch=0): array {
        $v=Room_Lobby::view($s,$actor,$now,$watch);$b=$v['board'];if(!$b)return $v;
        $catalog=self::catalog();$cards=array();
        foreach($b['cards'] as $card){
            $public=array_intersect_key($card,array_flip(array('id','state','owner','matchedAt')));
            if(in_array($card['state'],array('open','miss'),true)){
                $d=$catalog[$card['device']];$public['face']=array('kind'=>$card['kind'],'name'=>$d['name'],'description'=>$d['description'],'category'=>$d['categoryLabel'],
                    'image'=>$d['image'],'alt'=>$d['imageAlt'],'sourceUrl'=>$d['sourceUrl'],'author'=>$d['author'],'license'=>$d['license']);
                // A photograph carries its visual subject, not a visible answer label.
                if($card['kind']==='photo')unset($public['face']['name'],$public['face']['description']);
            }
            $cards[]=$public;
        }
        $b['cards']=$cards;
        $b['turns']=array_map(static fn($turn)=>array('count'=>count($turn['cards']),'until'=>$turn['until']),$b['turns']);
        $v['board']=$b;return $v;
    }
    private static function close_card(array &$card): void {$card['state']='down';$card['owner']=0;}
    private static function shift(array $s,int $ms): array {
        $s['startedAt']+=$ms;$s['endsAt']+=$ms;$s['pausedMs']+=$ms;
        foreach($s['boards'] as &$b){$b['stageStartedAt']+=$ms;if($b['clearAt'])$b['clearAt']+=$ms;foreach($b['turns'] as &$turn){$turn['until']+=$ms;$turn['startedAt']+=$ms;}unset($turn);}unset($b);return $s;
    }
    private static function finish(array $s,int $at,string $reason): array {
        $s['status']='finished';$s['finishedAt']=$at;
        foreach($s['boards'] as &$b){foreach($b['cards'] as &$card)if($card['state']!=='matched')self::close_card($card);unset($card);$b['turns']=array();}unset($b);
        $s['summary']=Room_Lobby::summary($s,$at,$reason,self::RULESET);return $s;
    }
    public static function catalog(): array {
        static $catalog;return $catalog??=array_column(Device_Matching_Module::rules()['devices'],null,'id');
    }
}
