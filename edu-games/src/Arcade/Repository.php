<?php
declare(strict_types=1);
namespace EduGames\Arcade;
final class Repository {
    public static function table(): string {global $wpdb;return $wpdb->prefix.'edu_games_arcade_runs';}
    public static function install(): bool {
        global $wpdb;if(!function_exists('dbDelta'))require_once ABSPATH.'wp-admin/includes/upgrade.php';$table=self::table();$collate=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token varchar(48) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            game_slug varchar(64) NOT NULL,
            pack varchar(96) NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'playing',
            revision bigint(20) unsigned NOT NULL DEFAULT 0,
            state_json longtext NOT NULL,
            score bigint(20) unsigned NOT NULL DEFAULT 0,
            elapsed_ms bigint(20) unsigned NOT NULL DEFAULT 0,
            wrong_count int unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            expires_ms bigint(20) unsigned NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY ranking (game_slug,pack,status,user_id),
            KEY owner (user_id,game_slug,created_at)
        ) {$collate};");
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table;
    }
    public static function engine(string $game): string {return match($game){'cipher-vault'=>Vault_Rules::class,'dance-bot'=>Dance_Rules::class,default=>throw new \InvalidArgumentException('게임을 확인하세요.')};}
    public static function pack(string $game,string $difficulty): string {return self::engine($game)::VERSION.'-'.$difficulty.'-'.\EduGames\Support\Clock::today();}
    public static function create(string $game,int $user,string $difficulty,int $now): array {
        global $wpdb;$table=self::table();$recent=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id=%d AND created_at>%s",$user,gmdate('Y-m-d H:i:s',(int)floor($now/1000)-60)));
        if($recent===null)throw new \RuntimeException('도전 기록을 확인하지 못했습니다.');if((int)$recent>=10)throw new \InvalidArgumentException('잠시 후 새 도전을 시작하세요.');
        $pack=self::pack($game,$difficulty);$seed=hash_hmac('sha256',$pack,wp_salt('auth'));$state=self::engine($game)::create($pack,$seed,$difficulty,$now);$token=bin2hex(random_bytes(24));
        $data=['token'=>$token,'user_id'=>$user,'game_slug'=>$game,'pack'=>$pack,'status'=>'playing','revision'=>0,'state_json'=>wp_json_encode($state),'score'=>0,'elapsed_ms'=>0,'wrong_count'=>0,'created_at'=>gmdate('Y-m-d H:i:s',(int)floor($now/1000)),'expires_ms'=>$now+7200000];
        if(false===$wpdb->insert($table,$data))throw new \RuntimeException('도전을 저장하지 못했습니다.');return $data+['id'=>(int)$wpdb->insert_id];
    }
    public static function find(string $game,int $user,string $token): ?array {
        global $wpdb;if(!preg_match('/^[a-f0-9]{48}$/D',$token))return null;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE game_slug=%s AND user_id=%d AND token=%s',$game,$user,$token),ARRAY_A);
        if(!empty($wpdb->last_error))throw new \RuntimeException('진행 기록을 읽지 못했습니다.');return is_array($row)?$row:null;
    }
    public static function save(array $row,array $state,int $now): ?array {
        global $wpdb;$engine=self::engine($row['game_slug']);$done=$state['status']!=='playing';$elapsed=$engine::elapsed($state,$now);$completed=$done?gmdate('Y-m-d H:i:s',(int)floor($now/1000)):null;
        $result=$wpdb->query($wpdb->prepare('UPDATE '.self::table().' SET state_json=%s,status=%s,revision=revision+1,score=%d,elapsed_ms=%d,wrong_count=%d,completed_at='.($done?'%s':'NULL').' WHERE id=%d AND revision=%d AND user_id=%d',...array_merge([wp_json_encode($state),$state['status'],$state['score'],$elapsed,$state['wrong']],$done?[$completed]:[],[(int)$row['id'],(int)$row['revision'],(int)$row['user_id']])));
        if($result===false)throw new \RuntimeException('저장하지 못했습니다. 같은 요청으로 다시 시도하세요.');if($result!==1)return null;
        return array_replace($row,['state_json'=>wp_json_encode($state),'revision'=>(int)$row['revision']+1,'status'=>$state['status'],'score'=>$state['score'],'elapsed_ms'=>$elapsed,'wrong_count'=>$state['wrong'],'completed_at'=>$completed]);
    }
    public static function ranking(string $game,string $difficulty,mixed $page,mixed $size): array {
        global $wpdb;$table=self::table();$pack=self::pack($game,$difficulty);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT a.id,a.user_id,a.score,a.elapsed_ms,a.wrong_count,a.created_at FROM {$table} a WHERE a.game_slug=%s AND a.pack=%s AND a.status='finished' AND a.id=(SELECT b.id FROM {$table} b WHERE b.game_slug=a.game_slug AND b.pack=a.pack AND b.user_id=a.user_id AND b.status='finished' ORDER BY b.score DESC,b.elapsed_ms ASC,b.wrong_count ASC,b.id ASC LIMIT 1) ORDER BY a.score DESC,a.elapsed_ms ASC,a.wrong_count ASC,a.id ASC",$game,$pack),ARRAY_A);
        if(!is_array($rows))throw new \RuntimeException('순위를 읽지 못했습니다.');$rank=0;$previous=null;
        foreach($rows as $i=>&$r){$key=$r['score'].':'.$r['elapsed_ms'].':'.$r['wrong_count'];if($key!==$previous)$rank=$i+1;$previous=$key;$r['rank']=$rank;$r['userId']=(int)$r['user_id'];$r['playerName']=(string)(get_userdata($r['userId'])->display_name??'플레이어');$r['score']=(int)$r['score'];$r['elapsedMs']=(int)$r['elapsed_ms'];$r['durationSeconds']=round($r['elapsedMs']/1000,3);$r['wrong']=(int)$r['wrong_count'];
            $total=$game==='cipher-vault'?24:8;$r['accuracy']=round($total/($total+$r['wrong'])*100,2);
            if(\EduGames\Rankings\Record_Moderation::allowed())$r['deleteUrl']=rest_url('edu-games/v1/arcade/'.$game.'/records/'.$r['id']);unset($r['user_id']);}unset($r);
        $meta=\EduGames\Rankings\Page::meta(count($rows),$page,$size);$own=array_values(array_filter($rows,static fn($r)=>$r['userId']===get_current_user_id()))[0]??null;
        return ['pack'=>$pack,'leaderboard'=>array_slice($rows,$meta['offset'],$meta['perPage']),'pagination'=>$meta,'overview'=>['top'=>array_slice($rows,0,3),'personal'=>$own,'topScore'=>$rows[0]['score']??null,'total'=>count($rows)]];
    }
    public static function records(string $game,int $user,mixed $page,mixed $size): array {
        global $wpdb;$table=self::table();$total=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE game_slug=%s AND user_id=%d",$game,$user));if($total===null)throw new \RuntimeException('기록을 읽지 못했습니다.');$meta=\EduGames\Rankings\Page::meta((int)$total,$page,$size);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT token,pack,status,score,elapsed_ms,wrong_count,created_at FROM {$table} WHERE game_slug=%s AND user_id=%d ORDER BY id DESC LIMIT %d OFFSET %d",$game,$user,$meta['perPage'],$meta['offset']),ARRAY_A);
        if(!is_array($rows))throw new \RuntimeException('기록을 읽지 못했습니다.');return ['records'=>$rows,'pagination'=>$meta];
    }
}
