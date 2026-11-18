<?php
declare(strict_types=1);
namespace EduGames\Rest\Controllers;
use EduGames\Typing\Service;
use EduGames\Support\Capabilities;
use EduGames\Games\Game_Visibility;

final class Typing_Controller {
    public static function player(): bool {return Capabilities::can_play()&&Game_Visibility::is_open('code-typing');}
    public static function reader(): bool {return self::player()||Capabilities::can_manage();}
    public static function register_routes(): void {
        $routes=array(
            array('/typing/catalog','GET','catalog','reader'),
            array('/typing/assigned','GET','assigned','player'),
            array('/typing/evaluations/(?P<id>\d+)/preview','GET','preview','player'),
            array('/typing/runs/start','POST','start','player'),
            array('/typing/runs/update','POST','update','player'),
            array('/typing/runs/resume','POST','resume','player'),
            array('/typing/leaderboard','POST','leaderboard','reader'),
            array('/typing/evaluations','GET','evaluations','teacher'),
            array('/typing/evaluations','POST','create','teacher'),
            array('/typing/evaluations/(?P<id>\d+)/status','POST','status','teacher'),
            array('/typing/evaluations/(?P<id>\d+)/results','GET','results','teacher'),
            array('/typing/evaluations/(?P<id>\d+)/attempts','GET','attempts','teacher')
        );
        foreach($routes as [$path,$method,$callback,$access])register_rest_route('edu-games/v1',$path,array('methods'=>$method,'callback'=>array(__CLASS__,$callback),'permission_callback'=>$access==='teacher'?array(Capabilities::class,'can_manage'):array(__CLASS__,$access)));
    }
    private static function input(\WP_REST_Request $r): array {$p=$r->get_json_params();return is_array($p)?$p:array();}
    public static function catalog(\WP_REST_Request $r){return rest_ensure_response(Service::catalog());}
    public static function assigned(\WP_REST_Request $r){return rest_ensure_response(Service::assigned());}
    public static function preview(\WP_REST_Request $r){return rest_ensure_response(Service::preview((int)$r['id']));}
    public static function evaluations(\WP_REST_Request $r){return rest_ensure_response(Service::evaluations());}
    public static function create(\WP_REST_Request $r){return rest_ensure_response(Service::create_evaluation(self::input($r)));}
    public static function start(\WP_REST_Request $r){return rest_ensure_response(Service::start(self::input($r)));}
    public static function update(\WP_REST_Request $r){return rest_ensure_response(Service::update(self::input($r)));}
    public static function resume(\WP_REST_Request $r){return rest_ensure_response(Service::update(self::input($r),true));}
    public static function status(\WP_REST_Request $r){return rest_ensure_response(Service::set_status((int)$r['id'],self::input($r)['status']??null));}
    public static function results(\WP_REST_Request $r){$filters=array();foreach(array('status','quality','group','search','sort','direction') as $key)if($r->get_param($key)!==null)$filters[$key]=$r->get_param($key);return rest_ensure_response(Service::results((int)$r['id'],$r->get_param('page')??1,$r->get_param('perPage')??20,$r->get_param('export')==='1',null,$filters));}
    public static function attempts(\WP_REST_Request $r){$id=$r->get_param('userId');if(!is_scalar($id)||!ctype_digit((string)$id)||(int)$id<=0)return Service::error('학생 번호를 확인하세요.');return rest_ensure_response(Service::results((int)$r['id'],1,20,false,(int)$id));}
    public static function leaderboard(\WP_REST_Request $r){$p=self::input($r);$sample=$p['level']??$p['sampleId']??'';$version=$p['version']??\EduGames\Typing\Rules::VERSION;$lineEnding=$p['lineEnding']??'enter';return is_string($sample)&&is_string($version)&&is_string($lineEnding)?rest_ensure_response(Service::leaderboard($sample,$p['page']??1,$p['perPage']??20,$version,$lineEnding)):Service::error('예제와 채점 버전을 확인하세요.');}
}
