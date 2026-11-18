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
        $wrapper = self::wrapper_attributes($is_block, 'binary-arcade-block eg-layout-v2');

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
                        <a class="oj-binary-button" data-binary-team-link href="<?php echo esc_url(home_url('/oj-addons/edu-games/binary-team')); ?>"><?php esc_html_e('실시간 팀전', 'edu-games'); ?></a>
                        <button type="button" class="oj-binary-button oj-binary-button-secondary" data-action="practice"><?php esc_html_e('연습 문제 생성', 'edu-games'); ?></button>
                        <button type="button" class="oj-binary-button oj-binary-button-primary" data-action="start"><?php esc_html_e('게임 시작', 'edu-games'); ?></button>
                    </div>
                </header>
                <p data-field="challenge-goal" aria-live="polite"></p>

                <div class="oj-binary-console" data-game-workspace="binary">
                    <aside class="oj-binary-sidebar" aria-label="게임 상태">
                        <div class="oj-binary-sidecard"><span data-field="score-label">점수</span><strong data-field="score">0</strong></div>
                        <div class="oj-binary-sidecard"><span data-field="stage-label">레벨</span><strong data-field="stage">1</strong></div>
                        <div class="oj-binary-sidecard"><span data-field="time-label">남은 줄</span><strong data-field="time">6</strong></div>
                        <div class="oj-binary-sidecard"><span data-field="streak-label">연속 정답</span><strong data-field="streak">0</strong></div>
                        <div class="oj-binary-sidecard"><span data-field="best-label">최고점</span><strong data-field="best">0</strong></div>
                        <div class="oj-binary-sidecard"><span data-field="rank-label">내 순위</span><strong data-field="rank">-</strong></div>
                    </aside>
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

                </div>
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
