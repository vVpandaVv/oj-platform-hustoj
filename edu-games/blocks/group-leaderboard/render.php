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
$default_game = isset($games['character-code']) ? 'character-code' : (array_key_first($games) ?: '');
$is_logged_in = is_user_logged_in();
$can_manage = \EduGames\Support\Capabilities::can_manage();
$can_view = $can_manage || \EduGames\Support\Capabilities::can_play();
$config = array(
    'restUrl' => esc_url_raw(rest_url('edu-games/v1')),
    'nonce' => wp_create_nonce('wp_rest'),
    'isLoggedIn' => $is_logged_in,
    'canPlay' => \EduGames\Support\Capabilities::can_play(),
    'canManage' => $can_manage,
    'currentUserId' => get_current_user_id(),
    'defaultGame' => $default_game,
    'games' => array_values($games),
);
?>
<section <?php echo get_block_wrapper_attributes(array('class' => 'edu-games-block edu-games-block-group-leaderboard')); ?> data-group-leaderboard data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
    <header class="eg-board-header">
        <p><?php esc_html_e('아케이드·이전 규칙 기록', 'edu-games'); ?></p>
        <h2><?php esc_html_e('순위 보기', 'edu-games'); ?></h2>
    </header>

    <?php if (! $is_logged_in) : ?>
        <div class="eg-empty"><?php esc_html_e('로그인하면 전체 순위, 팀 순위, 팀 내 순위를 볼 수 있습니다.', 'edu-games'); ?></div>
    <?php elseif (! $can_view) : ?>
        <div class="eg-empty"><?php esc_html_e('이 계정에는 순위를 볼 수 있는 권한이 없습니다. 관리자 화면의 "역할과 권한"에서 "교육 게임 플레이" 또는 "교육 게임 관리" 권한을 이 역할에 배정해 주세요.', 'edu-games'); ?></div>
    <?php else : ?>
        <div class="eg-team-panel" data-field="team-panel"></div>
        <div class="eg-board-toolbar eg-scope-tabs" data-field="scope-tabs"></div>
        <div class="eg-board-toolbar" data-field="game-tabs"></div>
        <div class="eg-board-status" data-field="status"></div>
        <div class="eg-board-table" data-field="leaderboard"></div>
        <?php if ($can_manage) : ?>
            <section class="eg-team-admin" data-field="team-admin">
                <h3><?php esc_html_e('팀 관리', 'edu-games'); ?></h3>
                <div data-field="team-admin-list"></div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php echo \EduGames\Blocks\Play_Renderer::reports(); ?>
<?php echo \EduGames\Blocks\Learning_Renderer::report(); ?>
<?php echo \EduGames\Blocks\Warehouse_Renderer::report(); ?>
<?php echo \EduGames\Blocks\Strategy_Renderer::reports(); ?>
