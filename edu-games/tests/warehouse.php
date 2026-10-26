<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EduGames\Games\Modules\Data_Structure_Lab\Warehouse_Board as Board;
use EduGames\Rest\Controllers\Warehouse_Controller as Controller;
$checks=0;
function verify_board(bool $ok,string $label): void { global $checks; $checks++; if(!$ok) { throw new RuntimeException($label); } }
function invalid_move(callable $fn): void { try { $fn(); } catch(InvalidArgumentException $e) { verify_board(true,'invalid'); return; } throw new RuntimeException('Invalid move accepted'); }
$solutions=require EDU_GAMES_PATH.'src/Games/Modules/Data_Structure_Lab/warehouse-solutions.php';
foreach(Board::levels() as $id=>$definition) {
    $state=Board::create($id); verify_board(count($solutions[$id])===count(Board::solve($definition)),'optimal par');
    foreach($solutions[$id] as $action) {
        $state=Board::apply($state,$action); $p=$state['position'];
        $cargo=array_merge(array_slice($definition['input'],$p['cursor']),array_slice($definition['target'],0,$p['delivered']),...array_values($p['lanes']));
        sort($cargo); verify_board($cargo===$definition['input'],'cargo conservation');
        foreach($p['lanes'] as $items) { verify_board(count($items)<=$definition['capacity'],'capacity'); }
        $view=Board::view($state); verify_board(!isset($view['history'])&&!isset($view['lastRequest']),'private projection');
    }
    verify_board($state['status']==='won'&&Board::score($state)===1000&&Board::stars(Board::score($state))===3,'perfect win');
    invalid_move(fn()=>Board::apply($state,array('type'=>'undo')));
}
$state=Board::create('route-01');
$exact=$state; $exact['budget']=$exact['par'];
foreach($solutions['route-01'] as $move) { $exact=Board::apply($exact,$move); }
verify_board($exact['status']==='won'&&$exact['moves']===$exact['budget'],'last energy wins');
invalid_move(fn()=>Board::apply($state,array('type'=>'ship','from'=>'stack')));
invalid_move(fn()=>Board::apply($state,array('type'=>'load','to'=>array())));
invalid_move(fn()=>Board::apply($state,array('type'=>'hack','score'=>999999)));
invalid_move(fn()=>Board::apply($state,array('type'=>'undo')));
$state=Board::apply($state,array('type'=>'load','to'=>'stack'));
$wrong=Board::apply($state,array('type'=>'ship','from'=>'stack'));
verify_board($wrong['position']===$state['position']&&$wrong['moves']===2&&$wrong['mistakes']===1,'wrong costs energy without removing cargo');
$undo=Board::apply($wrong,array('type'=>'undo'));
verify_board($undo['position']['cursor']===0&&$undo['moves']===3&&$undo['mistakes']===1&&$undo['undos']===1,'undo cannot reclaim resources');
while($wrong['status']==='playing') { $wrong=Board::apply($wrong,array('type'=>'ship','from'=>'stack')); }
verify_board($wrong['status']==='lost'&&Board::score($wrong)===0,'finite loss');
$p=array('cursor'=>6,'delivered'=>0,'lanes'=>array('stack'=>array(),'queue'=>array(),'priority'=>array('A','B','D','E')));
$d=Board::levels()['route-06'];
[$p,$wrong]=Board::move($p,$d,array('type'=>'ship','from'=>'priority')); verify_board(!$wrong,'priority higher first B');
[$p,$wrong]=Board::move($p,$d,array('type'=>'ship','from'=>'priority')); verify_board(!$wrong,'equal priority FIFO D');
verify_board(Board::stars(975)===3&&Board::stars(850)===2&&Board::stars(849)===1&&Board::stars(0)===0,'star boundaries');

final class Warehouse_DB {
    public string $prefix='wp_'; public array $row=array(); public array $best=array(); public bool $race=false; public bool $failure=false; public bool $readFailure=false; public int $writes=0; public int $recent=0;
    public function prepare($sql,...$args) { return array($sql,$args); }
    public function get_var($q) { return $this->recent; }
    public function get_results($q,$format) { return $this->readFailure?null:(str_contains($q[0],'MAX(score) AS best')?$this->best:array()); }
    public function insert($table,$data,$format) { if($this->failure)return false; $this->row=$data+array('id'=>1); return 1; }
    public function get_row($q,$format) {
        [$token,$user,$game,$version,$now]=$q[1]; $r=$this->row;
        return $r&&$token===$r['token']&&$user===$r['user_id']&&$game===$r['game_slug']&&$version===$r['content_version']&&$r['mode']==='board'&&$now<$r['expires_at']?$r:null;
    }
    public function query($q) {
        if($this->failure)return false; if($this->race)return 0;
        $args=$q[1]; if($args[count($args)-2]!==$this->row['revision'])return 0;
        $this->row['state_json']=$args[0]; $this->row['score']=$args[1]; $this->row['revision']++; $this->writes++; return 1;
    }
}
$GLOBALS['wpdb']=new Warehouse_DB(); $db=$GLOBALS['wpdb'];
$db->readFailure=true; verify_board(Controller::summary(new WP_REST_Request(''))->data['status']===500,'summary read failure'); $db->readFailure=false;
verify_board(Controller::start(new WP_REST_Request('',array('level'=>'route-02')))->data['status']===403,'locked level server guard');
$p=Controller::progress(); verify_board($p['levels']['route-01']['unlocked']&&!$p['levels']['route-02']['unlocked'],'initial unlock');
$db->best=array(array('level'=>'route-01','best'=>850));
$p=Controller::progress(); verify_board($p['stars']===2&&$p['levels']['route-02']['unlocked']&&!$p['levels']['route-03']['unlocked'],'persistent unlock');
$db->failure=true; verify_board(Controller::start(new WP_REST_Request(''))->data['status']===500,'insert failure'); $db->failure=false;
$start=Controller::start(new WP_REST_Request('')); verify_board(strlen($start['token'])===48,'server token');
$input=array('token'=>$start['token'],'revision'=>0,'requestId'=>'warehouse-request-1','move'=>array('type'=>'load','to'=>'stack'));
$request=new WP_REST_Request('',$input);
$db->failure=true; verify_board(Controller::update($request)->data['status']===500,'write failure'); $db->failure=false;
$db->race=true; verify_board(Controller::update($request)->data['status']===409,'race'); $db->race=false;
$saved=Controller::update($request); $repeat=Controller::update($request);
verify_board($saved===$repeat&&$db->writes===1&&$saved['moves']===1,'retry idempotent');
verify_board(Controller::update(new WP_REST_Request('',array_replace($input,array('requestId'=>'warehouse-request-2'))))->data['status']===409,'stale revision');
$db->row['user_id']=8; verify_board(Controller::resume($request)->data['status']===404,'cross user'); $db->row['user_id']=7;
$db->row['content_version']='other'; verify_board(Controller::resume($request)->data['status']===404,'cross version'); $db->row['content_version']=Board::VERSION;
$db->row['expires_at']='2000-01-01 00:00:00'; verify_board(Controller::resume($request)->data['status']===404,'expiry');
$db->recent=10; verify_board(Controller::start(new WP_REST_Request(''))->data['status']===429,'rate limit');
$GLOBALS['caps']=array(); verify_board(!Controller::allowed(),'play capability');
echo "PASS: $checks warehouse mechanics, optimal solutions, progression and API checks (DB doubles).\n";
