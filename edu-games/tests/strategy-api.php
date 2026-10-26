<?php
declare(strict_types=1);
// Included by strategy.php after the WordPress CLI doubles.
use EduGames\Rest\Controllers\Strategy_Controller as Strategy_API;
use EduGames\Boards\Catalog as Strategy_Catalog;
final class Strategy_DB {
    public string $prefix='wp_';public string $last_error='';public array $row=array();public array $best=array();public array $leaders=array();public bool $failure=false;public bool $readFailure=false;public bool $race=false;public int $writes=0;public int $recent=0;
    public function prepare($sql,...$args){return array($sql,$args);}
    public function get_var($q){return $this->readFailure?null:$this->recent;}
    public array $archive=array();public array $lastQuery=array();
    public function get_results($q,$format){$this->lastQuery=$q;return $this->readFailure?null:(str_contains($q[0],'content_version AS version')?$this->archive:(str_contains($q[0],'MAX(score) AS best')?$this->best:$this->leaders));}
    public function insert($table,$data,$format){if($this->failure)return false;$this->row=$data+array('id'=>1);return 1;}
    public function get_row($q,$format){$this->last_error=$this->readFailure?'Read failure':'';if($this->readFailure)return null;[$token,$user,$game,$version,$now]=$q[1];$r=$this->row;return $r&&$r['token']===$token&&$r['user_id']===$user&&$r['game_slug']===$game&&$r['content_version']===$version&&$r['mode']==='strategy'&&$r['expires_at']>$now?$r:null;}
    public function query($q){if($this->failure)return false;if($this->race)return 0;$a=$q[1];if($a[count($a)-2]!==$this->row['revision'])return 0;$this->row['state_json']=$a[0];$this->row['score']=$a[1];$this->row['revision']++;$this->row['completed_at']=str_contains($q[0],'completed_at=NULL')?null:$a[2];$this->writes++;return 1;}
}
$db=new Strategy_DB();$GLOBALS['wpdb']=$db;
foreach(Strategy_Catalog::GAMES as $game=>$class){
    $db->best=array();$db->leaders=array();$req=fn($data=array())=>new WP_REST_Request($game,$data);
    check_strategy(Strategy_API::allowed($req()),'known game allowed');
    $db->archive=array(array('version'=>'strategy-2026-09-v1','level'=>'board-01','score'=>'980','attempts'=>'2'));
    $oldWrites=$db->writes;check_strategy(Strategy_API::archive($req(array('user_id'=>8)))['records']===$db->archive,'archive returns prior season without mutation');
    check_strategy($db->lastQuery[1]===array(7,$game,Strategy_Catalog::version($game))&&$db->writes===$oldWrites,'archive binds signed-in user and requested game');
    check_strategy(str_contains($db->lastQuery[0],"mode='strategy'")&&str_contains($db->lastQuery[0],'content_version<>%s')&&str_contains($db->lastQuery[0],'completed_at IS NOT NULL'),'archive excludes current season and unfinished runs');
    $db->readFailure=true;check_strategy(Strategy_API::archive($req())->data['status']===500,'archive outage reported');$db->readFailure=false;
    check_strategy(Strategy_API::start($req(array('level'=>'board-02')))->data['status']===403,'server level locked');
    $db->readFailure=true;check_strategy(Strategy_API::summary($req())->data['status']===500,'summary fails closed');$db->readFailure=false;
    $db->failure=true;check_strategy(Strategy_API::start($req())->data['status']===500,'insert error reported');$db->failure=false;
    $start=Strategy_API::start($req());check_strategy(strlen($start['token'])===48,'opaque token');
    check_strategy($db->row['content_version']===Strategy_Catalog::version($game),'session uses game-specific current season');
    $input=array('token'=>$start['token'],'revision'=>0,'requestId'=>'strategy-request-1','move'=>$class::solution('board-01')[0]);
    $db->readFailure=true;check_strategy(Strategy_API::resume($req($input))->data['status']===500,'read outage is not expiry');$db->readFailure=false;
    $db->failure=true;check_strategy(Strategy_API::update($req($input))->data['status']===500,'write failure');$db->failure=false;
    $db->race=true;check_strategy(Strategy_API::update($req($input))->data['status']===409,'CAS race');$db->race=false;
    $writes=$db->writes;$saved=Strategy_API::update($req($input));check_strategy($saved===Strategy_API::update($req($input))&&$db->writes===$writes+1,'idempotent retry');
    check_strategy(Strategy_API::update($req(array_replace($input,array('requestId'=>'strategy-request-2'))))->data['status']===409,'stale revision');
    foreach(array('user_id'=>8,'content_version'=>'other','mode'=>'practice','game_slug'=>'other','expires_at'=>'2000-01-01 00:00:00') as $key=>$value){$old=$db->row[$key];$db->row[$key]=$value;check_strategy(Strategy_API::resume($req($input))->data['status']===404,'session binding '.$key);$db->row[$key]=$old;}
    foreach(array_slice($class::solution('board-01'),1) as $i=>$move){$saved=Strategy_API::update($req(array('token'=>$start['token'],'revision'=>$saved['revision'],'requestId'=>'strategy-next-'.$i,'move'=>$move)));}
    check_strategy($saved['status']==='won'&&$db->row['score']>0&&$db->row['completed_at']!==null,'score saved on finish');
    $db->best=array(array('level'=>'board-01','best'=>1000));$db->leaders=array(array('user_id'=>7,'score'=>1000),array('user_id'=>8,'score'=>1000),array('user_id'=>9,'score'=>900));
    $summary=Strategy_API::summary($req());check_strategy($summary['stars']===3&&$summary['levels']['board-02']['unlocked']&&!$summary['levels']['board-03']['unlocked'],'stars unlock persist');
    check_strategy(array_column($summary['leaderboard'],'rank')===array(1,1,3)&&!isset($summary['leaderboard'][0]['user_id']),'tied ranks privacy');
    $db->recent=10;check_strategy(Strategy_API::start($req())->data['status']===429,'rate limit');$db->recent=0;
}
check_strategy(!Strategy_API::allowed(new WP_REST_Request('unknown')),'unknown game denied');$GLOBALS['caps']=array();check_strategy(!Strategy_API::allowed(new WP_REST_Request('data-extraction')),'capability denied');unset($GLOBALS['caps']);
