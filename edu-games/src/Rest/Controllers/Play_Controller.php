<?php
declare(strict_types=1);
namespace EduGames\Rest\Controllers;
use EduGames\Play\Catalog;
use EduGames\Play\Board as Rules;
use EduGames\Learning\Installer;
use EduGames\Support\Capabilities;
use EduGames\Games\Game_Visibility;

final class Play_Controller {
    public static function register_routes(): void {
        foreach(array('start','update','resume','summary','archive') as $action)register_rest_route('edu-games/v1','/play/(?P<game>[a-z-]+)/'.$action,array('methods'=>'POST','callback'=>array(__CLASS__,$action),'permission_callback'=>array(__CLASS__,'allowed')));
    }
    public static function allowed(\WP_REST_Request $r): bool {return is_string($r['game'])&&isset(Catalog::GAMES[$r['game']])&&Capabilities::can_play()&&Game_Visibility::is_open($r['game']);}
    private static function error(string $text,int $status=400): \WP_Error {return new \WP_Error('edu_games_play',$text,array('status'=>$status));}
    private static function input(\WP_REST_Request $r): array {$input=$r->get_json_params();return is_array($input)?$input:array();}
    private static function game(\WP_REST_Request $r): string {return is_string($r['game'])&&isset(Catalog::GAMES[$r['game']])?$r['game']:'';}
    public static function archive(\WP_REST_Request $r) {
        $game=self::game($r);if(!$game)return self::error('게임을 확인하세요.');global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT content_version AS version, level, MAX(score) AS score, COUNT(*) AS attempts FROM '.Installer::table()." WHERE user_id=%d AND game_slug=%s AND (mode<>'play' OR content_version<>%s) AND completed_at IS NOT NULL GROUP BY content_version, level ORDER BY content_version DESC, level ASC LIMIT 100",get_current_user_id(),$game,Catalog::version($game)),ARRAY_A);
        return is_array($rows)?rest_ensure_response(array('records'=>$rows)):self::error('이전 시즌 기록을 읽지 못했습니다.',500);
    }
    public static function progress(string $game): array|\WP_Error {
        if(!isset(Catalog::GAMES[$game]))return self::error('게임을 확인하세요.');
        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT level, MAX(score) AS best FROM '.Installer::table()." WHERE user_id=%d AND game_slug=%s AND content_version=%s AND mode='play' AND completed_at IS NOT NULL GROUP BY level",get_current_user_id(),$game,Catalog::version($game)),ARRAY_A);
        if(!is_array($rows))return self::error('보드 기록을 불러오지 못했습니다.',500);
        $best=array_column($rows,'best','level');$levels=array();$number=0;$class=Catalog::game($game);
        foreach($class::levels() as $id=>$d)$levels[$id]=array('id'=>$id,'number'=>++$number,'title'=>$d['title'],'score'=>(int)($best[$id]??0),'unlocked'=>true);
        return array('levels'=>$levels,'game'=>$game,'version'=>Catalog::version($game));
    }
    public static function start(\WP_REST_Request $r) {
        $game=self::game($r);if(!$game)return self::error('게임을 확인하세요.');$class=Catalog::game($game);$level=self::input($r)['level']??'board-01';
        $version=self::input($r)['version']??null;if($version!==null&&$version!==Catalog::version($game))return self::error('오늘의 데이터가 바뀌었습니다. 페이지를 새로고침하세요.',409);
        if(!is_string($level)||!isset($class::levels()[$level]))return self::error('보드를 확인하세요.');
        $progress=self::progress($game);if(is_wp_error($progress))return $progress;if(!$progress['levels'][$level]['unlocked'])return self::error('이전 보드를 먼저 완료하세요.',403);
        global $wpdb;$table=Installer::table();$user=get_current_user_id();$recent=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id=%d AND started_at>%s",$user,gmdate('Y-m-d H:i:s',time()-60)));
        if($recent===null)return self::error('도전 기록을 확인하지 못했습니다.',500);if((int)$recent>=10)return self::error('잠시 후 새 도전을 시작하세요.',429);
        $state=$class::create($level);$token=bin2hex(random_bytes(24));
        $ok=$wpdb->insert($table,array('token'=>$token,'user_id'=>$user,'game_slug'=>$game,'content_version'=>Catalog::version($game),'mode'=>'play','level'=>$level,'revision'=>0,'state_json'=>wp_json_encode($state),'started_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>gmdate('Y-m-d H:i:s',time()+7200)),array('%s','%d','%s','%s','%s','%s','%d','%s','%s','%s'));
        if(false===$ok)return self::error('새 도전을 저장하지 못했습니다.',500);
        return rest_ensure_response($class::view($state)+array('token'=>$token,'revision'=>0));
    }
    private static function find(\WP_REST_Request $r): array|\WP_Error|null {
        $game=self::game($r);$token=self::input($r)['token']??null;if(!$game||!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token))return null;
        global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Installer::table()." WHERE token=%s AND user_id=%d AND game_slug=%s AND content_version IN (%s,%s) AND mode='play' AND expires_at>%s",$token,get_current_user_id(),$game,Catalog::version($game),Catalog::base_version($game).'-'.gmdate('Y-m-d',time()-86400),gmdate('Y-m-d H:i:s')),ARRAY_A);
        if(!empty($wpdb->last_error))return self::error('진행 기록을 읽지 못했습니다. 잠시 후 이어 하기를 다시 시도하세요.',500);
        return $row?:null;
    }
    private static function view(array $row): array {$class=Catalog::game($row['game_slug']);return $class::view(json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR))+array('token'=>$row['token'],'revision'=>(int)$row['revision']);}
    public static function resume(\WP_REST_Request $r) {$row=self::find($r);if(is_wp_error($row))return $row;return $row?rest_ensure_response(self::view($row)):self::error('도전이 만료되었거나 접근할 수 없습니다.',404);}
    public static function update(\WP_REST_Request $r) {
        $row=self::find($r);if(is_wp_error($row))return $row;if(!$row)return self::error('도전이 만료되었거나 접근할 수 없습니다.',404);$p=self::input($r);$id=$p['requestId']??null;
        if(!is_string($id)||!preg_match('/^[a-zA-Z0-9-]{8,64}$/D',$id))return self::error('요청 식별자가 필요합니다.');
        $state=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);if(($state['lastRequest']??null)===$id)return rest_ensure_response(self::view($row));
        if(!is_int($p['revision']??null)||$p['revision']!==(int)$row['revision'])return self::error('다른 창에서 진행되었습니다. 이어 하기로 갱신하세요.',409);
        if(!is_array($p['move']??null))return self::error('행동을 선택하세요.');$class=Catalog::game($row['game_slug']);
        try{$state=$class::apply($state,$p['move']);}catch(\InvalidArgumentException $e){return self::error($e->getMessage());}
        $state['lastRequest']=$id;$done=$state['status']!=='playing';global $wpdb;$table=Installer::table();
        $args=array_merge(array(wp_json_encode($state),Rules::score($state)),$done?array(gmdate('Y-m-d H:i:s')):array(),array((int)$row['id'],(int)$row['revision'],get_current_user_id()));
        $updated=$wpdb->query($wpdb->prepare("UPDATE {$table} SET state_json=%s, revision=revision+1, score=%f, completed_at=".($done?'%s':'NULL')." WHERE id=%d AND revision=%d AND user_id=%d",...$args));
        if(false===$updated)return self::error('저장하지 못했습니다. 같은 행동을 다시 보내세요.',500);if($updated!==1)return self::error('다른 요청이 먼저 저장되었습니다. 이어 하기로 갱신하세요.',409);
        $row['state_json']=wp_json_encode($state);$row['revision']=(int)$row['revision']+1;return rest_ensure_response(self::view($row));
    }
    public static function summary(\WP_REST_Request $r) {
        $game=self::game($r);if(!$game)return self::error('게임을 확인하세요.');$class=Catalog::game($game);$level=self::input($r)['level']??'board-01';if(!is_string($level)||!isset($class::levels()[$level]))return self::error('보드를 확인하세요.');
        $progress=self::progress($game);if(is_wp_error($progress))return $progress;global $wpdb;
        $p=self::input($r);
        if(isset($p['page'])) {
            $result=\EduGames\Rankings\Run_Leaderboard::page($game,Catalog::version($game),'play',$level,$p['page'],$p['perPage']??20);
            return is_wp_error($result)?$result:rest_ensure_response($progress+$result+array('level'=>$level));
        }
        $rows=$wpdb->get_results($wpdb->prepare('SELECT user_id, MAX(score) AS score FROM '.Installer::table()." WHERE game_slug=%s AND content_version=%s AND mode='play' AND level=%s AND completed_at IS NOT NULL GROUP BY user_id ORDER BY score DESC, user_id ASC",$game,Catalog::version($game),$level),ARRAY_A);
        if(!is_array($rows))return self::error('순위를 불러오지 못했습니다.',500);$previous=null;$rank=0;foreach($rows as $i=>&$row){$row['score']=(int)$row['score'];if($row['score']!==$previous)$rank=$i+1;$previous=$row['score'];$row['rank']=$rank;$row['name']=(int)$row['user_id']===get_current_user_id()?'나':'도전자';unset($row['user_id']);}unset($row);
        return rest_ensure_response($progress+array('level'=>$level,'leaderboard'=>$rows));
    }
}
