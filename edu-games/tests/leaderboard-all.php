<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EduGames\Rankings\Leaderboard_Service as Ranking;
use EduGames\Groups\Group_Ranking_Service as Groups;
use EduGames\Rest\Controllers\Leaderboard_Controller as API;
$checks=0;
function rank_check(bool $ok,string $message): void {global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
final class Ranking_DB {
    public string $prefix='wp_';public array $last=array();public array $rows=array();
    public function __construct(){for($i=1;$i<=180;$i++)$this->rows[]=array('user_id'=>$i,'player_name'=>'학생 '.$i,'score'=>$i===180?0:1000-$i,'rounds_played'=>3,'correct_count'=>2,'duration_seconds'=>30,'accuracy'=>80.5,'attempts'=>2,'created_at'=>'2026-09-12 00:00:00');}
    public function prepare($sql,...$args){return array($sql,count($args)===1&&is_array($args[0])?$args[0]:$args);}
    public function get_row($query,$format){return null;}
    public function get_var($query){return 0;}
    public function get_col($query){return $query[1][0]===999?array():range(1,60);}
    public function get_results($query,$format){
        if(is_string($query))return array_map(static fn($id)=>array('id'=>$id,'name'=>'팀 '.$id),range(1,60));
        $this->last=$query;[$sql,$args]=$query;$rows=$this->rows;$limited=preg_match('/LIMIT %d\s*$/',$sql)===1;
        if(str_contains($sql,'user_id IN')){$ids=array_slice($args,1,$limited?-1:null);$rows=array_values(array_filter($rows,static fn($r)=>in_array($r['user_id'],$ids,true)));}
        if($limited)$rows=array_slice($rows,0,$args[count($args)-1]);return $rows;
    }
}
final class Ranking_Request extends WP_REST_Request {
    public function __construct(private array $params=array()){parent::__construct('character-code');}
    public function get_param($name){return $this->params[$name]??($name==='game'?'character-code':null);}
    public function offsetGet(mixed $offset): mixed {return $this->params[$offset]??parent::offsetGet($offset);}
}
$db=new Ranking_DB();$GLOBALS['wpdb']=$db;
$all=Ranking::for_game('character-code');
rank_check(count($all)===180&&$all[179]['rank']===180,'all 180 scored participants are reachable');
rank_check($all[179]['score']===0,'zero-score participant retained');
rank_check(!preg_match('/LIMIT %d\s*$/',$db->last[0])&&$db->last[1]===array('character-code','character-code'),'unlimited outer query with bound game');
rank_check(str_contains($db->last[0],'scores.id = (')&&str_contains($db->last[0],'LIMIT 1'),'canonical one-best-attempt subquery retained');
rank_check(count(Ranking::for_game('character-code',20))===20,'explicit legacy limit remains supported');
rank_check(count(Ranking::for_game('character-code',120))===120,'no hidden fifty-participant cap');
rank_check(count(Ranking::for_game_among_users('character-code',range(1,60)))===60,'all sixty team members');
rank_check(count(Ranking::for_game_among_users('character-code',array(2,2,-3,0,180)))===2,'team IDs remain scoped and deduplicated');
rank_check(Ranking::for_game_among_users('character-code',array())===array(),'empty team has no ranking');
rank_check(count(Groups::leaderboard(1,'character-code'))===60,'team ranking default includes all');
$teams=Groups::standings('character-code');
rank_check(count($teams)===60&&$teams[59]['rank']===60,'all teams, not first twenty/fifty');
rank_check($teams[0]['memberCount']===60&&$teams[0]['totalScore']===58170&&$teams[0]['averageScore']===969.5,'team average includes members below fiftieth');
rank_check(count(Groups::standings('character-code',5))===5,'explicit team limit compatibility');
foreach(array(new Ranking_Request(),new Ranking_Request(array('limit'=>'0'))) as $req)rank_check(count(API::get_game_leaderboard($req)['leaderboard'])===180,'REST default/zero includes all participants');
rank_check(count(API::get_game_leaderboard(new Ranking_Request(array('limit'=>20)))['leaderboard'])===20,'REST explicit limit compatibility');
rank_check(count(API::get_team_standings(new Ranking_Request())['standings'])===60,'REST all team standings');
rank_check(count(API::get_team_leaderboard(new Ranking_Request(array('team_id'=>1)))['leaderboard'])===60,'REST all members');
rank_check(API::can_read_team_leaderboard(new Ranking_Request(array('team_id'=>1))),'own team permission retained');
rank_check(!API::can_read_team_leaderboard(new Ranking_Request(array('team_id'=>999))),'unrelated team stays private');
$GLOBALS['caps']=array();rank_check(!API::can_read_leaderboard(),'anonymous capability denied');unset($GLOBALS['caps']);
foreach(array('Strategy_Controller','Warehouse_Controller','Learning_Controller') as $file){
    $text=file_get_contents(EDU_GAMES_PATH.'src/Rest/Controllers/'.$file.'.php');
    preg_match('/SELECT user_id, MAX\(score\) AS score[^\n]+/',$text,$query);
    rank_check(isset($query[0])&&!str_contains($query[0],'LIMIT 10')&&!str_contains($query[0],'AND score>0'),$file.' complete participant query');
    rank_check(str_contains($query[0],'completed_at IS NOT NULL')&&str_contains($query[0],'content_version=%s'),$file.' completed/version scope retained');
}
echo "PASS: $checks all-participant ranking and permission checks (SQL/WordPress doubles).\n";
