<?php
declare(strict_types=1);

namespace TeachingTools\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/**
 * Single entry point for every tool's live/participant mode. Logged-in
 * learners land here (via a typed code or a code-prefilled link), the page
 * exchanges the code for the session over REST, and renders the participant
 * view matching the returned toolType. Unsupported future tools fall back to
 * the neutral "coming soon" view.
 */
final class Public_Join_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $query = $request->query();
        $raw_code = isset($query['code']) && is_string($query['code']) ? $query['code'] : '';
        $prefill_code = strtoupper(sanitize_text_field($raw_code));

        $config = array(
            'restUrl' => esc_url_raw(rest_url('teaching-tools/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'prefillCode' => $prefill_code,
            'currentStudentId' => get_current_user_id(),
        );

        ob_start();
        ?>
        <div class="htt-join" data-htt-join data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <div class="htt-join-form" data-field="form">
                <header class="ojp-page-header htt-join-header">
                    <div>
                        <p class="ojp-eyebrow"><?php esc_html_e('수업', 'teaching-tools'); ?></p>
                        <h1><?php esc_html_e('수업 참여하기', 'teaching-tools'); ?></h1>
                        <p><?php esc_html_e('선생님이 알려준 참여 코드를 입력하세요.', 'teaching-tools'); ?></p>
                    </div>
                </header>
                <p class="ojp-notice ojp-notice--info" data-field="participation-notice" role="status" hidden></p>
                <label>
                    <?php esc_html_e('참여 코드', 'teaching-tools'); ?>
                    <input type="text" maxlength="6" autocomplete="off" data-field="code" placeholder="ABCD12">
                </label>
                <button type="button" class="ojp-button ojp-button--primary ojp-button--large htt-primary-action" data-action="join"><?php esc_html_e('참여하기', 'teaching-tools'); ?></button>
                <p data-field="error" class="ojp-notice ojp-notice--danger htt-join-error" role="alert" hidden></p>
            </div>

            <nav class="htt-assigned-list" data-field="assigned-sessions" aria-label="<?php echo esc_attr__('배정된 활동', 'teaching-tools'); ?>" hidden></nav>

            <div class="htt-join-view" data-field="view" hidden>
                <div class="htt-participation-controls">
                    <strong data-field="participation-title"></strong>
                    <div class="htt-participation-actions">
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="student-fullscreen" aria-label="<?php echo esc_attr__('전체화면', 'teaching-tools'); ?>" title="<?php echo esc_attr__('전체화면', 'teaching-tools'); ?>" aria-pressed="false"><?php echo Ui::icon('fullscreen'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <button type="button" class="ojp-button ojp-button--plain" data-action="leave-session"><?php echo Ui::icon('close'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e('수업에서 나가기', 'teaching-tools'); ?></button>
                    </div>
                </div>
                <div data-field="timer-view" class="htt-join-timer" hidden>
                    <div class="htt-timer-clock" data-field="timer-clock">00:00</div>
                    <div class="htt-timer-ended" data-field="timer-ended" hidden><?php esc_html_e('시간이 종료되었습니다', 'teaching-tools'); ?></div>
                </div>
                <div data-field="seating-view" class="htt-join-seating" hidden>
                    <p data-field="seating-my-seat"></p>
                    <div class="htt-seat-board" data-field="seating-board"></div>
                </div>
                <div data-field="picker-view" class="htt-join-picker" hidden>
                    <p data-field="picker-you"></p>
                    <div class="htt-picker-latest" data-field="picker-latest"></div>
                </div>
                <div data-field="ladder-view" class="htt-join-ladder" hidden>
                    <div data-field="ladder-pick" hidden>
                        <p><?php esc_html_e('시작할 줄을 선택하세요.', 'teaching-tools'); ?></p>
                        <div class="htt-join-ladder-columns" data-field="ladder-columns"></div>
                    </div>
                    <div data-field="ladder-animation" hidden></div>
                    <div data-field="ladder-result" class="htt-join-ladder-result" hidden></div>
                </div>
                <div data-field="whiteboard-view" class="htt-join-whiteboard" hidden>
                    <p class="htt-whiteboard-connection" data-field="whiteboard-connection" role="status"></p>
                    <div class="htt-whiteboard-material-error" data-field="whiteboard-material-error" role="status" hidden><span data-field="whiteboard-material-message"></span><button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="retry-whiteboard-material" aria-label="<?php echo esc_attr__('PDF 배경 다시 불러오기', 'teaching-tools'); ?>" title="<?php echo esc_attr__('PDF 배경 다시 불러오기', 'teaching-tools'); ?>"><?php echo Ui::icon('reset'); ?></button></div>
                    <div class="htt-whiteboard-view-controls" role="toolbar" aria-label="<?php echo esc_attr__('판서 보기 설정', 'teaching-tools'); ?>">
                        <select data-field="whiteboard-fit" aria-label="<?php echo esc_attr__('맞춤 방식', 'teaching-tools'); ?>">
                            <option value="page"><?php esc_html_e('화면 맞춤', 'teaching-tools'); ?></option>
                            <option value="width"><?php esc_html_e('너비 맞춤', 'teaching-tools'); ?></option>
                            <option value="board"><?php esc_html_e('칠판 전체', 'teaching-tools'); ?></option>
                        </select>
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="whiteboard-zoom-out" aria-label="<?php echo esc_attr__('축소', 'teaching-tools'); ?>" title="<?php echo esc_attr__('축소', 'teaching-tools'); ?>"><?php echo Ui::icon('minus'); ?></button>
                        <input type="range" min="25" max="400" step="25" value="100" data-field="whiteboard-zoom" aria-label="<?php echo esc_attr__('확대 비율', 'teaching-tools'); ?>">
                        <output data-field="whiteboard-zoom-value" aria-live="polite">100%</output>
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="whiteboard-zoom-in" aria-label="<?php echo esc_attr__('확대', 'teaching-tools'); ?>" title="<?php echo esc_attr__('확대', 'teaching-tools'); ?>"><?php echo Ui::icon('plus'); ?></button>
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="whiteboard-zoom-reset" aria-label="<?php echo esc_attr__('맞춤 크기로 초기화', 'teaching-tools'); ?>" title="<?php echo esc_attr__('맞춤 크기로 초기화', 'teaching-tools'); ?>"><?php echo Ui::icon('reset'); ?></button>
                    </div>
                    <div class="htt-whiteboard-viewport" data-field="whiteboard-viewport" tabindex="0" role="region" aria-label="<?php echo esc_attr__('판서와 PDF', 'teaching-tools'); ?>">
                    <div class="htt-whiteboard-view-stage" data-field="whiteboard-stage">
                    <div class="htt-join-whiteboard-surface">
                    <canvas data-field="whiteboard-material-frame" class="htt-whiteboard-material-frame" width="1000" height="600" aria-label="<?php echo esc_attr__('학습자료 PDF 배경', 'teaching-tools'); ?>" hidden></canvas>
                    <canvas data-field="whiteboard-canvas" width="1000" height="600"></canvas>
                    </div>
                    </div>
                    </div>
                </div>
                <div data-field="quickboard-view" class="htt-join-quickboard" hidden>
                    <h2 data-field="quickboard-prompt"></h2>
                    <div class="htt-join-quickboard-toolbar">
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button is-active" data-quickboard-tool="pen" aria-label="<?php echo esc_attr__('펜', 'teaching-tools'); ?>" title="<?php echo esc_attr__('펜', 'teaching-tools'); ?>"><?php echo Ui::icon('pen'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-quickboard-tool="eraser" aria-label="<?php echo esc_attr__('지우개', 'teaching-tools'); ?>" title="<?php echo esc_attr__('지우개', 'teaching-tools'); ?>"><?php echo Ui::icon('eraser'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <span class="htt-join-quickboard-colors" data-field="quickboard-colors">
                            <button type="button" class="htt-join-quickboard-color is-active" data-color="#1a1a1a" style="background:#1a1a1a" aria-label="검정"></button>
                            <button type="button" class="htt-join-quickboard-color" data-color="#e03131" style="background:#e03131" aria-label="빨강"></button>
                            <button type="button" class="htt-join-quickboard-color" data-color="#1971c2" style="background:#1971c2" aria-label="파랑"></button>
                            <button type="button" class="htt-join-quickboard-color" data-color="#2f9e44" style="background:#2f9e44" aria-label="초록"></button>
                            <button type="button" class="htt-join-quickboard-color" data-color="#f08c00" style="background:#f08c00" aria-label="주황"></button>
                        </span>
                        <label><?php esc_html_e('굵기', 'teaching-tools'); ?><input type="range" min="2" max="18" value="4" data-field="quickboard-width"></label>
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="quickboard-undo" aria-label="<?php echo esc_attr__('되돌리기', 'teaching-tools'); ?>" title="<?php echo esc_attr__('되돌리기', 'teaching-tools'); ?>"><?php echo Ui::icon('undo'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="quickboard-clear" aria-label="<?php echo esc_attr__('모두 지우기', 'teaching-tools'); ?>" title="<?php echo esc_attr__('모두 지우기', 'teaching-tools'); ?>"><?php echo Ui::icon('trash'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                    </div>
                    <div class="htt-join-quickboard-canvas-wrap"><canvas data-field="quickboard-canvas" width="1000" height="600"></canvas></div>
                    <div class="htt-join-quickboard-actions">
                        <span class="htt-join-quickboard-status" data-field="quickboard-status" role="status"><?php esc_html_e('그린 뒤 제출하세요.', 'teaching-tools'); ?></span>
                        <button type="button" class="ojp-button ojp-button--primary" data-action="quickboard-submit"><?php esc_html_e('판서 제출', 'teaching-tools'); ?></button>
                    </div>
                </div>
                <div data-field="quiz-view" class="htt-join-quiz" hidden>
                    <p class="htt-quiz-question" data-field="quiz-question"></p>
                    <div data-field="quiz-options" class="htt-join-quiz-options"></div>
                    <div data-field="quiz-scale-wrap" hidden>
                        <div data-field="quiz-scale" class="htt-join-quiz-scale"></div>
                        <div class="htt-join-quiz-scale-labels"><span data-field="quiz-scale-low"></span><span data-field="quiz-scale-high"></span></div>
                    </div>
                    <form data-field="quiz-response-form" class="htt-join-quiz-response-form" hidden>
                        <input type="text" data-field="quiz-response-text" maxlength="200" autocomplete="off">
                        <button type="submit" class="ojp-button ojp-button--primary" data-field="quiz-response-submit"><?php esc_html_e('보내기', 'teaching-tools'); ?></button>
                    </form>
                    <p data-field="quiz-voted" hidden></p>
                    <div data-field="quiz-results" class="htt-quiz-bars" hidden></div>
                    <div data-field="quiz-text-results" class="htt-quiz-text-wall" hidden></div>
                    <div data-field="quiz-word-results" class="htt-quiz-wordcloud" hidden></div>
                    <div data-field="quiz-own" class="htt-join-quiz-own" hidden></div>
                    <div data-field="quiz-approved" class="htt-join-quiz-approved" hidden></div>
                </div>
                <div data-field="grouping-view" class="htt-join-grouping" hidden>
                    <p data-field="grouping-my-group"></p>
                    <div data-field="grouping-groups" class="htt-grouping-groups"></div>
                </div>
                <div data-field="timeattack-view" class="htt-join-timeattack" hidden>
                    <div class="htt-timeattack-problem-title" data-field="timeattack-problem"></div>
                    <div class="htt-timer-clock" data-field="timeattack-clock">00:00</div>
                </div>
                <div data-field="classroom-view" class="htt-join-classroom" hidden>
                    <p class="htt-classroom-connection" data-field="classroom-connection" role="status"><?php esc_html_e('수업에 연결하고 있습니다.', 'teaching-tools'); ?></p>
                    <section class="htt-classroom-student-section" aria-labelledby="htt-classroom-student-activity-title">
                        <h2 id="htt-classroom-student-activity-title"><?php esc_html_e('현재 활동', 'teaching-tools'); ?></h2>
                        <p class="ojp-empty-state" data-field="classroom-activity-empty"><?php esc_html_e('지금은 안내된 활동이 없습니다.', 'teaching-tools'); ?></p>
                        <div class="htt-classroom-student-activity" data-field="classroom-activity" hidden>
                            <h3 data-field="classroom-activity-title"></h3>
                            <p data-field="classroom-activity-note" hidden></p>
                            <a class="ojp-button ojp-button--primary" data-field="classroom-activity-link" href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e('활동 열기', 'teaching-tools'); ?></a>
                        </div>
                    </section>
                    <section class="htt-classroom-student-section" aria-labelledby="htt-classroom-student-signal-title">
                        <h2 id="htt-classroom-student-signal-title"><?php esc_html_e('지금 상태를 알려주세요', 'teaching-tools'); ?></h2>
                        <div class="htt-classroom-signal-grid" data-field="classroom-signals">
                            <button type="button" data-signal="ready"><?php esc_html_e('준비됐어요', 'teaching-tools'); ?></button>
                            <button type="button" data-signal="understood"><?php esc_html_e('이해했어요', 'teaching-tools'); ?></button>
                            <button type="button" data-signal="unsure"><?php esc_html_e('조금 어려워요', 'teaching-tools'); ?></button>
                            <button type="button" data-signal="repeat"><?php esc_html_e('다시 설명해 주세요', 'teaching-tools'); ?></button>
                            <button type="button" data-signal="help"><?php esc_html_e('도움이 필요해요', 'teaching-tools'); ?></button>
                            <button type="button" data-signal="done"><?php esc_html_e('활동을 마쳤어요', 'teaching-tools'); ?></button>
                        </div>
                        <p class="htt-classroom-my-signal" data-field="classroom-my-signal" aria-live="polite"></p>
                    </section>
                </div>
                <div data-field="unsupported-view" hidden>
                    <p class="ojp-empty-state"><?php esc_html_e('이 도구는 아직 준비 중입니다.', 'teaching-tools'); ?></p>
                </div>
            </div>
        </div>
        <?php

        return Ui::page(__('수업 참여', 'teaching-tools'), (string) ob_get_clean(), 'wide', false);
    }
}
