<?php
/**
 * @var array<string, mixed> $attributes
 */

if (! defined('ABSPATH')) {
    exit;
}

$config = array(
    'restUrl' => esc_url_raw(rest_url('edu-games/v1')),
    'nonce' => wp_create_nonce('wp_rest'),
    'isLoggedIn' => is_user_logged_in(),
    'playerName' => is_user_logged_in() ? wp_get_current_user()->display_name : __('손님', 'edu-games'),
);
?>
<section <?php echo get_block_wrapper_attributes(array('class' => 'edu-games-block edu-games-block-player-stats')); ?> data-player-stats data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
    <header class="eg-stats-header">
        <p><?php esc_html_e('아케이드·이전 규칙 기록', 'edu-games'); ?></p>
        <h2><?php esc_html_e('학습 게임 통계', 'edu-games'); ?></h2>
    </header>

    <div class="eg-stats-status" data-field="status"><?php esc_html_e('통계를 불러오는 중입니다.', 'edu-games'); ?></div>
    <div class="eg-stats-summary" data-field="summary"></div>
    <div class="eg-stats-layout">
        <section class="eg-stats-panel">
            <h3><?php esc_html_e('게임별 기록', 'edu-games'); ?></h3>
            <div data-field="games"></div>
        </section>
        <section class="eg-stats-panel">
            <h3><?php esc_html_e('최근 실전 기록', 'edu-games'); ?></h3>
            <div data-field="recent"></div>
        </section>
    </div>
</section>
<?php echo \EduGames\Blocks\Learning_Renderer::report(); ?>
<?php echo \EduGames\Blocks\Warehouse_Renderer::report(); ?>
<?php echo \EduGames\Blocks\Strategy_Renderer::reports(); ?>
