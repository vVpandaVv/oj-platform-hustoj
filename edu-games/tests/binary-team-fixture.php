<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/binary-team-support.php';
use EduGames\Multiplayer\{Service,Failure};
use EduGames\Tests\Binary_Memory_Repository;
define('EDU_GAMES_VERSION',(require EDU_GAMES_PATH.'plugin-package.php')['version']);
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_html($v){return esc_attr($v);}
function esc_url($v){return esc_attr($v);}
function esc_url_raw($v){return $v;}
function rest_url($v){return '/'.$v;}
function wp_create_nonce($v){return 'fixture-nonce';}
function wp_enqueue_style(...$args){}
function wp_enqueue_script(...$args){}
function wp_get_current_user(){return (object)array('display_name'=>'학생 '.get_current_user_id());}
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$uid=(int)($input['uid']??7);$GLOBALS['test_user_id']=$uid;
$game=$input['game']??'binary-conversion';
$GLOBALS['caps']=array('edu_games_play');if($uid===100)$GLOBALS['caps'][]='edu_games_manage';
if($input['action']==='html'){
    if(!empty($input['socketUrl']))$GLOBALS['options']['edu_games_realtime']=array('enabled'=>true,'public_url'=>$input['socketUrl']);
    echo \EduGames\Blocks\Binary_Team_Renderer::render_game($game,false,$input['screen']??'student',$input['room']??'');exit;
}
$db=new Binary_Memory_Repository();$db->rooms=$input['db']['rooms']??array();$db->results=$input['db']['results']??array();
$service=new Service($db,$game);$actor=array('id'=>$uid,'name'=>$uid===100?'진행 교사':'학생 '.$uid,'manage'=>$uid===100,'admin'=>false);$now=(int)$input['now'];$body=$input['body']??array();
try{
    $scope=$input['role']??'play';
    if($scope!=='play'){
        if($uid!==100)throw new Failure('교사 전용 화면입니다.',403);
        if($scope==='board'&&!in_array($input['action'],array('poll','ticket'),true))throw new Failure('현황판은 읽기 전용입니다.',403);
        if(in_array($input['action'],array('ticket','poll','command'),true)){$actor['scope']=$scope;$actor['room']=$input['code']??$input['room']??'';if($input['action']==='ticket')$service->access($actor['room'],$actor,null,$now);}
    }
    $secret='binary-team-test-secret-0123456789';
    if($input['action']==='socket')\EduGames\Multiplayer\Socket_Relay::verify($input['raw'],$input['time'],$input['signature'],$secret,time());
    $data=match($input['action']){
        'setup'=>(static function()use($service,$actor,$body,$now){
            $room=$service->create($actor,$body,$now);$code=$room['code'];$total=$body['teams']*$body['capacity'];
            for($id=1;$id<=$total;$id++)$service->access($code,array('id'=>$id,'name'=>'학생 '.$id,'manage'=>false,'admin'=>false),array('type'=>'join','op'=>'setup-join-'.$id),$now);
            for($id=1;$id<=$total;$id++)$service->access($code,array('id'=>$id,'name'=>'학생 '.$id,'manage'=>false,'admin'=>false),array('type'=>'ready','ready'=>true,'op'=>'setup-ready-'.$id,'round'=>1),$now);
            return $service->access($code,$actor,array('type'=>'start','op'=>'setup-start-room','round'=>1),$now);
        })(),
        'ticket'=>array('ticket'=>\EduGames\Multiplayer\Ticket::issue($actor,$secret,(int)floor($now/1000),$game)),
        'relay'=>\EduGames\Multiplayer\Relay::handle($db,$body,$secret,$now),
        'socket'=>\EduGames\Multiplayer\Socket_Relay::handle($db,$body,$secret,$now),
        'create'=>$service->create($actor,$body,$now),'rooms'=>$service->rooms($actor,$now),
        'command'=>$service->access($input['code'],$actor,$body,$now,(int)($input['watch']??0)),
        'poll'=>$service->access($input['code'],$actor,null,$now,(int)($input['watch']??0)),
        'records'=>$service->records($actor,(int)($input['page']??1),$now,$input['mode']??'all'),'result'=>$service->result($input['id'],$actor),
    };$status=200;
}catch(Failure $e){$data=array('message'=>$e->getMessage());$status=$e->status;}
echo json_encode(array('status'=>$status,'data'=>$data,'db'=>array('rooms'=>$db->rooms,'results'=>$db->results)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
