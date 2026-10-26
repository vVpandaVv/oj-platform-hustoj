<?php
declare(strict_types=1);
namespace EduGames\Reports;
use EduGames\Rankings\Page;
use EduGames\Learning\Catalog;
use EduGames\Learning\Run;

final class Report {
    public static function modes(string $game): array {
        $modes=array('arcade'=>array('label'=>'아케이드·검정 기록','version'=>'arcade'));
        if(isset(\EduGames\Boards\Catalog::GAMES[$game])) $modes=array('strategy'=>array('label'=>'전략 보드','version'=>\EduGames\Boards\Catalog::version($game)));
        if($game==='data-structure-lab') $modes=array('board'=>array('label'=>'물류 보드','version'=>\EduGames\Games\Modules\Data_Structure_Lab\Warehouse_Board::VERSION));
        if($game==='image-data') $modes=array('collection'=>array('label'=>'복원 도감','version'=>'progress-1'));
        if(isset(\EduGames\Play\Catalog::GAMES[$game])) $modes=array('play'=>array('label'=>'오늘의 직접 조작 도전','version'=>\EduGames\Play\Catalog::version($game)))+$modes;
        if(isset(Catalog::PROVIDERS[$game])) {
            $modes['assessment']=array('label'=>'개념 평가','version'=>Catalog::VERSION);
            $modes['practice']=array('label'=>'개념 연습','version'=>Catalog::VERSION);
            $modes['guided']=array('label'=>'안내 연습','version'=>Catalog::VERSION);
        }
        return $modes;
    }
    public static function read(array $users,string $game,string $mode,string $from,string $to,$page,$size): array|\WP_Error {
        global $wpdb;
        $modes=self::modes($game);if(!isset($modes[$mode]))return new \WP_Error('edu_games_report_mode','기록 종류를 확인하세요.',array('status'=>400));
        $users=array_values(array_unique(array_filter(array_map('intval',$users),static fn($id)=>$id>0)));sort($users);
        $meta=Page::meta(count($users),$page,$size);$selected=array_slice($users,$meta['offset'],$meta['perPage']);
        $rows=array();foreach($selected as $user){$profile=get_userdata($user);$rows[$user]=array('userId'=>$user,'name'=>$profile?$profile->display_name:'학생','attempts'=>0,'completed'=>0,'cleared'=>0,'stars'=>0,'best'=>null,'first'=>null,'final'=>null,'total'=>null,'last'=>null);}
        if(!$users)return array('students'=>array(),'pagination'=>$meta,'participants'=>0,'version'=>$modes[$mode]['version']);
        $table=$mode==='arcade'?\EduGames\Database\Installer::scores_table():($mode==='collection'?\EduGames\Progress\Store::table():\EduGames\Learning\Installer::table());
        $time=$mode==='arcade'?'created_at':($mode==='collection'?'updated_at':'started_at');
        $where=$wpdb->prepare('game_slug=%s',$game);
        if(!in_array($mode,array('arcade','collection'),true))$where.=$wpdb->prepare(' AND content_version=%s AND mode=%s',$modes[$mode]['version'],$mode);
        if($from!=='')$where.=$wpdb->prepare(" AND {$time}>=%s",$mode==='arcade'?get_date_from_gmt($from):$from);
        if($to!=='')$where.=$wpdb->prepare(" AND {$time}<%s",$mode==='arcade'?get_date_from_gmt($to):$to);
        $all=implode(',',$users);$ids=implode(',',$selected);
        $participants=$wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE {$where} AND user_id IN ({$all})");
        if($participants===null||!empty($wpdb->last_error))return Page::error();
        if($mode==='collection')$sql="SELECT user_id, COUNT(*) AS attempts, COUNT(*) AS completed, COUNT(*) AS best, MAX(updated_at) AS last FROM {$table} WHERE {$where} AND user_id IN ({$ids}) GROUP BY user_id";
        elseif($mode==='arcade')$sql="SELECT user_id, COUNT(*) AS attempts, COUNT(*) AS completed, MAX(score) AS best, MAX(created_at) AS last FROM {$table} WHERE {$where} AND user_id IN ({$ids}) GROUP BY user_id";
        else $sql="SELECT user_id, level, COUNT(*) AS attempts, SUM(completed_at IS NOT NULL) AS completed, MAX(CASE WHEN completed_at IS NOT NULL THEN score ELSE NULL END) AS best, MAX(started_at) AS last FROM {$table} WHERE {$where} AND user_id IN ({$ids}) GROUP BY user_id, level";
        $records=$wpdb->get_results($sql,ARRAY_A);if(!is_array($records)||!empty($wpdb->last_error))return Page::error();
        foreach($records as $record) {
            $id=(int)$record['user_id'];if(!isset($rows[$id]))continue;
            $row=&$rows[$id];$row['attempts']+=(int)$record['attempts'];$row['completed']+=(int)$record['completed'];
            if($record['best']!==null)$row['best']=max($row['best']??0,(float)$record['best']);
            $last=$mode==='arcade'?get_gmt_from_date($record['last']):$record['last'];if($row['last']===null||$last>$row['last'])$row['last']=$last;
            if($mode==='strategy')$row['stars']+=\EduGames\Boards\Rules::stars((int)$record['best']);
            if($mode==='board')$row['stars']+=\EduGames\Games\Modules\Data_Structure_Lab\Warehouse_Board::stars((int)$record['best']);
            if(in_array($mode,array('strategy','board'),true)&&(float)$record['best']>0)$row['cleared']++;
            unset($row);
        }
        if(in_array($mode,array('guided','practice','assessment'),true)) {
            $latest=$wpdb->get_results("SELECT user_id,state_json FROM {$table} WHERE id IN (SELECT MAX(id) FROM {$table} WHERE {$where} AND user_id IN ({$ids}) AND completed_at IS NOT NULL GROUP BY user_id)",ARRAY_A);
            if(!is_array($latest)||!empty($wpdb->last_error))return Page::error();
            foreach($latest as $record){$id=(int)$record['user_id'];if(!isset($rows[$id]))continue;$state=json_decode($record['state_json'],true);if(!is_array($state)||!isset($state['ids'],$state['records']))continue;$summary=Run::summary($state);$rows[$id]['first']=$summary['firstCorrect'];$rows[$id]['final']=$summary['finalCorrect'];$rows[$id]['total']=$summary['total'];}
        }
        return array('students'=>array_values($rows),'pagination'=>$meta,'participants'=>(int)$participants,'version'=>$modes[$mode]['version']);
    }
}
