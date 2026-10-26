<?php
declare(strict_types=1);
namespace EduGames\Rest\Controllers;

use EduGames\Learning\Catalog;
use EduGames\Learning\Installer;
use EduGames\Learning\Run;
use EduGames\Games\Game_Visibility;
use EduGames\Support\Capabilities;

final class Learning_Controller {
    public static function register_routes(): void {
        foreach(array('start','update','resume','summary') as $action){
            register_rest_route('edu-games/v1','/learning/(?P<game>[a-z0-9-]+)/'.$action,array('methods'=>'POST','callback'=>array(__CLASS__,$action),'permission_callback'=>array(__CLASS__,'allowed')));
        }
    }
    public static function allowed(\WP_REST_Request $request): bool {
        return Capabilities::can_play()&&isset(Catalog::PROVIDERS[(string)$request['game']])&&Game_Visibility::is_open((string)$request['game']);
    }
    private static function error(string $message,int $status=400): \WP_Error {return new \WP_Error('edu_games_learning',$message,array('status'=>$status));}
    private static function input(\WP_REST_Request $r): array {$p=$r->get_json_params();return is_array($p)?$p:array();}

    public static function start(\WP_REST_Request $request) {
        $p=self::input($request);$mode=$p['mode']??'practice';$level=$p['level']??'all';$game=(string)$request['game'];
        if(!in_array($mode,array('guided','practice','assessment'),true)||!in_array($level,array('all','easy','normal','hard'),true)){return self::error('모드나 난이도를 확인하세요.');}
        try{$state=Run::create($game,$level);}catch(\InvalidArgumentException $e){return self::error($e->getMessage());}
        global $wpdb;$table=Installer::table();$user=get_current_user_id();
        $recent=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id=%d AND started_at>%s",$user,gmdate('Y-m-d H:i:s',time()-60)));
        if($recent>=10){return self::error('새 세트를 너무 자주 시작했습니다. 잠시 후 다시 시도하세요.',429);}
        $token=bin2hex(random_bytes(24));
        $ok=$wpdb->insert($table,array('token'=>$token,'user_id'=>$user,'game_slug'=>$game,'content_version'=>Catalog::VERSION,'mode'=>$mode,'level'=>$level,'revision'=>0,'state_json'=>wp_json_encode($state),'started_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>gmdate('Y-m-d H:i:s',time()+7200)),array('%s','%d','%s','%s','%s','%s','%d','%s','%s','%s'));
        if(false===$ok){return self::error('학습 기록을 시작하지 못했습니다.',500);}
        return rest_ensure_response(array_merge(Run::view($state,$game,$mode),array('token'=>$token,'revision'=>0,'level'=>$level)));
    }

    private static function find(\WP_REST_Request $request): ?array {
        $p=self::input($request);$token=$p['token']??null;if(!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token)){return null;}
        global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Installer::table().' WHERE token=%s AND user_id=%d AND game_slug=%s AND content_version=%s AND expires_at>%s',$token,get_current_user_id(),(string)$request['game'],Catalog::VERSION,gmdate('Y-m-d H:i:s')),ARRAY_A)?:null;
    }

    public static function resume(\WP_REST_Request $request) {
        $row=self::find($request);if(null===$row){return self::error('세션이 만료되었거나 접근할 수 없습니다.',404);}
        return rest_ensure_response(self::view($row));
    }

    private static function view(array $row): array {
        $state=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);
        return array_merge(Run::view($state,$row['game_slug'],$row['mode']),array('token'=>$row['token'],'revision'=>(int)$row['revision'],'level'=>$row['level']));
    }

    public static function update(\WP_REST_Request $request) {
        $row=self::find($request);if(null===$row){return self::error('세션이 만료되었거나 접근할 수 없습니다.',404);}
        $input=self::input($request);$request_id=$input['requestId']??null;
        if(!is_string($request_id)||!preg_match('/^[a-zA-Z0-9-]{8,64}$/D',$request_id)){return self::error('요청 식별자가 필요합니다.');}
        $state=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);
        if(($state['lastRequest']??null)===$request_id){return rest_ensure_response(self::view($row));}
        if(!is_int($input['revision']??null)||$input['revision']!==(int)$row['revision']){return self::error('다른 창에서 진행되었습니다. 현재 상태를 다시 불러오세요.',409);}
        try{$state=Run::apply($state,$row['game_slug'],$row['mode'],$input);}catch(\InvalidArgumentException $e){return self::error($e->getMessage());}
        $state['lastRequest']=$request_id;$summary=Run::summary($state);$done=$state['index']>=count($state['ids']);
        global $wpdb;$table=Installer::table();
        $updated=$wpdb->query($wpdb->prepare("UPDATE {$table} SET state_json=%s, revision=revision+1, score=%f, completed_at=".($done?'%s':'NULL')." WHERE id=%d AND revision=%d AND user_id=%d",...array_merge(array(wp_json_encode($state),$summary['score']),$done?array(gmdate('Y-m-d H:i:s')):array(),array((int)$row['id'],(int)$row['revision'],get_current_user_id()))));
        if(false===$updated){return self::error('저장하지 못했습니다. 답안을 유지하고 다시 제출하세요.',500);}
        if(1!==$updated){return self::error('다른 요청이 먼저 저장되었습니다. 현재 상태를 다시 불러오세요.',409);}
        $row['state_json']=wp_json_encode($state);$row['revision']=(int)$row['revision']+1;
        return rest_ensure_response(self::view($row));
    }

    public static function summary(\WP_REST_Request $request) {
        global $wpdb;$table=Installer::table();$game=(string)$request['game'];$level=self::input($request)['level']??'all';
        if(!in_array($level,array('all','easy','normal','hard'),true)){return self::error('난이도를 확인하세요.');}
        $history=$wpdb->get_results($wpdb->prepare("SELECT mode, level, score, started_at, completed_at, state_json FROM {$table} WHERE user_id=%d AND game_slug=%s AND content_version=%s ORDER BY id DESC LIMIT 12",get_current_user_id(),$game,Catalog::VERSION),ARRAY_A);
        if(!is_array($history)) return self::error('학습 기록을 불러오지 못했습니다.',500);
        foreach($history as &$row){$row['summary']=Run::summary(json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR));unset($row['state_json']);}unset($row);
        $p=self::input($request);
        if(isset($p['page'])) {
            $result=\EduGames\Rankings\Run_Leaderboard::page($game,Catalog::VERSION,'assessment',$level,$p['page'],$p['perPage']??20);
            return is_wp_error($result)?$result:rest_ensure_response($result+array('history'=>$history,'version'=>Catalog::VERSION,'level'=>$level));
        }
        $leaders=$wpdb->get_results($wpdb->prepare("SELECT user_id, MAX(score) AS score FROM {$table} WHERE game_slug=%s AND content_version=%s AND mode='assessment' AND level=%s AND completed_at IS NOT NULL GROUP BY user_id ORDER BY score DESC, user_id ASC",$game,Catalog::VERSION,$level),ARRAY_A);
        foreach($leaders as &$row){$row['name']=(int)$row['user_id']===get_current_user_id()?'나':'학습자 '.$row['user_id'];unset($row['user_id']);}unset($row);
        return rest_ensure_response(array('history'=>$history,'leaderboard'=>$leaders,'version'=>Catalog::VERSION,'level'=>$level));
    }
}
