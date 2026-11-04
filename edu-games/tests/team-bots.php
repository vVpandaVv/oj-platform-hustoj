<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EduGames\Multiplayer\{Room_Rules as R,Device_Room};
$checks=0;$stats=array();
function bot_check(bool $ok,string $message): void {global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function bot_room(string $game,string $pace,string $seed): array {
    $actor=array('id'=>1,'name'=>'학생','manage'=>false,'admin'=>false);
    $s=R::create('ABC234',$actor,array('botPace'=>$pace,'participate'=>true),1000000,$seed,$game);
    return R::command($s,$actor,array('type'=>'practice-start'),1000000);
}
foreach(array(R::BINARY,R::DEVICE) as $game){
    foreach(array('slow','normal','fast') as $pace){
        $total=array('correct'=>0,'wrong'=>0,'edits'=>0);
        for($seed=0;$seed<16;$seed++){
            $s=bot_room($game,$pace,'bot-level-'.$seed);$start=$s['startedAt'];
            $initial=R::tick($s,$start+2000);bot_check(array_sum(array_column($initial['players'],'edits'))===0,'bots leave an initial reading window');
            for($at=$start;$at<=$start+45000;$at+=250)$s=R::tick($s,$at);
            $total['correct']+=array_sum(array_column($s['players'],'correct'));$total['wrong']+=array_sum(array_column($s['players'],'wrong'));$total['edits']+=array_sum(array_column($s['players'],'edits'));
            foreach($s['botState']??array() as $state)bot_check(count($state['memory'])<=array('slow'=>4,'normal'=>8,'fast'=>12)[$pace],'bounded learner-sized memory');
        }
        bot_check($total['edits']>0,'every profile remains active');bot_check($total['wrong']>0,'profiles are not perfect solvers');
        $stats[$game][$pace]=$total;
    }
    bot_check($stats[$game]['slow']['correct']<$stats[$game]['normal']['correct'],'normal is stronger than beginner on the same seeds');
    bot_check($stats[$game]['normal']['correct']<$stats[$game]['fast']['correct'],'challenge is stronger than normal on the same seeds');
}
$s=bot_room(R::DEVICE,'slow','hidden-card-invariance');$at=$s['startedAt']+3900;$changed=$s;
$ids=array_column($changed['boards'][1]['cards'],'device');$ids=array_reverse($ids);
foreach($changed['boards'][1]['cards'] as $i=>&$card)$card['device']=$ids[$i];unset($card);
$a=R::tick($s,$at);$b=R::tick($changed,$at);
bot_check(array_column($a['boards'][1]['cards'],'state')===array_column($b['boards'][1]['cards'],'state'),'first choice cannot depend on hidden card identities');
$s=bot_room(R::DEVICE,'slow','forgetting');$at=$s['startedAt']+30000;
foreach($s['players'] as $id=>$p)if(!empty($p['bot']))$s['botState'][$id]=array('next'=>$at+10000,'memory'=>array(),'stage'=>1,'step'=>0);
$s['botState']['-1']['next']=$at;$card=$s['boards'][1]['cards'][0];$s['botState']['-1']['memory'][$card['id']]=array('device'=>$card['device'],'kind'=>$card['kind'],'seen'=>$at-20000);
$s=R::tick($s,$at);bot_check(!isset($s['botState']['-1']['memory'][$card['id']]),'stale card memory actually expires');
echo 'PASS: '.$checks.' bot response, mistakes, memory and difficulty checks. '.json_encode($stats,JSON_UNESCAPED_UNICODE)."\n";
