<?php
declare(strict_types=1);
namespace EduGames\Blocks;
final class Arcade_Renderer {
    public static function vault(array $attributes=[],bool $block=false): string {return self::render('cipher-vault',$block);}
    public static function dance(array $attributes=[],bool $block=false): string {return self::render('dance-bot',$block);}
    public static function render(string $game,bool $block=false): string {
        $vault=$game==='cipher-vault';$title=$vault?'암호 금고 · 개인전':'댄스봇';Game_Shell_Renderer::assets();Rank_Pager_Renderer::assets();
        wp_enqueue_style('edu-games-arcade',plugins_url('assets/arcade/style.css',EDU_GAMES_FILE),['edu-games-shell'],EDU_GAMES_VERSION);
        wp_enqueue_script('edu-games-vault-board',plugins_url('assets/arcade/vault-board.js',EDU_GAMES_FILE),[],EDU_GAMES_VERSION,true);
        wp_enqueue_script('edu-games-arcade',plugins_url('assets/arcade/view.js',EDU_GAMES_FILE),['edu-games-vault-board','edu-games-rank-pager'],EDU_GAMES_VERSION,true);
        $config=['gameId'=>$game,'hubUrl'=>home_url('/oj-addons/edu-games/games'),'restUrl'=>rest_url('edu-games/v1/arcade/'.$game),'nonce'=>wp_create_nonce('wp_rest'),'currentUserId'=>get_current_user_id(),'isLoggedIn'=>is_user_logged_in(),'icons'=>plugins_url('assets/shell/icons.svg',EDU_GAMES_FILE),
            'codebook'=>$vault?\EduGames\Arcade\Vault_Rules::codebook():[], 'teamUrl'=>$vault?home_url('/oj-addons/edu-games/cipher-team'):'','practiceUrl'=>$vault?home_url('/oj-addons/edu-games/games/character-code'):''];
        $wrapper=$block?get_block_wrapper_attributes(['class'=>'edu-game ea-game']):'class="edu-game ea-game"';
        return '<section '.$wrapper.' data-edu-arcade data-config="'.esc_attr(wp_json_encode($config)).'"><div class="ea-shell"><header class="ea-topbar"><h2>'.$title.'</h2><nav>'.($vault?'':'<button type="button" data-ea="practice">자유 안무</button>').'<select data-ea-difficulty aria-label="난이도"><option value="standard">표준</option><option value="challenge">도전</option></select><button type="button" data-ea="start" class="ea-primary">기록 도전</button><button type="button" data-ea="stop" hidden>도전 중단</button></nav></header>'.($vault?Game_Shell_Renderer::cipher_navigation('solo'):'').'<div class="ea-metrics" data-ea-metrics></div><div data-ea-board></div><output class="ea-message" data-ea-message role="status" aria-live="polite"></output><nav class="ea-record-links"><button type="button" data-ea="ranking">순위</button><button type="button" data-ea="records">내 기록</button><button type="button" data-ea="resume" hidden>다시 연결</button></nav><div data-ea-records hidden></div></div></section>';
    }
}
