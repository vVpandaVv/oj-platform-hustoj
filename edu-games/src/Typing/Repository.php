<?php
declare(strict_types=1);
namespace EduGames\Typing;

final class Repository {
    public static function table(string $name): string {global $wpdb;return $wpdb->prefix.'edu_games_typing_'.$name;}
    public static function install(): bool {
        global $wpdb;$c=$wpdb->get_charset_collate();$evaluations=self::table('evaluations');$targets=self::table('targets');$runs=self::table('runs');
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$evaluations} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            owner_id bigint(20) unsigned NOT NULL,
            title varchar(191) NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'draft',
            config_json longtext NOT NULL,
            audit_json longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY owner_status (owner_id,status)
        ) {$c};");
        dbDelta("CREATE TABLE {$targets} (
            evaluation_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            groups_json text NOT NULL,
            PRIMARY KEY  (evaluation_id,user_id),
            KEY learner (user_id,evaluation_id)
        ) {$c};");
        dbDelta("CREATE TABLE {$runs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token varchar(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            evaluation_id bigint(20) unsigned NOT NULL DEFAULT 0,
            attempt_no int(10) unsigned NOT NULL,
            challenge varchar(191) NOT NULL,
            version varchar(40) NOT NULL,
            status varchar(16) NOT NULL,
            revision int(10) unsigned NOT NULL DEFAULT 0,
            last_request varchar(64) NOT NULL DEFAULT '',
            state_json longtext NOT NULL,
            metrics_json text NOT NULL,
            deadline_at datetime NOT NULL,
            effective_cpm decimal(10,2) NOT NULL DEFAULT 0,
            input_accuracy decimal(5,2) NOT NULL DEFAULT 0,
            qualified tinyint(1) NOT NULL DEFAULT 0,
            started_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            UNIQUE KEY attempt (evaluation_id,user_id,attempt_no),
            KEY evaluation_results (evaluation_id,user_id,status),
            KEY ranked (evaluation_id,version,challenge,status,qualified,effective_cpm)
        ) {$c};");
        foreach(array($evaluations,$targets,$runs) as $t)if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))!==$t)return false;
        return true;
    }
    public static function evaluation(int $id): ?array {global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('evaluations').' WHERE id=%d',$id),ARRAY_A)?:null;}
    public static function run(string $token,int $user): ?array {global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('runs').' WHERE token=%s AND user_id=%d AND version IN (%s,%s)',$token,$user,Rules::VERSION,Rules::LEGACY_VERSION),ARRAY_A)?:null;}
    public static function state(array $row): array {return json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);}
    public static function summary_columns(): string {return 'id,token,user_id,evaluation_id,attempt_no,challenge,version,status,revision,last_request,metrics_json,deadline_at,effective_cpm,input_accuracy,qualified,started_at,completed_at';}
    public static function save(array $row,array $state,string $request): bool {
        global $wpdb;$metrics=Rules::metrics($state,microtime(true));$done=$state['status']==='finished';
        return 1===$wpdb->query($wpdb->prepare('UPDATE '.self::table('runs')." SET state_json=%s,metrics_json=%s,status=%s,revision=revision+1,last_request=%s,effective_cpm=%f,input_accuracy=%f,qualified=%d,completed_at=".($done?'%s':'NULL').' WHERE id=%d AND revision=%d',
            ...array_merge(array(wp_json_encode($state),wp_json_encode($metrics),$state['status'],$request,$metrics['effectiveCpm'],$metrics['inputAccuracy'],$metrics['qualified']?1:0),$done?array(gmdate('Y-m-d H:i:s')):array(),array((int)$row['id'],(int)$row['revision']))));
    }
    public static function settle(array $row): array {
        if($row['status']!=='running')return $row;
        $s=self::state($row);
        if(microtime(true)>$s['deadline']+2){
            $s['status']='finished';
            if(self::save($row,$s,$row['last_request'])){$m=Rules::metrics($s,microtime(true));$row['state_json']=wp_json_encode($s);$row['metrics_json']=wp_json_encode($m);$row['status']='finished';$row['revision']++;$row['completed_at']=gmdate('Y-m-d H:i:s');$row['effective_cpm']=$m['effectiveCpm'];$row['input_accuracy']=$m['inputAccuracy'];$row['qualified']=$m['qualified']?1:0;}
            else{$fresh=self::run($row['token'],(int)$row['user_id']);if($fresh)$row=$fresh;}
        }
        return $row;
    }
    public static function settle_summary(array $row): array {
        if($row['status']==='running'&&time()>strtotime($row['deadline_at'].' UTC')+3){$full=self::run($row['token'],(int)$row['user_id']);if($full){$row=self::settle($full);unset($row['state_json']);}}
        return $row;
    }
}
