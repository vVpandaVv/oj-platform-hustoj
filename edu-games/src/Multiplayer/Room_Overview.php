<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;

/** Projectors receive activity, never puzzle contents or a playable board. */
final class Room_Overview {
    public static function view(array $s,array $actor,int $now): array {
        $v=Room_Lobby::view($s,$actor,$now);$v['board']=null;$v['overview']=true;$v['readOnly']=($actor['scope']??'')==='board';
        foreach($v['teams'] as &$t){
            $b=$s['boards'][$t['team']]??null;$t['wrong']=$b['wrong']??0;$t['preview']=array();
            if(!$b)continue;
            $t['stageCorrect']=$b['stageCorrect']??0;
            foreach($b['cards']??$b['rows']??array() as $item)$t['preview'][]=array('state'=>$item['state']??'active','owner'=>$item['owner']);
        }unset($t);
        $ranked=$v['teams'];usort($ranked,static fn($a,$b)=>($b['score']<=>$a['score'])?:($b['correct']<=>$a['correct'])?:($a['wrong']<=>$b['wrong']));
        $rank=0;$last=null;foreach($ranked as $i=>$t){$key=$t['score'].':'.$t['correct'].':'.$t['wrong'];if($key!==$last)$rank=$i+1;$last=$key;foreach($v['teams'] as &$team)if($team['team']===$t['team'])$team['rank']=$rank;unset($team);}
        $v['feed']=$s['feed']??array();return $v;
    }
    public static function events(array $before,array $s,int $now): array {
        $text=array();
        if($before['status']!==$s['status'])$text[]=array('lobby'=>'대기실로 돌아왔습니다','playing'=>$before['status']==='paused'?'경기를 재개했습니다':'경기를 시작했습니다','paused'=>'경기를 일시 정지했습니다','finished'=>'경기가 종료되었습니다')[$s['status']];
        foreach($s['players'] as $id=>$p){
            if(!isset($before['players'][$id]))$text[]=$p['name'].' 참가';
            elseif($before['players'][$id]['team']!==$p['team'])$text[]=$p['name'].' → '.($p['team']?$p['team'].'팀':'미배정');
        }
        foreach($before['players'] as $id=>$p)if(!isset($s['players'][$id]))$text[]=$p['name'].' 참가 해제';
        foreach($s['boards'] as $team=>$b){$old=$before['boards'][$team]??array();$delta=$b['correct']-($old['correct']??0);
            if($delta>0)$text[]=$team.'팀 +'.$delta.' 해결 · '.$b['score'].'점';
            if(($b['stage']??0)>($old['stage']??0))$text[]=$team.'팀 '.($b['stage']).'판 시작';
            if($b['endedAt']!==null&&($old['endedAt']??null)===null)$text[]=$team.'팀 보드 포화';
        }
        foreach($text as $line)$s['feed'][]=array('at'=>$now,'text'=>$line);
        if(isset($s['feed']))$s['feed']=array_slice($s['feed'],-24);return $s;
    }
}
