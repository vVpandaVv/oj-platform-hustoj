<?php
/** Signed room polling/commands with WordPress SHORTINIT; no Arena dependency. */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);echo '{"code":"relay-unavailable"}';exit;}
$raw=file_get_contents('php://input',false,null,0,131073);
if($raw===false||strlen($raw)>131072){http_response_code(413);echo '{"code":"edu_binary_team","message":"요청이 너무 큽니다."}';exit;}
$input=json_decode($raw,true);
if(!is_array($input)){http_response_code(422);echo '{"code":"edu_binary_team","message":"요청 형식이 올바르지 않습니다."}';exit;}
$directory=__DIR__;$load='';
for($i=0;$i<8;$i++){$directory=dirname($directory);if(is_file($directory.'/wp-load.php')){$load=$directory.'/wp-load.php';break;}}
if($load===''){http_response_code(503);echo '{"code":"relay-unavailable"}';exit;}
define('SHORTINIT',true);
require $load;
if(!defined('EDU_GAMES_PATH'))define('EDU_GAMES_PATH',__DIR__.'/');
require_once EDU_GAMES_PATH.'src/Core/Autoloader.php';
\EduGames\Core\Autoloader::register();
try{
    $plugin=basename(__DIR__).'/edu-games.php';
    $active=in_array($plugin,(array)get_option('active_plugins',array()),true);
    if(!$active&&function_exists('get_site_option'))$active=isset(get_site_option('active_sitewide_plugins',array())[$plugin]);
    if(!$active)throw new \EduGames\Multiplayer\Failure('교육 게임이 비활성화되어 있습니다.',403);
    $secret=(string)get_option('edu_games_binary_team_secret','');
    if(!empty($input['sync'])){
        if(empty(get_option('edu_games_realtime',array())['enabled']))throw new \EduGames\Multiplayer\Failure('WebSocket 연동이 꺼져 있습니다.',503);
        \EduGames\Multiplayer\Socket_Relay::verify($raw,(string)($_SERVER['HTTP_X_OJ_REALTIME_TIME']??''),(string)($_SERVER['HTTP_X_OJ_REALTIME_SIGNATURE']??''),$secret,time());
        $game=\EduGames\Multiplayer\Socket_Relay::game($input['channel']??'');
        if(in_array($game,(array)get_option('edu_games_closed_games',array()),true))throw new \EduGames\Multiplayer\Failure('이 게임은 현재 공개되지 않았습니다.',403);
        echo json_encode(\EduGames\Multiplayer\Socket_Relay::handle(new \EduGames\Multiplayer\Wpdb_Repository(),$input,$secret,(int)round(microtime(true)*1000)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        exit;
    }
    if(strlen($raw)>8192)throw new \EduGames\Multiplayer\Failure('요청이 너무 큽니다.',413);
    if(!is_string($input['ticket']??null))throw new \EduGames\Multiplayer\Failure('입장권을 확인하세요.',401);
    $game=$input['game']??\EduGames\Multiplayer\Room_Rules::BINARY;if(!is_string($game))throw new \EduGames\Multiplayer\Failure('팀전 종류를 확인하세요.',422);
    $actor=\EduGames\Multiplayer\Ticket::read($input['ticket'],$secret,time(),$game);
    if(in_array($game,(array)get_option('edu_games_closed_games',array()),true)&&!$actor['manage']&&!$actor['admin'])throw new \EduGames\Multiplayer\Failure('이 게임은 현재 공개되지 않았습니다.',403);
    $data=\EduGames\Multiplayer\Relay::handle(new \EduGames\Multiplayer\Wpdb_Repository(),$input,$secret,(int)round(microtime(true)*1000));
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(\EduGames\Multiplayer\Failure $e){http_response_code($e->status);echo json_encode(array('code'=>'edu_binary_team','message'=>$e->getMessage()),JSON_UNESCAPED_UNICODE);}
catch(\Throwable $e){error_log('[Edu Games binary relay] '.$e->getMessage());http_response_code(503);echo '{"code":"edu_binary_team","message":"경기 서버에 연결하지 못했습니다."}';}
