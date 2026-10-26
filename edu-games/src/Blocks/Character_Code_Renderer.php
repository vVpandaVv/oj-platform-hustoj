<?php
declare(strict_types=1);

namespace EduGames\Blocks;

use EduGames\Games\Modules\Character_Code\Character_Code_Module;

final class Character_Code_Renderer extends Abstract_Game_Renderer {
    /**
     * @param array<string, mixed> $attributes
     */
    public static function render(array $attributes = array(), bool $is_block = false): string {
        $rules = Character_Code_Module::rules();
        $config = self::build_config(
            Character_Code_Module::ID,
            $rules,
            array(
                'defaultMode' => $rules['defaultMode'],
                'defaultDifficulty' => $rules['defaultDifficulty'],
                'playerName' => is_user_logged_in() ? wp_get_current_user()->display_name : __('손님', 'edu-games'),
            )
        );
        $difficulties = is_array($rules['difficulties']) ? $rules['difficulties'] : array();
        $modes = is_array($rules['modes']) ? $rules['modes'] : array();
        $wrapper = self::wrapper_attributes($is_block, 'character-code-block');

        ob_start();
        ?>
        <section <?php echo $wrapper; ?> data-character-code data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <div class="oj-code-shell">
                <header class="oj-code-topbar">
                    <div class="oj-code-brand">
                        <div class="oj-code-mark" aria-hidden="true">
                            <svg viewBox="0 0 64 64" focusable="false">
                                <circle cx="32" cy="32" r="28"></circle>
                                <circle cx="32" cy="32" r="17"></circle>
                                <path d="M32 4v12M32 48v12M4 32h12M48 32h12M14 14l8 8M42 42l8 8M50 14l-8 8M22 42l-8 8"></path>
                                <text x="32" y="37" text-anchor="middle">A</text>
                            </svg>
                        </div>
                        <div>
                            <p class="oj-code-kicker">암호 실험실</p>
                            <h2>문자 코드 암호 게임</h2>
                        </div>
                    </div>

                    <div class="oj-code-actions">
                        <select class="oj-code-select" data-field="difficulty" aria-label="난이도">
                            <?php foreach ($difficulties as $difficulty_id => $difficulty) : ?>
                                <option value="<?php echo esc_attr((string) $difficulty_id); ?>"><?php echo esc_html((string) ($difficulty['label'] ?? $difficulty_id)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="oj-code-button" data-action="hint">힌트</button>
                        <button type="button" class="oj-code-button" data-action="new-round">새 연습 문제</button>
                        <button type="button" class="oj-code-button is-primary" data-action="start-ranked">실전 시작</button>
                    </div>
                </header>
                <p data-field="challenge-goal" aria-live="polite"></p>

                <div class="oj-code-typebar" role="tablist" aria-label="플레이 방식">
                    <button type="button" class="oj-code-type is-active" data-play-type="practice">연습 모드</button>
                    <button type="button" class="oj-code-type" data-play-type="ranked">실전 게임</button>
                </div>

                <div class="oj-code-modebar" role="tablist" aria-label="연습 문제 유형" data-field="practice-modes">
                    <?php foreach ($modes as $mode_id => $mode) : ?>
                        <button type="button" class="oj-code-mode<?php echo $mode_id === $rules['defaultMode'] ? ' is-active' : ''; ?>" data-mode="<?php echo esc_attr((string) $mode_id); ?>"><?php echo esc_html((string) ($mode['label'] ?? $mode_id)); ?></button>
                    <?php endforeach; ?>
                </div>

                <div class="oj-code-stats" aria-label="게임 상태">
                    <div class="oj-code-stat">
                        <span>점수</span>
                        <strong data-field="score">0</strong>
                    </div>
                    <div class="oj-code-stat">
                        <span data-field="progress-label">연습 번호</span>
                        <strong data-field="round">1</strong>
                    </div>
                    <div class="oj-code-stat">
                        <span>연속 정답</span>
                        <strong data-field="streak">0</strong>
                    </div>
                    <div class="oj-code-stat">
                        <span>최고점</span>
                        <strong data-field="best">0</strong>
                    </div>
                </div>

                <main class="oj-code-layout">
                    <section class="oj-code-panel oj-code-problem-panel">
                        <div class="oj-code-panel-head">
                            <span data-field="mode-label">Decode</span>
                            <span data-field="difficulty-label">Easy</span>
                        </div>
                        <div class="oj-code-prompt" data-field="prompt"></div>
                        <div class="oj-code-answer" data-field="answer"></div>
                        <div class="oj-code-submit-row">
                            <button type="button" class="oj-code-button is-primary" data-action="submit">제출</button>
                            <p class="oj-code-feedback" data-field="feedback" aria-live="polite"></p>
                            <p data-field="phrase-meta"></p>
                            <p>이 활동은 임의의 문자 치환표입니다. ASCII·유니코드 표준이나 안전한 암호 방식과는 다릅니다.</p>
                        </div>
                    </section>

                    <aside class="oj-code-side">
                        <section class="oj-code-panel oj-code-table-panel">
                            <div class="oj-code-panel-head">
                                <span>코드표</span>
                                <span data-field="table-count">37</span>
                            </div>
                            <div class="oj-code-table" data-field="code-table"></div>
                        </section>

                        <section class="oj-code-panel oj-code-rank-panel">
                            <div class="oj-code-panel-head">
                                <span>실전 순위</span>
                                <span data-field="rank-status">대기</span>
                            </div>
                            <div class="oj-code-leaderboard" data-field="leaderboard"></div>
                        </section>
                    </aside>
                </main>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }
}
