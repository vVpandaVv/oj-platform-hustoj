<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;

final class Realtime_Settings {
    public const OPTION = 'edu_games_realtime';

    public static function all(): array {
        $raw=get_option(self::OPTION,array());$raw=is_array($raw)?$raw:array();
        $default=preg_replace('#^http#','ws',rtrim(home_url(),'/')).'/edu-games-live';
        return array('enabled'=>!empty($raw['enabled']),'public_url'=>$raw['public_url']??$default);
    }
    public static function url(): string { $s=self::all();return $s['enabled']?$s['public_url']:''; }
    public static function api_url(): string {return 'http://127.0.0.1:3210'.(parse_url(self::all()['public_url'],PHP_URL_PATH)?:'/edu-games-live');}
    public static function save(array $input): void {
        $url=$input['public_url']??'';$site=parse_url(home_url());$p=is_string($url)?parse_url(trim($url)):false;
        if(!$p||($p['scheme']??'')!==($site['scheme']==='https'?'wss':'ws')||strtolower($p['host']??'')!==strtolower($site['host']??'')
            ||($p['port']??($p['scheme']==='wss'?443:80))!==($site['port']??($site['scheme']==='https'?443:80))
            ||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])
            ||!preg_match('#^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#D',$p['path']??''))throw new Failure('현재 사이트의 ws/wss 주소와 전용 경로를 입력하세요.',422);
        update_option(self::OPTION,array('enabled'=>!empty($input['enabled']),'public_url'=>trim($url)),false);
        do_action('edu_games_realtime_settings_saved',get_current_user_id());
    }
    public static function install_code(): string {
        $site=parse_url(home_url());$env=array('EDU_GAMES_SECRET'=>(string)get_option('edu_games_binary_team_secret',''),
            'WP_API'=>plugins_url('binary-relay.php',EDU_GAMES_FILE),'ALLOWED_ORIGINS'=>$site['scheme'].'://'.$site['host'].(isset($site['port'])?':'.$site['port']:''),
            'HOST'=>'127.0.0.1','PORT'=>'3210','WS_PATH'=>(string)parse_url(self::all()['public_url'],PHP_URL_PATH));
        return 'ojhub1.'.rtrim(strtr(base64_encode((string)wp_json_encode(array('v'=>1,'app'=>'edu-games','env'=>$env))),'+/','-_'),'=');
    }
    public static function status(): array {
        $time=(string)time();$signature=Socket_Relay::signature('GET','/status',$time,'',(string)get_option('edu_games_binary_team_secret',''));
        $r=wp_remote_get(self::api_url().'/status',array('timeout'=>5,'redirection'=>0,'headers'=>array('X-OJ-Realtime-Time'=>$time,'X-OJ-Realtime-Signature'=>$signature)));
        if(is_wp_error($r))throw new Failure('공용 WebSocket 서버에 연결하지 못했습니다.',503);
        $data=json_decode(wp_remote_retrieve_body($r),true);
        if(wp_remote_retrieve_response_code($r)!==200||!is_array($data)||($data['app']??'')!=='edu-games'||($data['protocol']??0)!==1
            ||($data['service']??'')!=='oj-realtime-hub'||empty($data['authenticated'])||($data['transport']??'')!=='websocket')throw new Failure('서버 앱 등록과 인증키를 확인하세요.',503);
        return array_intersect_key($data,array_flip(array('ok','service','app','version','appVersion','connections','rooms','transport','protocol')));
    }
}
