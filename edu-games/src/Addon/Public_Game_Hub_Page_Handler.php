<?php
declare(strict_types=1);

namespace EduGames\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use EduGames\Games\Game_Category_Labels;
use EduGames\Games\Game_Visibility;
use EduGames\Support\Capabilities;

final class Public_Game_Hub_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        // Read the raw (visibility-independent) status so an operator/teacher
        // preview bypass can still tell "closed" apart from "not built yet",
        // same distinction Game_Access_Guard/Game_Play_Page_Handler make.
        $all_games = function_exists('edu_games_registry') ? \edu_games_registry()->all(false) : array();
        $can_preview_closed = Capabilities::can_preview_closed();

        // A game an operator closed via the visibility toggle (as opposed to
        // one that's simply not built yet) disappears from the grid
        // entirely for regular players — only the preview bypass still sees
        // it, marked as closed, so it can be checked before reopening it.
        $games = $can_preview_closed
            ? $all_games
            : array_filter(
                $all_games,
                static function (array $game): bool {
                    return 'playable' !== (string) ($game['status'] ?? 'planned')
                        || Game_Visibility::is_open((string) ($game['id'] ?? ''));
                }
            );

        $ranking_link = '<a class="ojp-button ojp-button--tinted" href="' . esc_url(home_url('/oj-addons/edu-games/ranking')) . '">'
            . esc_html__('게임 순위', 'edu-games') . '</a>';

        ob_start();
        ?>
        <div class="edu-games-addon-hub">
            <?php
            echo Page_Response::header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
                __('학습 활동', 'edu-games'),
                __('교육 게임', 'edu-games'),
                __('게임을 골라 바로 시작합니다. 기록은 계정에 저장되고 순위에 반영됩니다.', 'edu-games'),
                $ranking_link
            );
            ?>
            <?php if (empty($games)) : ?>
                <div class="ojp-empty-state"><div><strong><?php esc_html_e('아직 열린 게임이 없습니다.', 'edu-games'); ?></strong><p><?php esc_html_e('선생님이 게임을 공개하면 여기에 나타납니다.', 'edu-games'); ?></p></div></div>
            <?php else : ?>
                <div class="eg-hub-grid">
                    <?php foreach ($games as $game) : ?>
                        <?php
                        $game_id = sanitize_key((string) ($game['id'] ?? ''));
                        $title = (string) ($game['title'] ?? $game_id);
                        $description = (string) ($game['description'] ?? '');
                        $category_label = Game_Category_Labels::label((string) ($game['category'] ?? 'game'));
                        $status = (string) ($game['status'] ?? 'planned');
                        $is_open = Game_Visibility::is_open($game_id);
                        $is_built = 'playable' === $status;
                        $is_playable = $is_built && ($is_open || $can_preview_closed);
                        $status_label = $is_built
                            ? ($is_open ? (string) ($game['statusLabel'] ?? $status) : __('비공개', 'edu-games'))
                            : (string) ($game['statusLabel'] ?? $status);
                        $status_tone = $is_built ? ($is_open ? 'success' : 'warning') : 'neutral';
                        ?>
                        <?php if ($is_playable) : ?>
                            <a class="eg-hub-card<?php echo $is_open ? '' : ' is-closed'; ?>" href="<?php echo esc_url(home_url('/oj-addons/edu-games/games/' . $game_id)); ?>">
                        <?php else : ?>
                            <article class="eg-hub-card is-closed">
                        <?php endif; ?>
                                <div>
                                    <div class="eg-hub-card-meta">
                                        <span class="ojp-status-badge ojp-status-badge--neutral"><?php echo esc_html($category_label); ?></span>
                                        <span class="ojp-status-badge ojp-status-badge--<?php echo esc_attr($status_tone); ?>"><?php echo esc_html($status_label); ?></span>
                                    </div>
                                    <h3><?php echo esc_html($title); ?></h3>
                                    <p><?php echo esc_html($description); ?></p>
                                </div>
                                <span class="eg-hub-open"><?php echo esc_html($is_playable ? __('게임 열기', 'edu-games') : __('준비 중', 'edu-games')); ?></span>
                        <?php if ($is_playable) : ?>
                            </a>
                        <?php else : ?>
                            </article>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php

        return Page_Response::themed(__('교육 게임', 'edu-games'), (string) ob_get_clean());
    }
}
