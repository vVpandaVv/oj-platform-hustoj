<?php
declare(strict_types=1);
namespace EduGames\Blocks;

final class Rank_Pager_Renderer {
    public static function assets(): void {
        wp_enqueue_style('edu-games-rank-pager',plugins_url('assets/rankings/pager.css',EDU_GAMES_FILE),array(),(string)filemtime(EDU_GAMES_PATH.'assets/rankings/pager.css'));
        wp_enqueue_script('edu-games-rank-pager',plugins_url('assets/rankings/pager.js',EDU_GAMES_FILE),array(),(string)filemtime(EDU_GAMES_PATH.'assets/rankings/pager.js'),true);
        wp_enqueue_script('edu-games-progress',plugins_url('assets/learning/progress.js',EDU_GAMES_FILE),array(),(string)filemtime(EDU_GAMES_PATH.'assets/learning/progress.js'),true);
    }
}
