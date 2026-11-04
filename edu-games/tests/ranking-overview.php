<?php
declare(strict_types=1);
require __DIR__.'/v1.php';
use EduGames\Rankings\{Leaderboard_Service,Run_Leaderboard};
function rest_url($path){return '/'.$path;}
$base=$checks;
$overview=Leaderboard_Service::overview('binary-conversion');
check($overview['personal']['rank']===7&&$overview['personal']['score']===173.0,'exact arcade own rank and score outside display logic');
$scoped=Leaderboard_Service::overview('binary-conversion',array(5,6,7,8));
check($scoped['personal']['rank']===3,'within-team overview uses team ranking, not global ranking');
check(Leaderboard_Service::overview('binary-conversion',array(1,2))['personal']===null,'another team never exposes a personal rank');
check(Leaderboard_Service::overview('character-code')['topScore']===null,'empty game has no invented top score');
$game='missing-values';$version='ranking-overview-test';$level='board-01';
foreach(range(1,43) as $uid)$wpdb->insert('wp_edu_games_learning_runs',array('user_id'=>$uid,'game_slug'=>$game,'content_version'=>$version,'mode'=>'play','level'=>$level,'score'=>$uid===7?0:($uid===2?999:1000-$uid),'completed_at'=>'2026-11-03 01:00:00'));
$page=Run_Leaderboard::page($game,$version,'play',$level,1,20,true);
check($page['overview']['personal']===array('score'=>0.0,'rank'=>43),'zero-score own rank remains available outside first page');
check($page['overview']['topScore']===999.0&&$page['leaderboard'][0]['rank']===1&&$page['leaderboard'][1]['rank']===1,'same-score shared ranks retained');
check(!array_filter($page['leaderboard'],fn($r)=>$r['isMe']),'no other row is marked as the viewer');
$last=Run_Leaderboard::page($game,$version,'play',$level,3,20,true);
check($last['leaderboard'][2]['isMe']&&$last['leaderboard'][2]['rank']===43,'own-row marker retained on last page without leaking IDs');
check(!isset($last['leaderboard'][2]['user_id'])&&!isset($last['leaderboard'][2]['userId']),'existing run identity privacy retained');
$empty=Run_Leaderboard::page($game,'another-version','play',$level,1,20,true);
check($empty['overview']===array('topScore'=>null,'personal'=>null),'different version never merges the daily ranking');
$normal=Run_Leaderboard::page($game,$version,'play',$level,1,20);
check(!isset($normal['overview'])&&!isset($normal['leaderboard'][0]['isMe']),'existing consumers keep their original response shape');
$wpdb->fail=true;check(is_wp_error(Run_Leaderboard::page($game,$version,'play',$level,1,20,true)),'query failures never report empty success');$wpdb->fail=false;
echo ($checks-$base)." ranking overview and privacy checks passed\n";
