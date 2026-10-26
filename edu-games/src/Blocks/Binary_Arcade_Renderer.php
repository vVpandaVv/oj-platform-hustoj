<?php
declare(strict_types=1);

namespace EduGames\Blocks;

use EduGames\Games\Modules\Binary_Conversion\Binary_Conversion_Module;

final class Binary_Arcade_Renderer extends Abstract_Game_Renderer {
    /**
     * @param array<string, mixed> $attributes
     */
    public static function render(array $attributes = array(), bool $is_block = false): string {
        $config = self::build_config(Binary_Conversion_Module::ID, Binary_Conversion_Module::rules());
        $wrapper = self::wrapper_attributes($is_block, 'binary-arcade-block');

        ob_start();
        ?>
        <section <?php echo $wrapper; ?> data-binary-arcade data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <div class="oj-binary-shell">
                <header class="oj-binary-topbar">
                    <div class="oj-binary-brand">
                        <p class="oj-binary-kicker"><?php esc_html_e('데이터 기초', 'edu-games'); ?></p>
                        <h2><?php esc_html_e('이진수 변환 아케이드', 'edu-games'); ?></h2>
                    </div>
                    <div class="oj-binary-actions">
                        <button type="button" class="oj-binary-button oj-binary-button-secondary" data-action="practice"><?php esc_html_e('연습 문제 생성', 'edu-games'); ?></button>
                        <button type="button" class="oj-binary-button oj-binary-button-primary" data-action="start"><?php esc_html_e('게임 시작', 'edu-games'); ?></button>
                    </div>
                </header>
                <p data-field="challenge-goal" aria-live="polite"></p>

                <div class="oj-binary-layout">
                    <section class="oj-binary-board" aria-label="<?php esc_attr_e('이진수 게임 보드', 'edu-games'); ?>">
                        <div class="oj-binary-board-head" data-field="grid-head"></div>
                        <div class="oj-binary-danger-line">
                            <span><?php esc_html_e('위험선', 'edu-games'); ?></span>
                        </div>
                        <div class="oj-binary-lanes" data-field="lanes"></div>
                        <div class="oj-binary-board-foot" data-field="grid-foot"></div>
                        <output data-field="place-sum" class="oj-binary-place-sum" aria-live="polite"></output>

                        <div class="oj-binary-overlay is-visible" data-field="overlay">
                            <div class="oj-binary-overlay-card">
                                <p data-field="overlay-title"><?php esc_html_e('준비', 'edu-games'); ?></p>
                                <strong data-field="overlay-score">0</strong>
                                <span class="oj-binary-save-status" data-field="save-status"></span>
                                <div class="oj-binary-overlay-actions">
                                    <button type="button" class="oj-binary-button oj-binary-button-secondary" data-action="practice"><?php esc_html_e('연습 문제 생성', 'edu-games'); ?></button>
                                    <button type="button" class="oj-binary-button oj-binary-button-primary" data-action="restart"><?php esc_html_e('게임 시작', 'edu-games'); ?></button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <aside class="oj-binary-sidebar" aria-label="<?php esc_attr_e('게임 상태', 'edu-games'); ?>">
                        <div class="oj-binary-sidecard">
                            <span data-field="score-label"><?php esc_html_e('점수', 'edu-games'); ?></span>
                            <strong data-field="score">0</strong>
                        </div>
                        <div class="oj-binary-sidecard">
                            <span data-field="stage-label"><?php esc_html_e('레벨', 'edu-games'); ?></span>
                            <strong data-field="stage">1</strong>
                        </div>
                        <div class="oj-binary-sidecard">
                            <span data-field="time-label"><?php esc_html_e('남은 줄', 'edu-games'); ?></span>
                            <strong data-field="time">6</strong>
                        </div>
                        <div class="oj-binary-sidecard">
                            <span data-field="streak-label"><?php esc_html_e('연속 정답', 'edu-games'); ?></span>
                            <strong data-field="streak">0</strong>
                        </div>
                        <div class="oj-binary-sidecard">
                            <span data-field="best-label"><?php esc_html_e('최고점', 'edu-games'); ?></span>
                            <strong data-field="best">0</strong>
                        </div>
                        <div class="oj-binary-sidecard">
                            <span data-field="rank-label"><?php esc_html_e('내 순위', 'edu-games'); ?></span>
                            <strong data-field="rank">-</strong>
                        </div>
                    </aside>
                </div>

                <section class="oj-binary-rank-panel" aria-label="<?php esc_attr_e('실전 순위', 'edu-games'); ?>">
                    <div class="oj-binary-rank-head">
                        <span><?php esc_html_e('전체 순위', 'edu-games'); ?></span>
                        <span data-field="rank-status"><?php esc_html_e('대기', 'edu-games'); ?></span>
                    </div>
                    <div class="oj-binary-leaderboard" data-field="leaderboard"></div>
                </section>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }
}
