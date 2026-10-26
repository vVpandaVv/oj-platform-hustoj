<?php
declare(strict_types=1);
namespace EduGames\Rest\Controllers;
use EduGames\Progress\Store;
use EduGames\Progress\Validator;
use EduGames\Support\Capabilities;
use EduGames\Games\Game_Visibility;

final class Progress_Controller {
    public const GAMES=array('binary-conversion','character-code','keyword-rain','image-data','code-typing','device-matching');
    public static function register_routes(): void {
        register_rest_route('edu-games/v1','/progress/(?P<game>[a-z-]+)',array('methods'=>'GET, POST','callback'=>array(__CLASS__,'handle'),'permission_callback'=>array(__CLASS__,'allowed')));
    }
    public static function allowed(\WP_REST_Request $r): bool { return Capabilities::can_play()&&in_array($r['game'],self::GAMES,true)&&Game_Visibility::is_open((string)$r['game']); }
    public static function handle(\WP_REST_Request $r) {
        $game=(string)$r['game'];$user=get_current_user_id();
        $expected=$r->get_param('accountUserId');
        if(!is_scalar($expected)||(string)$expected!==(string)$user) return new \WP_Error('edu_games_account_changed','로그인 계정이 변경되었습니다. 페이지를 새로 열어 주세요.',array('status'=>403));
        if($r->get_method()==='POST') {
            $p=$r->get_json_params();
            if(!is_array($p)) return new \WP_Error('edu_games_progress_input','기록을 확인하세요.',array('status'=>400));
            try { $entries=Validator::entries($game,$p); } catch(\InvalidArgumentException $e) { return new \WP_Error('edu_games_progress_input',$e->getMessage(),array('status'=>400)); }
            foreach($entries as $key=>$value) if(!Store::put($user,$game,$key,$value))return Store::error();
        }
        $result=Store::read($user,$game);return is_wp_error($result)?$result:rest_ensure_response($result);
    }
}
