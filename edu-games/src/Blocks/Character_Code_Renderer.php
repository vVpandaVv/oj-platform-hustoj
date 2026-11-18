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
        $wrapper = self::wrapper_attributes($is_block, 'character-code-block oj-code-arcade-ui');
        $icons = plugins_url('assets/shell/icons.svg', EDU_GAMES_FILE);

        ob_start();
        ?>
        <section <?php echo $wrapper; ?> data-character-code data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <div class="oj-code-shell">
                <header class="oj-code-topbar">
                    <div class="oj-code-brand">
                        <div>
                            <h2>문자 코드 연습</h2>
                        </div>
                    </div>

                    <div class="oj-code-actions">
                        <select class="oj-code-select" data-field="difficulty" aria-label="난이도">
                            <?php foreach ($difficulties as $difficulty_id => $difficulty) : ?>
                                <option value="<?php echo esc_attr((string) $difficulty_id); ?>"><?php echo esc_html((string) ($difficulty['label'] ?? $difficulty_id)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="oj-code-button oj-code-tool" data-action="hint" aria-label="연습 힌트" title="연습 힌트">
                            <svg aria-hidden="true"><use href="<?php echo esc_url($icons); ?>#lightbulb"></use></svg>
                        </button>
                        <button type="button" class="oj-code-button oj-code-tool" data-action="new-round" aria-label="새 연습 문제" title="새 연습 문제">
                            <svg aria-hidden="true"><use href="<?php echo esc_url($icons); ?>#refresh-cw"></use></svg>
                        </button>
                        <button type="button" class="oj-code-button is-primary" data-action="start-ranked">이전 실전 시작</button>
                    </div>
                </header>
                <?php echo Game_Shell_Renderer::cipher_navigation('practice'); ?>

                <div class="oj-code-controls">
                <div class="oj-code-typebar" role="tablist" aria-label="플레이 방식">
                    <button type="button" class="oj-code-type is-active" data-play-type="practice">연습 모드</button>
                    <button type="button" class="oj-code-type" data-play-type="ranked">이전 실전</button>
                </div>

                <div class="oj-code-modebar" role="tablist" aria-label="연습 문제 유형" data-field="practice-modes">
                    <?php foreach ($modes as $mode_id => $mode) : ?>
                        <button type="button" class="oj-code-mode<?php echo $mode_id === $rules['defaultMode'] ? ' is-active' : ''; ?>" data-mode="<?php echo esc_attr((string) $mode_id); ?>"><?php echo esc_html((string) ($mode['label'] ?? $mode_id)); ?></button>
                    <?php endforeach; ?>
                </div>
                </div>

                <div class="oj-code-stats" aria-label="게임 상태">
                    <div class="oj-code-stat">
                        <span>점수 <small class="oj-code-combo">연속 <b data-field="streak">0</b></small></span>
                        <strong data-field="score">0</strong>
                    </div>
                    <div class="oj-code-stat">
                        <span data-field="progress-label">연습 번호</span>
                        <strong data-field="round">1</strong>
                    </div>
                    <div class="oj-code-stat">
                        <span>최고점</span>
                        <strong data-field="best">0</strong>
                    </div>
                </div>
                <div class="oj-code-time-track" data-field="time-track" role="progressbar" aria-label="남은 문제 시간" aria-valuemin="0" aria-valuemax="100" aria-valuenow="100" hidden><span data-field="time-fill"></span></div>

                <main class="oj-code-layout">
                    <section class="oj-code-panel oj-code-problem-panel oj-code-board" aria-label="암호 게임판">
                        <div class="oj-code-panel-head">
                            <span data-field="mode-label">Decode</span>
                            <span data-field="difficulty-label">Easy</span>
                        </div>
                        <h3 data-field="challenge-goal"></h3>
                        <div class="oj-code-prompt" data-field="prompt"></div>
                        <div class="oj-code-answer" data-field="answer"></div>
                        <div class="oj-code-submit-row">
                            <button type="button" class="oj-code-button is-primary" data-action="submit"><span data-field="submit-label">확인</span><svg aria-hidden="true"><use href="<?php echo esc_url($icons); ?>#arrow-right"></use></svg></button>
                            <output class="oj-code-score-burst" data-field="score-burst" aria-label="획득 점수"></output>
                        </div>
                        <p class="oj-code-feedback" data-field="feedback" role="status" aria-live="polite"></p>
                        <small class="oj-code-score-detail" data-field="score-detail"></small>
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
                <details class="oj-code-help eg-game-disclosure" data-shell-section="help">
                    <summary>게임 도움말</summary>
                    <p>복호화는 숫자를 글자로, 암호화는 글자를 숫자로 바꿉니다. 코드표 복원은 제시된 글자·숫자 쌍으로 빈 대응을 채웁니다. 공백도 코드 00으로 입력합니다.</p>
                    <p>실전은 정답을 맞히면 다음 문제의 시간이 다시 채워집니다. 오답 제출 또는 시간 초과로 종료되며, 점수는 기존 기준대로 기본·문자 길이·난도·연속 정답을 반영합니다. 연습 힌트와 오답은 연습 점수에서 감점합니다.</p>
                    <p>코드표는 모든 판에서 같습니다. 학습용 문자 코드이며 ASCII·유니코드 표준이나 보안용 암호는 아닙니다.</p>
                </details>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }
}
