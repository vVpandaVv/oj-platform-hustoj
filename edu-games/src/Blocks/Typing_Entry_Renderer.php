<?php
declare(strict_types=1);
namespace EduGames\Blocks;

use EduGames\Games\Game_Visibility;
use EduGames\Support\Capabilities;

final class Typing_Entry_Renderer {
    public static function assets(): void {
        foreach(array('css','js') as $type){
            $file='assets/typing/entry.'.$type;$version=(string)filemtime(EDU_GAMES_PATH.$file);
            if($type==='css')wp_enqueue_style('edu-games-typing-entry',plugins_url($file,EDU_GAMES_FILE),array(),$version);
            else wp_enqueue_script('edu-games-typing-entry',plugins_url($file,EDU_GAMES_FILE),array(),$version,true);
        }
    }
    public static function hub(?string $game_url=null): string {
        if(!is_user_logged_in()||!Capabilities::can_play()||!Game_Visibility::is_open('code-typing'))return '';
        self::assets();
        $config=array('restUrl'=>esc_url_raw(rest_url('edu-games/v1')),'nonce'=>wp_create_nonce('wp_rest'),
            'gameUrl'=>$game_url??home_url('/oj-addons/edu-games/games/code-typing'),'iconsUrl'=>plugins_url('assets/shell/icons.svg',EDU_GAMES_FILE));
        return '<section class="edu-typing-entry" data-typing-entry-hub data-config="'.esc_attr(wp_json_encode($config)).'" aria-label="내 수행평가"><div class="ete-student-loading" role="status">평가 조회 중</div></section>';
    }
}
