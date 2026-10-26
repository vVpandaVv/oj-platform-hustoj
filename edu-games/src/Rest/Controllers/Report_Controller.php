<?php
declare(strict_types=1);
namespace EduGames\Rest\Controllers;
use EduGames\Reports\Roster;
use EduGames\Reports\Report;
use EduGames\Support\Capabilities;

final class Report_Controller {
    public static function register_routes(): void {
        register_rest_route('edu-games/v1','/reports/contexts',array('methods'=>'GET','callback'=>array(__CLASS__,'contexts'),'permission_callback'=>array(Capabilities::class,'can_manage')));
        register_rest_route('edu-games/v1','/reports/students',array('methods'=>'GET','callback'=>array(__CLASS__,'students'),'permission_callback'=>array(Capabilities::class,'can_manage')));
    }
    public static function contexts(\WP_REST_Request $r) {
        $cursor=$r->get_param('cursor')??'';
        if(!is_string($cursor)||strlen($cursor)>512)return self::invalid();
        try{$result=Roster::contexts($cursor);}catch(\InvalidArgumentException){return self::invalid();}
        return is_wp_error($result)?$result:rest_ensure_response($result);
    }
    public static function students(\WP_REST_Request $r) {
        $p=$r->get_params();
        foreach(array('type','id','revision','game','mode') as $field)if(!is_string($p[$field]??null))return self::invalid();
        if(!in_array($p['type'],array('class','group'),true)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,159}$/D',$p['id'])||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,159}$/D',$p['revision']))return self::invalid();
        if(!isset(\edu_games_registry()->all(false)[$p['game']]))return self::invalid();
        $dates=array();foreach(array('from','to') as $key){$date=$p[$key]??'';if(!is_string($date))return self::invalid();if($date===''){$dates[$key]='';continue;}$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('UTC'));if(!$parsed||$parsed->format('Y-m-d')!==$date)return self::invalid();$dates[$key]=($key==='to'?$parsed->modify('+1 day'):$parsed)->format('Y-m-d H:i:s');}
        if($dates['from']!==''&&$dates['to']!==''&&$dates['from']>=$dates['to'])return self::invalid();
        $users=Roster::learners($p['type'],$p['id'],$p['revision']);if(is_wp_error($users))return $users;
        $result=Report::read($users,$p['game'],$p['mode'],$dates['from'],$dates['to'],$p['page']??1,$p['perPage']??20);
        return is_wp_error($result)?$result:rest_ensure_response($result);
    }
    private static function invalid(): \WP_Error {return new \WP_Error('edu_games_report_input','학급, 게임 또는 조회 기간을 확인하세요.',array('status'=>400));}
}
