<?php
declare(strict_types=1);

namespace EduGames\Blocks;

use EduGames\Games\Modules\Code_Typing\Code_Typing_Module;

final class Code_Typing_Renderer extends Abstract_Game_Renderer {
    /**
     * @param array<string, mixed> $attributes
     */
    public static function render(array $attributes = array(), bool $is_block = false): string {
        $config = self::build_config(Code_Typing_Module::ID, Code_Typing_Module::rules(),array('iconsUrl'=>plugins_url('assets/shell/icons.svg',EDU_GAMES_FILE)));
        $wrapper = self::wrapper_attributes($is_block, 'code-typing-block eg-layout-v2');

        ob_start();
        ?>
        <section <?php echo $wrapper; ?> data-code-typing data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <div class="oj-typing-shell">
                <header class="oj-typing-topbar">
                    <div>
                        <p class="oj-typing-kicker"><?php esc_html_e('코딩 스킬', 'edu-games'); ?></p>
                        <h2><?php esc_html_e('코드 타이핑', 'edu-games'); ?></h2>
                    </div>
                    <div class="oj-typing-actions">
                        <button type="button" class="oj-typing-button oj-typing-button-ghost" data-action="reset"><?php esc_html_e('초기화', 'edu-games'); ?></button>
                        <button type="button" class="oj-typing-button oj-typing-button-primary" data-action="start"><?php esc_html_e('입력 준비', 'edu-games'); ?></button>
                    </div>
                </header>
                <details class="oj-typing-classroom" data-typing-assignment-panel>
                    <summary><svg aria-hidden="true"><use href="<?php echo esc_url(plugins_url('assets/shell/icons.svg',EDU_GAMES_FILE)); ?>#users"></use></svg><strong>학급 검정</strong><b data-typing-assignment-count>…</b><span data-typing-entry-status></span></summary>
                    <section class="edu-typing-entry oj-typing-assignments" data-typing-assignments aria-label="내 수행평가"><div class="ete-student-loading" role="status">평가 조회 중</div></section>
                </details>
                <p data-field="challenge-goal" aria-live="polite"></p>

                <div class="oj-typing-workbench" data-game-workspace="typing">
                <div class="oj-typing-controls" aria-label="<?php esc_attr_e('코드 타이핑 설정', 'edu-games'); ?>">
                    <label>
                        <span><?php esc_html_e('언어', 'edu-games'); ?></span>
                        <select data-field="language"></select>
                    </label>
                    <div class="oj-typing-mode-tabs" data-field="modes" role="tablist"></div>
                    <label class="oj-typing-sample-select">
                        <span><?php esc_html_e('예제 코드', 'edu-games'); ?></span>
                        <select data-field="sample"></select>
                    </label>
                    <label class="oj-typing-line-ending-select">
                        <span>줄 끝 입력</span>
                        <select data-field="line-ending" aria-label="줄 끝 입력"><option value="enter">Enter만</option><option value="space-enter">Space·Enter 허용</option></select>
                    </label>
                </div>

                <div class="oj-typing-stats" aria-label="<?php esc_attr_e('타이핑 기록', 'edu-games'); ?>">
                    <div class="oj-typing-stat oj-typing-speed-stat">
                        <span><?php esc_html_e('유효 CPM', 'edu-games'); ?></span>
                        <strong data-field="speed">0 CPM</strong>
                    </div>
                    <div class="oj-typing-stat">
                        <span><?php esc_html_e('입력 정확도', 'edu-games'); ?></span>
                        <strong data-field="accuracy">100%</strong>
                    </div>
                    <div class="oj-typing-stat">
                        <span><?php esc_html_e('진행률', 'edu-games'); ?></span>
                        <strong data-field="progress">0%</strong>
                    </div>
                    <div class="oj-typing-stat">
                        <span><?php esc_html_e('오타', 'edu-games'); ?></span>
                        <strong data-field="mistakes">0</strong>
                    </div>
                    <div class="oj-typing-stat">
                        <span><?php esc_html_e('완료 코드', 'edu-games'); ?></span>
                        <strong data-field="samples">0</strong>
                    </div>
                    <div class="oj-typing-stat">
                        <span><?php esc_html_e('점수', 'edu-games'); ?></span>
                        <strong data-field="score">0</strong>
                    </div>
                    <div class="oj-typing-stat">
                        <span><?php esc_html_e('내 순위', 'edu-games'); ?></span>
                        <strong data-field="rank">-</strong>
                    </div>
                </div>

                <section class="oj-typing-stream-panel" aria-label="<?php esc_attr_e('코드 입력', 'edu-games'); ?>">
                    <div class="oj-typing-panel-head">
                        <div>
                            <span data-field="sample-meta"></span>
                            <h3 data-field="sample-title"></h3>
                        </div>
                        <b data-field="time">0:00</b>
                    </div>

                    <div class="oj-typing-stream" data-field="stream"></div>

                    <!--
                        This textarea is the real, focused input that receives
                        every keystroke — it is intentionally invisible
                        (see .oj-typing-input-capture). Everything the player
                        sees is the rendered stream above, built from its
                        value on every input event (see view.js#renderStream).
                    -->
                    <textarea data-field="input" class="oj-typing-input-capture" rows="1" spellcheck="false" autocomplete="off" autocapitalize="off" aria-label="<?php esc_attr_e('타자 입력', 'edu-games'); ?>"></textarea>
                    <div class="oj-typing-feedback" data-field="feedback"><?php esc_html_e('언어와 예제를 고른 뒤 첫 글자를 입력하세요.', 'edu-games'); ?></div>
                    <button type="button" class="oj-typing-button" data-typing-retry data-verified-retry hidden>연결·저장 재시도</button>
                    <button type="button" class="oj-typing-button" data-action="typing-error" data-typing-error-location hidden title="첫 오타 위치로 이동" aria-label="첫 오타 위치로 이동"><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><use href="<?php echo esc_url(plugins_url('assets/shell/icons.svg',EDU_GAMES_FILE)); ?>#search"></use></svg></button>
                </section>
                </div>

                <div class="oj-typing-keyboard" data-field="keyboard" aria-hidden="true"></div>
                <details data-field="expected-output" hidden><summary>제시 코드의 예상 출력 · 표준 입력 없음</summary><pre></pre></details>

                <section class="oj-typing-rank-panel" aria-label="<?php esc_attr_e('검정 순위', 'edu-games'); ?>">
                    <p><a href="<?php echo esc_url(home_url('/oj-addons/edu-games/ranking?game=code-typing&records=arcade')); ?>" class="oj-typing-legacy-link">이전 검정 기록·순위</a></p>
                    <div class="oj-typing-rank-head">
                        <span><?php esc_html_e('전체 순위', 'edu-games'); ?></span>
                        <span data-field="rank-status"><?php esc_html_e('대기', 'edu-games'); ?></span>
                    </div>
                    <div class="oj-typing-leaderboard" data-field="leaderboard"></div>
                </section>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }
}
