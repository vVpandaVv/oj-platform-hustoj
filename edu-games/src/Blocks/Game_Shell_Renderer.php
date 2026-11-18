<?php
declare(strict_types=1);
namespace EduGames\Blocks;

final class Game_Shell_Renderer {
    public static function cipher_navigation(string $current): string {
        $links = [
            'solo' => ['/oj-addons/edu-games/games/cipher-vault', '개인 금고'],
            'team' => ['/oj-addons/edu-games/cipher-team', '금고 팀전'],
            'practice' => ['/oj-addons/edu-games/games/character-code', '코드 연습'],
        ];
        $html = '<nav class="eg-cipher-nav" aria-label="문자 코드 활동 선택">';
        foreach ($links as $id => [$path, $label]) {
            $html .= '<a href="'.esc_url(home_url($path)).'"'.($id === $current ? ' aria-current="page"' : '').'>'.esc_html($label).'</a>';
        }
        return $html.'</nav>';
    }
    public static function assets(): void {
        wp_enqueue_style('edu-games-shell', plugins_url('assets/shell/shell.css', EDU_GAMES_FILE), array(), (string)filemtime(EDU_GAMES_PATH.'assets/shell/shell.css'));
        wp_enqueue_style('edu-games-layouts', plugins_url('assets/shell/layouts.css', EDU_GAMES_FILE), array('edu-games-shell'), (string)filemtime(EDU_GAMES_PATH.'assets/shell/layouts.css'));
        wp_enqueue_script('edu-games-shell', plugins_url('assets/shell/shell.js', EDU_GAMES_FILE), array(), (string)filemtime(EDU_GAMES_PATH.'assets/shell/shell.js'), true);
    }
}
