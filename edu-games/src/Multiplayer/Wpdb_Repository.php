<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;

final class Wpdb_Repository implements Repository {
    public static function rooms_table(): string {global $wpdb;return $wpdb->prefix.'edu_games_binary_rooms';}
    public static function results_table(): string {global $wpdb;return $wpdb->prefix.'edu_games_binary_results';}

    public static function install(): bool {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $collate=$wpdb->get_charset_collate();$rooms=self::rooms_table();$results=self::results_table();
        dbDelta("CREATE TABLE {$rooms} (
            code varchar(8) NOT NULL,
            game_id varchar(40) NOT NULL DEFAULT 'binary-conversion',
            match_mode varchar(16) NOT NULL DEFAULT 'practice',
            host_id bigint(20) unsigned NOT NULL,
            member_ids text NOT NULL,
            status varchar(16) NOT NULL,
            state longtext NOT NULL,
            updated_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (code),
            KEY host_status (host_id, status),
            KEY game_status (game_id, status),
            KEY updated_at (updated_at)
        ) {$collate};");
        dbDelta("CREATE TABLE {$results} (
            match_id varchar(32) NOT NULL,
            game_id varchar(40) NOT NULL DEFAULT 'binary-conversion',
            match_mode varchar(16) NOT NULL DEFAULT 'practice',
            host_id bigint(20) unsigned NOT NULL,
            member_ids text NOT NULL,
            summary longtext NOT NULL,
            finished_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (match_id),
            KEY host_finished (host_id, finished_at),
            KEY game_finished (game_id, finished_at),
            KEY game_mode_finished (game_id, match_mode, finished_at),
            KEY finished_at (finished_at)
        ) {$collate};");
        foreach(array($rooms,$results) as $table)if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table)return false;
        foreach(array($rooms,$results) as $table)if(!$wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'game_id'"))return false;
        foreach(array($rooms,$results) as $table)if(!$wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'match_mode'"))return false;
        if(strlen((string)get_option('edu_games_binary_team_secret',''))<32)update_option('edu_games_binary_team_secret',bin2hex(random_bytes(32)),false);
        return true;
    }

    public function locked(string $key,callable $operation): mixed {
        global $wpdb;
        $lock='egbt_'.substr(hash('sha256',$wpdb->prefix.':'.$key),0,48);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)',$lock,1))!==1)throw new Failure('동기화 중입니다. 잠시 뒤 다시 시도하세요.',503);
        try{return $operation();}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public function get(string $code): ?array {
        global $wpdb;$raw=$wpdb->get_var($wpdb->prepare('SELECT state FROM '.self::rooms_table().' WHERE code = %s',$code));
        return $raw===null?null:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }
    private static function fields(array $r): array {
        return array('host_id'=>$r['host'],'game_id'=>Room_Rules::game($r),'match_mode'=>Room_Policy::mode($r),'member_ids'=>implode(',',array_keys($r['players'])),'status'=>$r['status'],
            'state'=>json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'updated_at'=>$r['updatedAt']);
    }
    public function create(array $room): void {
        global $wpdb;
        if($wpdb->insert(self::rooms_table(),array('code'=>$room['code'])+self::fields($room))===false)throw new Failure('방을 저장하지 못했습니다.',503);
    }
    public function put(array $room): void {
        global $wpdb;
        if($wpdb->update(self::rooms_table(),self::fields($room),array('code'=>$room['code']))===false)throw new Failure('경기 상태를 저장하지 못했습니다. 다시 연결하세요.',503);
    }
    public function delete(string $code): void {
        global $wpdb;
        if($wpdb->delete(self::rooms_table(),array('code'=>$code),array('%s'))===false)throw new Failure('방을 닫지 못했습니다. 다시 시도하세요.',503);
    }
    public function recent(int $user,bool $admin=false): array {
        global $wpdb;
        $where=$admin?'1=1':$wpdb->prepare('(host_id = %d OR FIND_IN_SET(%d, member_ids))',$user,$user);
        $rows=$wpdb->get_col('SELECT state FROM '.self::rooms_table().' WHERE '.$where.' ORDER BY updated_at DESC LIMIT 60');
        return array_map(static fn($raw)=>json_decode($raw,true,512,JSON_THROW_ON_ERROR),(array)$rows);
    }
    public function save_result(array $result): void {
        global $wpdb;
        if($this->result($result['id'])!==null)return;
        $members=implode(',',array_map(static fn($p)=>(int)$p['id'],$result['players']));
        if($wpdb->insert(self::results_table(),array('match_id'=>$result['id'],'game_id'=>Room_Rules::game($result),'match_mode'=>Room_Policy::mode($result),'host_id'=>$result['host'],'member_ids'=>$members,
            'summary'=>json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'finished_at'=>$result['finishedAt']))===false)throw new Failure('결과 저장에 실패했습니다. 재연결하면 다시 저장합니다.',503);
    }
    public function result(string $id): ?array {
        global $wpdb;$raw=$wpdb->get_var($wpdb->prepare('SELECT summary FROM '.self::results_table().' WHERE match_id = %s',$id));
        return $raw===null?null:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }
    public function records(int $user,bool $admin,int $page,string $game=Room_Rules::BINARY,string $mode='all'): array {
        global $wpdb;
        $where=$admin?'1=1':$wpdb->prepare('(host_id = %d OR FIND_IN_SET(%d, member_ids))',$user,$user);
        $where.=$wpdb->prepare(' AND game_id = %s',$game);
        if($mode!=='all')$where.=$wpdb->prepare(' AND match_mode = %s',$mode);
        $table=self::results_table();$total=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$table.' WHERE '.$where);
        $pages=max(1,(int)ceil($total/20));$page=min($page,$pages);
        $rows=$wpdb->get_col($wpdb->prepare('SELECT summary FROM '.$table.' WHERE '.$where.' ORDER BY finished_at DESC, match_id DESC LIMIT %d OFFSET %d',20,($page-1)*20));
        return array('items'=>array_map(static fn($raw)=>json_decode($raw,true,512,JSON_THROW_ON_ERROR),(array)$rows),
            'pagination'=>array('page'=>$page,'pages'=>$pages,'total'=>$total,'perPage'=>20));
    }
    public static function prune(): void {
        global $wpdb;
        $now=(int)round(microtime(true)*1000);$store=new self();$service=new Service($store);
        $due=$wpdb->get_col($wpdb->prepare('SELECT code FROM '.self::rooms_table()." WHERE status IN ('playing','paused') AND updated_at < %d LIMIT 200",$now-300000));
        foreach((array)$due as $code){try{$room=$store->get($code);if($room)(new Service($store,Room_Rules::game($room)))->access($code,array('id'=>0,'admin'=>true,'manage'=>true,'name'=>''),null,$now);}catch(\Throwable $e){error_log('[Edu Games team maintenance] '.$e->getMessage());}}
        $wpdb->query($wpdb->prepare('DELETE FROM '.self::rooms_table()." WHERE status IN ('lobby','finished') AND updated_at < %d",$now-2*86400000));
    }
}
