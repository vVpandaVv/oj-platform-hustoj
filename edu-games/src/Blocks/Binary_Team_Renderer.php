<?php
declare(strict_types=1);
namespace EduGames\Blocks;
use EduGames\Support\Capabilities;

final class Binary_Team_Renderer {
    public static function render(array $attributes=array(),bool $is_block=false): string {
        return self::render_game('binary-conversion',$is_block);
    }
    public static function render_game(string $game,bool $is_block=false,string $screen='student',string $room=''): string {
        if(!in_array($screen,array('student','teacher','board'),true))$screen='student';
        $manage=Capabilities::can_manage()||current_user_can('manage_options');
        if($screen!=='student'&&!$manage)return '<p>교사 전용 화면입니다.</p>';
        if(!preg_match('/^[A-Z2-9]{6}$/D',$room))$room='';
        $device=$game==='device-matching';$slug=$device?'device-team':'binary-team';$title=$device?'장치 카드 매칭 팀전':'이진수 아케이드 팀전';
        Game_Shell_Renderer::assets();
        wp_enqueue_style('edu-games-team-personal-'.$slug,plugins_url('blocks/'.($device?'device-matching':'binary-arcade').'/style.css',EDU_GAMES_FILE),array('edu-games-shell'),EDU_GAMES_VERSION);
        wp_enqueue_style('edu-games-binary-team',plugins_url('assets/binary-team/style.css',EDU_GAMES_FILE),array('edu-games-shell'),EDU_GAMES_VERSION);
        wp_enqueue_script('edu-games-binary-socket',plugins_url('assets/binary-team/socket.js',EDU_GAMES_FILE),array(),EDU_GAMES_VERSION,true);
        wp_enqueue_script('edu-games-device-board',plugins_url('assets/device-team/board.js',EDU_GAMES_FILE),array(),EDU_GAMES_VERSION,true);
        wp_enqueue_style('edu-games-device-team',plugins_url('assets/device-team/style.css',EDU_GAMES_FILE),array('edu-games-binary-team'),EDU_GAMES_VERSION);
        wp_enqueue_style('edu-games-team-operations',plugins_url('assets/binary-team/operations.css',EDU_GAMES_FILE),array('edu-games-device-team'),EDU_GAMES_VERSION);
        wp_enqueue_script('edu-games-team-operations',plugins_url('assets/binary-team/operations.js',EDU_GAMES_FILE),array(),EDU_GAMES_VERSION,true);
        wp_enqueue_script('edu-games-binary-team',plugins_url('assets/binary-team/view.js',EDU_GAMES_FILE),array('edu-games-shell','edu-games-binary-socket','edu-games-device-board','edu-games-team-operations'),EDU_GAMES_VERSION,true);
        $config=array('gameId'=>$game,'channel'=>$slug,'title'=>$title,'restUrl'=>esc_url_raw(rest_url('edu-games/v1/'.$slug)),'nonce'=>wp_create_nonce('wp_rest'),'relayUrl'=>plugins_url('binary-relay.php',EDU_GAMES_FILE),
            'currentUserId'=>get_current_user_id(),'isLoggedIn'=>is_user_logged_in(),'manage'=>$manage,'admin'=>current_user_can('manage_options'),'screen'=>$screen,'initialRoom'=>$room,
            'teacherUrl'=>home_url('/oj-console/addons/edu-games/team-games?game='.$game),'studentUrl'=>home_url('/oj-addons/edu-games/'.$slug),
            'boardUrl'=>home_url('/oj-addons/edu-games/team-board?game='.$game),
            'websocketUrl'=>\EduGames\Multiplayer\Realtime_Settings::url(),'creditsUrl'=>plugins_url('assets/device-matching/ATTRIBUTION.md',EDU_GAMES_FILE),
            'hubUrl'=>home_url('/oj-addons/edu-games/games'),'soloUrl'=>home_url('/oj-addons/edu-games/games/'.$game));
        if($screen==='student')unset($config['teacherUrl'],$config['boardUrl']);
        $class='edu-game binary-team'.($device?' device-team':'');$wrapper=$is_block?get_block_wrapper_attributes(array('class'=>$class)):'class="'.$class.'"';
        return '<section '.$wrapper.' data-team-screen="'.$screen.'" data-binary-team data-config="'.esc_attr(wp_json_encode($config)).'">'
            .'<div class="bt-shell '.($device?'oj-device-shell':'oj-binary-shell').'"><header class="ep-heading"><h2>'.esc_html($title.($screen==='teacher'?' · 교사 운영실':($screen==='board'?' · 현황판':''))).'</h2>'
            .($screen!=='student'?'<a class="bt-link" href="'.esc_url($config['studentUrl']).'">학생 참가 화면</a>':'')
            .'<a class="bt-link" href="'.esc_url($config['soloUrl']).'">'.esc_html__('개인전','edu-games').'</a></header>'
            .'<p class="bt-message" data-bt-message role="status" aria-live="polite"></p><div data-bt-app></div></div></section>';
    }
}
