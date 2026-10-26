<?php
declare(strict_types=1);
require __DIR__.'/v1.php';
use EduGames\Rest\Controllers\Ranking_Records_Controller as Delete_API;
use EduGames\Rankings\Record_Moderation;
use EduGames\Rankings\Leaderboard_Service as Rankings;
use EduGames\Rankings\Run_Leaderboard;

function rest_url($path) { return '/'.$path; }
function wp_verify_nonce($nonce,$action) { return $nonce==='valid' && $action==='wp_rest'; }
function do_action($name,...$args) { $GLOBALS['audit'][]=array($name,$args); }
final class Delete_Request extends WP_REST_Request {
    public function __construct(string $game, private string $store, private int $id, private string $nonce='valid') { parent::__construct($game); }
    public function offsetGet(mixed $key): mixed { return match($key) {'store'=>$this->store,'id'=>$this->id,default=>parent::offsetGet($key)}; }
    public function get_header($name) { return $this->nonce; }
}
$base=$checks;
$GLOBALS['caps']=array('edu_games_play','edu_games_manage');
check(!Record_Moderation::allowed(),'teacher management alone does not permit deletion');
check(Delete_API::delete(new Delete_Request('binary-conversion','score',1))->data['status']===403,'non-admin rejected in handler too');
check(!isset(Rankings::page('binary-conversion')['leaderboard'][0]['deleteUrl']),'student and teacher have no delete metadata');
$GLOBALS['caps']=array('manage_options');
check(Delete_API::delete(new Delete_Request('binary-conversion','score',1,''))->data['status']===403,'missing nonce rejected');
check(Delete_API::delete(new Delete_Request('binary-conversion','score',1,'expired'))->data['status']===403,'expired nonce rejected');
$p=Rankings::page('binary-conversion');
check(str_ends_with($p['leaderboard'][0]['deleteUrl'],'/records/score/1'),'exact canonical attempt is deletable');
check(Delete_API::delete(new Delete_Request('character-code','score',1))->data['status']===404,'cross-game ID rejected');
check(Delete_API::delete(new Delete_Request('binary-conversion','bogus',1))->data['status']===400,'store whitelist');
check(Delete_API::delete(new Delete_Request('binary-conversion','score',1))['deleted'],'admin removes one attempt');
check(Rankings::rank_for_user('binary-conversion',1)===179,'remaining attempt supplies next valid rank');
check(Rankings::page('binary-conversion')['pagination']['total']===180,'participant retained with earlier attempt');
check(EduGames\Progress\Store::read(1,'binary-conversion')['bestScore']===1,'personal best recalculated');
check(Delete_API::delete(new Delete_Request('binary-conversion','score',1))->data['status']===404,'repeated delete does not remove next attempt');
$last=(int)$wpdb->get_var("SELECT id FROM wp_edu_games_scores WHERE user_id=180");
check(Delete_API::delete(new Delete_Request('binary-conversion','score',$last))['deleted'],'last attempt deleted');
check(Rankings::page('binary-conversion')['pagination']['total']===179,'participant count shrinks on last attempt');
$game='missing-values';$version='delete-test';$level='board-01';
$make=fn($u,$score,$done='2026-10-15 01:00:00')=>$wpdb->insert('wp_edu_games_learning_runs',array('user_id'=>$u,'game_slug'=>$game,'content_version'=>$version,'mode'=>'play','level'=>$level,'score'=>$score,'completed_at'=>$done));
$first=$make(7,900);$second=$make(7,900);$lower=$make(7,700);$other=$make(8,800);$active=$make(7,999,null);
$p=Run_Leaderboard::page($game,$version,'play',$level);
check(count($p['leaderboard'])===2&&str_ends_with($p['leaderboard'][0]['deleteUrl'],'/run/'.$first),'run best tie resolves deterministically');
check(Delete_API::delete(new Delete_Request($game,'run',$active))->data['status']===404,'active run cannot be deleted');
check(Delete_API::delete(new Delete_Request($game,'run',$first))['deleted'],'run deletion succeeds');
check(str_ends_with(Run_Leaderboard::page($game,$version,'play',$level)['leaderboard'][0]['deleteUrl'],'/run/'.$second),'equal next attempt replaces deleted run');
check(Delete_API::delete(new Delete_Request($game,'run',$second))['deleted'],'next tied run can be removed explicitly');
check(Run_Leaderboard::page($game,$version,'play',$level)['leaderboard'][0]['score']===800.0,'other participant moves up');
$wpdb->fail=true;check(Delete_API::delete(new Delete_Request($game,'run',$lower))->data['status']===500,'database error is not reported as success');$wpdb->fail=false;
check((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_edu_games_learning_runs WHERE id='.$lower)===1,'failed deletion preserves record');
check(count($GLOBALS['audit'])===4&&$GLOBALS['audit'][0][1][0]['actor']===7,'successful mutations audited with actor');
unset($GLOBALS['caps']);
check(!isset(Run_Leaderboard::page($game,$version,'play',$level)['leaderboard'][0]['deleteUrl']),'learner run ranking has no delete controls');
echo ($checks-$base)." ranking moderation checks passed\n";
