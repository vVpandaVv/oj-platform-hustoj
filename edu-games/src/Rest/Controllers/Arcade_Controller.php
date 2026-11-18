<?php
declare(strict_types=1);
namespace EduGames\Rest\Controllers;
use EduGames\Arcade\Repository;
use EduGames\Support\Capabilities;
use EduGames\Games\Game_Visibility;
final class Arcade_Controller {
    public static function register_routes(): void {
        foreach(['cipher-vault','dance-bot'] as $game){
            foreach(['start','command','resume','ranking','records'] as $action)register_rest_route('edu-games/v1','/arcade/'.$game.'/'.$action,['methods'=>'POST','permission_callback'=>static fn()=>self::allowed($game),'callback'=>static fn($r)=>self::run($game,$action,$r)]);
            register_rest_route('edu-games/v1','/arcade/'.$game.'/records/(?P<id>[1-9][0-9]*)',['methods'=>'DELETE','permission_callback'=>[Ranking_Records_Controller::class,'allowed'],'callback'=>static fn($r)=>self::remove($game,$r)]);
        }
    }
    public static function allowed(string $game): bool {return Capabilities::can_play()&&Game_Visibility::is_open($game);}
    public static function run(string $game,string $action,\WP_REST_Request $request): mixed {
        try{
            $p=$request->get_json_params();if(!is_array($p)||strlen((string)$request->get_body())>16384)return self::error('요청 형식을 확인하세요.',422);
            $now=(int)round(microtime(true)*1000);$user=get_current_user_id();$difficulty=$p['difficulty']??'standard';
            if(!is_string($difficulty)||!in_array($difficulty,['standard','challenge'],true))return self::error('난이도를 확인하세요.',422);
            if($action==='ranking')return self::response(Repository::ranking($game,$difficulty,$p['page']??1,$p['perPage']??20));
            if($action==='records')return self::response(Repository::records($game,$user,$p['page']??1,$p['perPage']??20));
            if($action==='start')return self::response(self::view(Repository::create($game,$user,$difficulty,$now),$now));
            if(!is_string($p['token']??null))return self::error('도전 번호를 확인하세요.',422);
            $row=Repository::find($game,$user,$p['token']);if(!$row)return self::error('도전을 찾을 수 없습니다.',404);
            $s=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);$engine=Repository::engine($game);
            if($s['status']==='playing'&&(int)$row['expires_ms']<=$now){$s=$engine::apply($s,['type'=>'stop'],$now);$next=Repository::save($row,$s,$now);if(!$next)return self::error('다른 요청이 진행되었습니다. 다시 연결하세요.',409);$row=$next;}
            if($action==='command'){
                $op=$p['requestId']??null;if(!is_string($op)||!preg_match('/^[a-zA-Z0-9_-]{8,64}$/D',$op)||!is_int($p['revision']??null)||!is_array($p['move']??null))return self::error('행동과 요청 번호를 확인하세요.',422);
                if(in_array($op,$s['operations'],true))return self::response(self::view($row,$now));
                if((int)$row['revision']!==$p['revision'])return self::error('다른 요청이 먼저 저장되었습니다. 이어 하기로 갱신하세요.',409);
                $s=$engine::apply($s,$p['move'],$now);$s['operations'][]=$op;$s['operations']=array_slice($s['operations'],-64);$next=Repository::save($row,$s,$now);if(!$next)return self::error('다른 요청이 먼저 저장되었습니다. 이어 하기로 갱신하세요.',409);$row=$next;
            }
            return self::response(self::view($row,$now));
        }catch(\InvalidArgumentException $e){return self::error($e->getMessage(),422);}catch(\Throwable $e){error_log('[Edu Arcade] '.$e->getMessage());return self::error('기록 서버에 연결하지 못했습니다. 같은 도전으로 다시 연결하세요.',503);}
    }
    public static function view(array $row,int $now): array {return Repository::engine($row['game_slug'])::view(json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR),$now)+['token'=>$row['token'],'revision'=>(int)$row['revision']];}
    public static function remove(string $game,\WP_REST_Request $request): mixed {
        $permission=Ranking_Records_Controller::allowed($request);if(is_wp_error($permission))return $permission;
        global $wpdb;$removed=$wpdb->query($wpdb->prepare('DELETE FROM '.Repository::table()." WHERE id=%d AND game_slug=%s AND status<>'playing'",(int)$request['id'],$game));
        if($removed!==1)return self::error($removed===false?'삭제하지 못했습니다.':'삭제할 수 있는 완료 기록이 없습니다.',$removed===false?500:404);
        do_action('edu_games_ranking_record_deleted',['actor'=>get_current_user_id(),'game'=>$game,'store'=>'arcade','recordId'=>(int)$request['id'],'at'=>gmdate('c')]);
        return self::response(['deleted'=>true,'recordId'=>(int)$request['id']]);
    }
    private static function response(array $data): \WP_REST_Response {$r=new \WP_REST_Response($data);$r->header('Cache-Control','no-store, private');return $r;}
    private static function error(string $text,int $status): \WP_Error {return new \WP_Error('edu_games_arcade',$text,['status'=>$status]);}
}
