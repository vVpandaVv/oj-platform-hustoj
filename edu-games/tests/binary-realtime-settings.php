<?php
declare(strict_types=1);
define('EDU_GAMES_FILE',dirname(__DIR__).'/edu-games.php');
define('EDU_GAMES_PATH',dirname(__DIR__).'/');
require dirname(__DIR__).'/src/Core/Autoloader.php';
\EduGames\Core\Autoloader::register();
use EduGames\Multiplayer\{Realtime_Settings as Settings,Socket_Relay,Failure};
$options=array('edu_games_binary_team_secret'=>str_repeat('secret-1234',5));$routes=array();$caps=array();$response=array();$checks=0;
function get_option($key,$default=false){global $options;return $options[$key]??$default;}
function update_option($key,$value,$autoload=null){global $options;$options[$key]=$value;}
function home_url(){return 'https://school.test';}
function get_current_user_id(){return 100;}
function do_action(...$args){}
function plugins_url($path,$file){return 'https://school.test/wp-content/plugins/edu-games/'.$path;}
function wp_json_encode($value){return json_encode($value);}
function current_user_can($cap){global $caps;return in_array($cap,$caps,true);}
function register_rest_route($ns,$path,$args){global $routes;$routes[$path]=$args;}
class WP_REST_Server {public const READABLE='GET',CREATABLE='POST';}
function wp_remote_get($url,$args){
    global $response;
    if($url!=='http://127.0.0.1:3210/edu-games-live/status'||$args['redirection']!==0)throw new RuntimeException('Unexpected server address');
    $headers=$args['headers'];$expected=Socket_Relay::signature('GET','/status',$headers['X-OJ-Realtime-Time'],'',get_option('edu_games_binary_team_secret'));
    if(!hash_equals($expected,$headers['X-OJ-Realtime-Signature']))throw new RuntimeException('Unsigned server status');
    return $response;
}
function is_wp_error($value){return false;}
function wp_remote_retrieve_body($value){return json_encode($value['body']);}
function wp_remote_retrieve_response_code($value){return $value['status'];}
function check($ok,$message){global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function rejected($fn,$status){try{$fn();}catch(Failure $e){check($e->status===$status,'expected rejection');return;}throw new RuntimeException('Expected failure');}
check(Settings::url()==='','WebSocket disabled by default');
check(Settings::all()['public_url']==='wss://school.test/edu-games-live','same-site default');
Settings::save(array('enabled'=>true,'public_url'=>'wss://school.test/edu-games-live'));
check(Settings::url()==='wss://school.test/edu-games-live','saved public URL');
check(Settings::api_url()==='http://127.0.0.1:3210/edu-games-live','shared internal listener');
foreach(array('wss://other.test/edu-games-live','ws://school.test/edu-games-live','wss://user@school.test/edu-games-live','wss://school.test/edu-games-live?x=1','wss://school.test:3210/edu-games-live') as $url)rejected(fn()=>Settings::save(array('public_url'=>$url)),422);
$code=Settings::install_code();check(str_starts_with($code,'ojhub1.'),'shared installer format');
$config=json_decode(base64_decode(strtr(substr($code,7),'-_','+/')),true);
check($config['app']==='edu-games'&&$config['env']['EDU_GAMES_SECRET']===get_option('edu_games_binary_team_secret'),'independent app secret');
check($config['env']['WS_PATH']==='/edu-games-live'&&$config['env']['HOST']==='127.0.0.1'&&$config['env']['PORT']==='3210','existing server namespace');
check($config['env']['WP_API']==='https://school.test/wp-content/plugins/edu-games/binary-relay.php','server callback');
$response=array('status'=>200,'body'=>array('ok'=>true,'app'=>'edu-games','protocol'=>1,'service'=>'oj-realtime-hub','authenticated'=>true,'transport'=>'websocket','version'=>'1.0.1','connections'=>4,'rooms'=>1));
check(Settings::status()['connections']===4,'authenticated status response');
$response['body']['app']='oj-code-arena';rejected(fn()=>Settings::status(),503);
\EduGames\Rest\Controllers\Binary_Team_Controller::register_routes();
foreach(array('settings','status') as $action){
    $permission=$routes['/binary-team/realtime/'.$action]['permission_callback'];
    $caps=array('edu_games_play','edu_games_manage');check(!$permission(),'students and regular teachers cannot access secrets');
    $caps=array('manage_options');check($permission(),'site admin can manage realtime');
}
echo "PASS: {$checks} realtime settings, installation code, status authentication and permission checks.\n";
