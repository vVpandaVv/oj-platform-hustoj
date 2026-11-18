<?php
declare(strict_types=1);

namespace EduGames\Blocks;

use EduGames\Games\Modules\Keyword_Rain\Keyword_Rain_Module;

final class Keyword_Rain_Renderer extends Abstract_Game_Renderer {
    /**
     * @param array<string, mixed> $attributes
     */
    public static function render(array $attributes = array(), bool $is_block = false): string {
        $config = self::build_config(Keyword_Rain_Module::ID, Keyword_Rain_Module::rules());
        $wrapper = self::wrapper_attributes($is_block, 'keyword-rain-block eg-layout-v2');

        ob_start();
        ?>
        <section <?php echo $wrapper; ?> data-keyword-rain data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <div class="oj-rain-shell">
                <header class="oj-rain-topbar">
                    <div>
                        <p class="oj-rain-kicker"><?php esc_html_e('코딩 스킬', 'edu-games'); ?></p>
                        <h2><?php esc_html_e('키워드 레인', 'edu-games'); ?></h2>
                    </div>
                    <div class="oj-rain-actions">
                        <button type="button" class="oj-rain-button oj-rain-button-ghost" data-action="practice"><?php esc_html_e('연습 준비', 'edu-games'); ?></button>
                        <button type="button" class="oj-rain-button oj-rain-button-primary" data-action="start-ranked"><?php esc_html_e('도전 시작', 'edu-games'); ?></button>
                    </div>
                </header>
                <p data-field="challenge-goal" aria-live="polite"></p>

                <div class="oj-rain-modebar" role="tablist" aria-label="<?php esc_attr_e('플레이 방식', 'edu-games'); ?>">
                    <button type="button" class="oj-rain-mode is-active" data-play-type="practice"><?php esc_html_e('연습', 'edu-games'); ?></button>
                    <button type="button" class="oj-rain-mode" data-play-type="ranked"><?php esc_html_e('도전', 'edu-games'); ?></button>
                    <select class="oj-rain-select" data-field="language" aria-label="언어 선택"></select>
                    <select class="oj-rain-select" data-field="difficulty" aria-label="난이도"></select>
                </div>

                <div class="oj-rain-stage" data-game-workspace="rain">
                <div class="oj-rain-stats" aria-label="<?php esc_attr_e('게임 상태', 'edu-games'); ?>">
                    <div class="oj-rain-stat">
                        <span><?php esc_html_e('점수', 'edu-games'); ?></span>
                        <strong data-field="score">0</strong>
                    </div>
                    <div class="oj-rain-stat">
                        <span><?php esc_html_e('정답', 'edu-games'); ?></span>
                        <strong data-field="correct">0</strong>
                    </div>
                    <div class="oj-rain-stat">
                        <span><?php esc_html_e('콤보', 'edu-games'); ?></span>
                        <strong data-field="combo">0</strong>
                    </div>
                    <div class="oj-rain-stat">
                        <span><?php esc_html_e('기회', 'edu-games'); ?></span>
                        <strong data-field="lives">5</strong>
                    </div>
                    <div class="oj-rain-stat">
                        <span><?php esc_html_e('단계', 'edu-games'); ?></span>
                        <strong data-field="level">1</strong>
                    </div>
                    <div class="oj-rain-stat">
                        <span><?php esc_html_e('내 순위', 'edu-games'); ?></span>
                        <strong data-field="rank">-</strong>
                    </div>
                </div>

                <main class="oj-rain-layout">
                    <section class="oj-rain-panel oj-rain-game-panel">
                        <div class="oj-rain-panel-head">
                            <div>
                                <span data-field="meta"><?php esc_html_e('준비', 'edu-games'); ?></span>
                                <h3 data-field="title"><?php esc_html_e('떨어지는 단어를 정확히 입력하세요', 'edu-games'); ?></h3>
                            </div>
                            <span data-field="status"><?php esc_html_e('대기', 'edu-games'); ?></span>
                        </div>

                        <div class="oj-rain-flight">
                        <div class="oj-rain-arena" data-field="arena" tabindex="0" aria-label="<?php esc_attr_e('키워드가 떨어지는 게임 공간', 'edu-games'); ?>">
                            <div class="oj-rain-drop-layer" data-field="drops"></div>
                            <div class="oj-rain-start-card" data-field="start-card">
                                <strong><?php esc_html_e('단어 입력 → 즉시 제거', 'edu-games'); ?></strong>
                                <span><?php esc_html_e('놓치면 기회 −1', 'edu-games'); ?></span>
                            </div>
                        </div>

                        <div class="oj-rain-input-row">
                            <input type="text" class="oj-rain-input" data-field="input" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="<?php esc_attr_e('떨어지는 단어를 입력하세요', 'edu-games'); ?>">
                            <button type="button" class="oj-rain-button oj-rain-button-primary" data-action="start"><?php esc_html_e('시작', 'edu-games'); ?></button>
                        </div>
                        </div>
                        <p class="oj-rain-feedback" data-field="feedback" aria-live="polite"></p>
                    </section>

                    <aside class="oj-rain-side">
                        <section class="oj-rain-panel">
                            <div class="oj-rain-panel-head">
                                <span><?php esc_html_e('규칙', 'edu-games'); ?></span>
                            </div>
                            <div class="oj-rain-rules">
                                <div>
                                    <strong><?php esc_html_e('정확히 입력', 'edu-games'); ?></strong>
                                    <span><?php esc_html_e('대소문자와 기호까지 보이는 그대로 입력하면 즉시 제거됩니다.', 'edu-games'); ?></span>
                                </div>
                                <div>
                                    <strong><?php esc_html_e('기회 관리', 'edu-games'); ?></strong>
                                    <span><?php esc_html_e('도전은 단어를 놓칠 때마다 기회가 줄고, 기회가 0이면 종료됩니다.', 'edu-games'); ?></span>
                                </div>
                                <div>
                                    <strong><?php esc_html_e('난이도 상승', 'edu-games'); ?></strong>
                                    <span><?php esc_html_e('정답을 많이 맞힐수록 등장 간격과 낙하 시간이 조금씩 빨라집니다.', 'edu-games'); ?></span>
                                </div>
                            </div>
                        </section>

                        <section class="oj-rain-panel">
                            <div class="oj-rain-panel-head">
                                <span><?php esc_html_e('순위', 'edu-games'); ?></span>
                                <span data-field="rank-status"><?php esc_html_e('대기', 'edu-games'); ?></span>
                            </div>
                            <div class="oj-rain-leaderboard" data-field="leaderboard"></div>
                        </section>
                    </aside>
                </main>
                </div>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }
}
