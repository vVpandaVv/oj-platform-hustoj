<?php
declare(strict_types=1);
namespace EduGames\Blocks;

final class Game_Shell_Renderer {
    public static function assets(): void {
        wp_enqueue_style('edu-games-shell', plugins_url('assets/shell/shell.css', EDU_GAMES_FILE), array(), (string)filemtime(EDU_GAMES_PATH.'assets/shell/shell.css'));
        wp_enqueue_script('edu-games-shell', plugins_url('assets/shell/shell.js', EDU_GAMES_FILE), array(), (string)filemtime(EDU_GAMES_PATH.'assets/shell/shell.js'), true);
    }
}
