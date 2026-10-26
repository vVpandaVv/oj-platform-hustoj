<?php
declare(strict_types=1);
namespace EduGames\Rest\Controllers;

use EduGames\Games\Modules\Data_Structure_Lab\Warehouse_Board as Board;
use EduGames\Learning\Installer;
use EduGames\Games\Game_Visibility;
use EduGames\Support\Capabilities;

final class Warehouse_Controller {
    public static function register_routes(): void {
        foreach (array('start','update','resume','summary') as $action) {
            register_rest_route('edu-games/v1','/warehouse/'.$action,array('methods'=>'POST','callback'=>array(__CLASS__,$action),'permission_callback'=>array(__CLASS__,'allowed')));
        }
    }
    public static function allowed(): bool { return Capabilities::can_play() && Game_Visibility::is_open(Board::GAME); }
    private static function error(string $message,int $status=400): \WP_Error { return new \WP_Error('edu_games_warehouse',$message,array('status'=>$status)); }
    private static function input(\WP_REST_Request $request): array { $p=$request->get_json_params(); return is_array($p)?$p:array(); }

    public static function progress(): array|\WP_Error {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT level, MAX(score) AS best FROM '.Installer::table()." WHERE user_id=%d AND game_slug=%s AND content_version=%s AND mode='board' AND completed_at IS NOT NULL GROUP BY level",get_current_user_id(),Board::GAME,Board::VERSION),ARRAY_A);
        if (!is_array($rows)) { return self::error('구역 기록을 불러오지 못했습니다.',500); }
        $best=array_column($rows,'best','level'); $levels=array(); $unlocked=true; $stars=0;
        foreach (Board::levels() as $id=>$definition) {
            $score=(int)($best[$id]??0); $count=Board::stars($score); $stars+=$count;
            $levels[$id]=array('id'=>$id,'title'=>$definition['title'],'number'=>$definition['number'],'score'=>$score,'stars'=>$count,'unlocked'=>$unlocked);
            $unlocked=$unlocked&&$score>0;
        }
        return array('levels'=>$levels,'stars'=>$stars,'maxStars'=>count($levels)*3);
    }

