<?php
/**
 * @var array<string, mixed> $attributes
 */

if (! defined('ABSPATH')) {
    exit;
}

$show_technical_details = function_exists('is_super_admin') && is_super_admin();
$registered_games = function_exists('edu_games_registry') ? edu_games_registry()->all() : array();
$games = array_filter(
    $registered_games,
    static function (array $game) use ($show_technical_details): bool {
        return $show_technical_details || 'closed' !== (string) ($game['status'] ?? 'planned');
    }
);
$selected_game_id = '';

foreach (array('edu_game', 'edu_game') as $query_key) {
    if (isset($_GET[$query_key])) {
        $selected_game_id = sanitize_key((string) wp_unslash($_GET[$query_key]));
        break;
    }
}

$base_url = is_singular() ? get_permalink() : remove_query_arg(array('edu_game', 'edu_game'));
if (! is_string($base_url) || '' === $base_url) {
    $base_url = home_url('/');
}

if ('' !== $selected_game_id && isset($games[$selected_game_id])) :
    $selected_game = $games[$selected_game_id];
    $selected_title = (string) ($selected_game['title'] ?? $selected_game_id);
    $selected_description = (string) ($selected_game['description'] ?? '');
    $selected_status = (string) ($selected_game['status'] ?? 'planned');
    $selected_block = (string) ($selected_game['block'] ?? '');
    $selected_shortcode = (string) ($selected_game['shortcode'] ?? '');
    ?>
    <section <?php echo get_block_wrapper_attributes(array('class' => 'platform-page page-shell edu-games-block edu-games-block-game-hub is-game-page')); ?>>
        <header class="eg-game-page-header">
            <a class="eg-game-back" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('게임 허브로 돌아가기', 'edu-games'); ?></a>
            <h2><?php echo esc_html($selected_title); ?></h2>
            <?php if ('' !== $selected_description) : ?>
                <p><?php echo esc_html($selected_description); ?></p>
            <?php endif; ?>
        </header>

        <?php if ('playable' === $selected_status && '' !== $selected_block && preg_match('/^[a-z0-9-]+\/[a-z0-9-]+$/', $selected_block)) : ?>
            <div class="eg-game-page-stage">
                <?php echo do_blocks('<!-- wp:' . $selected_block . ' /-->'); ?>
            </div>
        <?php elseif ('playable' === $selected_status && '' !== $selected_shortcode && preg_match('/^[A-Za-z0-9_-]+$/', $selected_shortcode)) : ?>
            <div class="eg-game-page-stage">
                <?php echo do_shortcode('[' . $selected_shortcode . ']'); ?>
            </div>
        <?php else : ?>
            <div class="eg-empty"><?php esc_html_e('아직 플레이할 수 없는 게임입니다.', 'edu-games'); ?></div>
        <?php endif; ?>
    </section>
    <?php
    return;
endif;
?>
<section <?php echo get_block_wrapper_attributes(array('class' => 'platform-page page-shell edu-games-block edu-games-block-game-hub')); ?>>
    <header class="eg-hub-header">
        <p><?php esc_html_e('Edu Games', 'edu-games'); ?></p>
        <h2><?php esc_html_e('학습 게임 허브', 'edu-games'); ?></h2>
    </header>

    <?php if (empty($games)) : ?>
        <div class="eg-empty"><?php esc_html_e('등록된 게임 모듈이 없습니다.', 'edu-games'); ?></div>
    <?php else : ?>
        <div class="eg-hub-grid">
            <?php foreach ($games as $game) : ?>
                <?php
                $title = (string) ($game['title'] ?? $game['id']);
                $description = (string) ($game['description'] ?? __('준비 중인 학습 게임입니다.', 'edu-games'));
                $block = (string) ($game['block'] ?? '');
                $shortcode = (string) ($game['shortcode'] ?? '');
                $category = (string) ($game['category'] ?? 'game');
                $category_label = \EduGames\Games\Game_Category_Labels::label($category);
                $status = (string) ($game['status'] ?? 'planned');
                $status_label = (string) ($game['statusLabel'] ?? ('playable' === $status ? __('플레이 가능', 'edu-games') : __('준비 중', 'edu-games')));
                $card_class = 'eg-hub-card is-' . sanitize_html_class($status);
                $is_clickable = 'playable' === $status && ('' !== $block || '' !== $shortcode);
                $game_url = $is_clickable ? add_query_arg('edu_game', rawurlencode((string) ($game['id'] ?? '')), $base_url) : '';
                ?>
                <?php if ($is_clickable) : ?>
                    <a class="<?php echo esc_attr($card_class); ?>" href="<?php echo esc_url($game_url); ?>">
                <?php else : ?>
                    <article class="<?php echo esc_attr($card_class); ?>">
                <?php endif; ?>
                    <div>
                        <div class="eg-hub-card-meta">
                            <span class="eg-hub-category"><?php echo esc_html($category_label); ?></span>
                            <span class="eg-hub-status"><?php echo esc_html($status_label); ?></span>
                        </div>
                        <h3><?php echo esc_html($title); ?></h3>
                        <p><?php echo esc_html($description); ?></p>
                    </div>
                    <?php if ($show_technical_details) : ?>
                        <dl>
                            <?php if ('' !== $block) : ?>
                                <div>
                                    <dt><?php esc_html_e('블록', 'edu-games'); ?></dt>
                                    <dd><?php echo esc_html($block); ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if ('' !== $shortcode) : ?>
                                <div>
                                    <dt><?php esc_html_e('숏코드', 'edu-games'); ?></dt>
                                    <dd>[<?php echo esc_html($shortcode); ?>]</dd>
                                </div>
                            <?php endif; ?>
                            <?php if ('' === $block && '' === $shortcode) : ?>
                                <div>
                                    <dt><?php esc_html_e('상태', 'edu-games'); ?></dt>
                                    <dd><?php esc_html_e('게임 화면 개발 예정', 'edu-games'); ?></dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                    <?php endif; ?>
                    <span class="eg-hub-open"><?php echo esc_html($is_clickable ? __('게임 열기', 'edu-games') : __('준비 중', 'edu-games')); ?></span>
                <?php if ($is_clickable) : ?>
                    </a>
                <?php else : ?>
                    </article>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
