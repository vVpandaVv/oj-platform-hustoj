<?php
declare(strict_types=1);
namespace EduGames\Rest\Controllers;
use EduGames\Multiplayer\{Service,Wpdb_Repository,Failure};
use EduGames\Support\Capabilities;
use EduGames\Games\Game_Visibility;

final class Binary_Team_Controller {
    public static function register_routes(string $game='binary-conversion'): void {
        $base=$game==='device-matching'?'/device-team':'/binary-team';$auth=static fn()=>self::allowed($game);
        register_rest_route('edu-games/v1',$base.'/ticket',array('methods'=>\WP_REST_Server::READABLE,'permission_callback'=>$auth,'callback'=>static fn($r)=>self::run('ticket',$r,$game)));
        register_rest_route('edu-games/v1',$base.'/rooms',array(
            array('methods'=>\WP_REST_Server::READABLE,'permission_callback'=>$auth,'callback'=>static fn($r)=>self::run('rooms',$r,$game)),
            array('methods'=>\WP_REST_Server::CREATABLE,'permission_callback'=>$auth,'callback'=>static fn($r)=>self::run('create',$r,$game)),
        ));
        register_rest_route('edu-games/v1',$base.'/rooms/(?P<code>[A-Za-z2-9]{6})',array(
            array('methods'=>\WP_REST_Server::READABLE,'permission_callback'=>$auth,'callback'=>static fn($r)=>self::run('poll',$r,$game)),
            array('methods'=>\WP_REST_Server::CREATABLE,'permission_callback'=>$auth,'callback'=>static fn($r)=>self::run('command',$r,$game)),
        ));
        register_rest_route('edu-games/v1',$base.'/records',array('methods'=>\WP_REST_Server::READABLE,'permission_callback'=>$auth,'callback'=>static fn($r)=>self::run('records',$r,$game)));
        register_rest_route('edu-games/v1',$base.'/records/(?P<match>[A-Za-z2-9]{6}-\d{1,6})',array('methods'=>\WP_REST_Server::READABLE,'permission_callback'=>$auth,'callback'=>static fn($r)=>self::run('result',$r,$game)));
        if($game==='binary-conversion')foreach(array('settings','status') as $action)register_rest_route('edu-games/v1',$base.'/realtime/'.$action,array(
            'methods'=>$action==='settings'?'GET,POST':\WP_REST_Server::READABLE,
            'permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>static fn($r)=>self::realtime($action,$r)));
    }
    public static function realtime(string $action,\WP_REST_Request $request): mixed {
        try{
            if($action==='status')$data=\EduGames\Multiplayer\Realtime_Settings::status();
            else{
                if($request->get_method()==='POST'){
                    $input=$request->get_json_params();if(!is_array($input))throw new Failure('설정을 확인하세요.',422);
                    \EduGames\Multiplayer\Realtime_Settings::save($input);
                }
                $data=\EduGames\Multiplayer\Realtime_Settings::all()+array('installCode'=>\EduGames\Multiplayer\Realtime_Settings::install_code());
            }
            $response=new \WP_REST_Response($data);$response->header('Cache-Control','no-store, private');return $response;
        }catch(Failure $e){return new \WP_Error('edu_binary_realtime',$e->getMessage(),array('status'=>$e->status));}
    }
    public static function allowed(string $game='binary-conversion'): bool {
        return (Capabilities::can_play()||Capabilities::can_preview_closed())
            && (Game_Visibility::is_open($game)||Capabilities::can_preview_closed());
    }
    public static function run(string $action,\WP_REST_Request $request,string $game='binary-conversion'): mixed {
        $actor=array('id'=>get_current_user_id(),'name'=>sanitize_text_field(wp_get_current_user()->display_name),
            'manage'=>Capabilities::can_manage(),'admin'=>current_user_can('manage_options'));
        $service=new Service(new Wpdb_Repository(),$game);$now=(int)round(microtime(true)*1000);
        try{
            $scope=(string)($request->get_param('role')??'play');
            if(!in_array($scope,array('play','control','board'),true))throw new Failure('화면 권한을 확인하세요.',403);
            if($scope!=='play'){
                if(!\EduGames\Multiplayer\Room_Policy::teacher($actor))throw new Failure('교사 전용 화면입니다.',403);
                if($scope==='board'&&!in_array($action,array('poll','ticket'),true))throw new Failure('현황판은 읽기 전용입니다.',403);
                if(in_array($action,array('poll','command','ticket'),true)){
                    $code=strtoupper((string)($request['code']??$request->get_param('room')));
                    $actor['scope']=$scope;$actor['room']=$code;
                    if($action==='ticket')$service->access($code,$actor,null,$now);
                }
            }
            $body=$request->get_json_params()??array();
            if(!is_array($body)||strlen((string)$request->get_body())>4096)throw new Failure('요청이 너무 크거나 올바르지 않습니다.',422);
            $result=match($action){
                'ticket'=>array('ticket'=>\EduGames\Multiplayer\Ticket::issue($actor,(string)get_option('edu_games_binary_team_secret',''),(int)floor($now/1000),$game)),
                'rooms'=>$service->rooms($actor,$now),
                'create'=>$service->create($actor,$body,$now),
                'poll'=>$service->access((string)$request['code'],$actor,null,$now,(int)$request->get_param('watchTeam')),
                'command'=>$service->access((string)$request['code'],$actor,$body,$now,(int)$request->get_param('watchTeam')),
                'records'=>$service->records($actor,max(1,(int)$request->get_param('page')),$now,(string)($request->get_param('mode')??'all')),
                'result'=>$service->result((string)$request['match'],$actor),
            };
            $response=new \WP_REST_Response($result);$response->header('Cache-Control','no-store, private');return $response;
        }catch(Failure $e){return new \WP_Error('edu_binary_team',$e->getMessage(),array('status'=>$e->status));}
        catch(\Throwable $e){error_log('[Edu Games binary team] '.$e->getMessage());return new \WP_Error('edu_binary_team','경기 서버에 연결하지 못했습니다. 잠시 뒤 다시 연결하세요.',array('status'=>503));}
    }
}