    public static function start(\WP_REST_Request $request) {
        $input=self::input($request); $level=$input['level']??'route-01';
        if (!is_string($level)||!isset(Board::levels()[$level])) { return self::error('구역을 확인하세요.'); }
        $progress=self::progress();
        if (is_wp_error($progress)) { return $progress; }
        if (!$progress['levels'][$level]['unlocked']) { return self::error('이전 구역을 먼저 완료하세요.',403); }
        global $wpdb; $table=Installer::table(); $user=get_current_user_id();
        $recent=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id=%d AND started_at>%s",$user,gmdate('Y-m-d H:i:s',time()-60)));
        if ($recent>=10) { return self::error('잠시 후 새 도전을 시작하세요.',429); }
        $state=Board::create($level); $token=bin2hex(random_bytes(24));
        $ok=$wpdb->insert($table,array('token'=>$token,'user_id'=>$user,'game_slug'=>Board::GAME,'content_version'=>Board::VERSION,'mode'=>'board','level'=>$level,'revision'=>0,'state_json'=>wp_json_encode($state),'started_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>gmdate('Y-m-d H:i:s',time()+7200)),array('%s','%d','%s','%s','%s','%s','%d','%s','%s','%s'));
        if (false===$ok) { return self::error('도전을 저장하지 못했습니다.',500); }
        return rest_ensure_response(Board::view($state)+array('token'=>$token,'revision'=>0));
    }

    private static function find(\WP_REST_Request $request): ?array {
        $token=self::input($request)['token']??null;
        if (!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token)) { return null; }
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Installer::table()." WHERE token=%s AND user_id=%d AND game_slug=%s AND content_version=%s AND mode='board' AND expires_at>%s",$token,get_current_user_id(),Board::GAME,Board::VERSION,gmdate('Y-m-d H:i:s')),ARRAY_A)?:null;
    }
    private static function view(array $row): array {
        return Board::view(json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR))+array('token'=>$row['token'],'revision'=>(int)$row['revision']);
    }
    public static function resume(\WP_REST_Request $request) {
        $row=self::find($request);
        return $row?rest_ensure_response(self::view($row)):self::error('도전이 만료되었거나 접근할 수 없습니다.',404);
    }
    public static function update(\WP_REST_Request $request) {
        $row=self::find($request);
        if (!$row) { return self::error('도전이 만료되었거나 접근할 수 없습니다.',404); }
        $input=self::input($request); $requestId=$input['requestId']??null;
        if (!is_string($requestId)||!preg_match('/^[a-zA-Z0-9-]{8,64}$/D',$requestId)) { return self::error('요청 식별자가 필요합니다.'); }
        $state=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);
        if (($state['lastRequest']??null)===$requestId) { return rest_ensure_response(self::view($row)); }
        if (!is_int($input['revision']??null)||$input['revision']!==(int)$row['revision']) { return self::error('다른 창에서 진행되었습니다. 이어 하기로 갱신하세요.',409); }
        if (!is_array($input['move']??null)) { return self::error('이동을 선택하세요.'); }
        try { $state=Board::apply($state,$input['move']); } catch (\InvalidArgumentException $e) { return self::error($e->getMessage()); }
        $state['lastRequest']=$requestId; $done=$state['status']!=='playing';
        global $wpdb; $table=Installer::table();
        $args=array_merge(array(wp_json_encode($state),Board::score($state)),$done?array(gmdate('Y-m-d H:i:s')):array(),array((int)$row['id'],(int)$row['revision'],get_current_user_id()));
        $updated=$wpdb->query($wpdb->prepare("UPDATE {$table} SET state_json=%s, revision=revision+1, score=%f, completed_at=".($done?'%s':'NULL')." WHERE id=%d AND revision=%d AND user_id=%d",...$args));
        if (false===$updated) { return self::error('저장하지 못했습니다. 같은 이동을 다시 시도하세요.',500); }
        if (1!==$updated) { return self::error('다른 요청이 먼저 저장되었습니다. 이어 하기로 갱신하세요.',409); }
        $row['state_json']=wp_json_encode($state); $row['revision']=(int)$row['revision']+1;
        return rest_ensure_response(self::view($row));
    }
    public static function summary(\WP_REST_Request $request) {
        $level=self::input($request)['level']??'route-01';
        if (!is_string($level)||!isset(Board::levels()[$level])) { return self::error('구역을 확인하세요.'); }
        $progress=self::progress();
        if (is_wp_error($progress)) { return $progress; }
        global $wpdb;
        $p=self::input($request);
        if(isset($p['page'])) {
            $result=\EduGames\Rankings\Run_Leaderboard::page(Board::GAME,Board::VERSION,'board',$level,$p['page'],$p['perPage']??20);
            return is_wp_error($result)?$result:rest_ensure_response($progress+$result+array('level'=>$level));
        }
        $leaders=$wpdb->get_results($wpdb->prepare('SELECT user_id, MAX(score) AS score FROM '.Installer::table()." WHERE game_slug=%s AND content_version=%s AND mode='board' AND level=%s AND completed_at IS NOT NULL GROUP BY user_id ORDER BY score DESC, user_id ASC",Board::GAME,Board::VERSION,$level),ARRAY_A);
        if (!is_array($leaders)) { return self::error('순위를 불러오지 못했습니다.',500); }
        $previous=null; $rank=0;
        foreach ($leaders as $i=>&$row) {
            $row['score']=(int)$row['score'];
            if ($row['score']!==$previous) { $rank=$i+1; }
            $previous=$row['score']; $row['rank']=$rank;
            $row['name']=(int)$row['user_id']===get_current_user_id()?'나':'도전자'; unset($row['user_id']);
        }
        unset($row);
        return rest_ensure_response($progress+array('leaderboard'=>$leaders,'level'=>$level,'version'=>Board::VERSION));
    }
}
