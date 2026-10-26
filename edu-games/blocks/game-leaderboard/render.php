<?php
/**
 * @var array<string, mixed> $attributes
 */

if (! defined('ABSPATH')) {
    exit;
}

$registered_games = function_exists('edu_games_registry') ? edu_games_registry()->all() : array();
\EduGames\Blocks\Rank_Pager_Renderer::assets();
$games = array_filter(
    $registered_games,
    static function (array $game): bool {
        return 'playable' === (string) ($game['status'] ?? 'planned');
    }
);
$default_game = isset($games['character-code']) ? 'character-code' : (array_key_first($games) ?: 'character-code');
$config = array(
    'restUrl' => esc_url_raw(rest_url('edu-games/v1')),
    'nonce' => wp_create_nonce('wp_rest'),
    'defaultGame' => $default_game,
    'games' => array_values($games),
);
?>
<section <?php echo get_block_wrapper_attributes(array('class' => 'edu-games-block edu-games-block-game-leaderboard')); ?> data-game-leaderboard data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
    <header class="eg-board-header">
        <p><?php esc_html_e('아케이드·이전 규칙 기록', 'edu-games'); ?></p>
        <h2><?php esc_html_e('게임 순위', 'edu-games'); ?></h2>
    </header>

    <div class="eg-board-toolbar" data-field="game-tabs"></div>
    <div class="eg-board-status" data-field="status"><?php esc_html_e('순위표를 불러오는 중입니다.', 'edu-games'); ?></div>
    <div class="eg-board-table" data-field="leaderboard"></div>
</section>
<?php echo \EduGames\Blocks\Learning_Renderer::report(); ?>
<?php echo \EduGames\Blocks\Warehouse_Renderer::report(); ?>
<?php echo \EduGames\Blocks\Strategy_Renderer::reports(); ?>
