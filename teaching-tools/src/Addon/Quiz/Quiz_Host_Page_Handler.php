<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Quiz;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use TeachingTools\Addon\Ui;

final class Quiz_Host_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('teaching-tools/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'joinUrl' => home_url('/oj-addons/teaching-tools/join'),
        );

        ob_start();
        ?>
        <div class="htt-quiz-host" data-htt-quiz-host data-htt-host data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <?php echo Ui::tool_header(__('빠른 응답', 'teaching-tools'), __('객관식, 척도, 한줄 응답, 워드클라우드와 익명 질문을 바로 받습니다.', 'teaching-tools')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

            <div class="htt-quiz-setup" data-field="setup">
                <div class="htt-step">
                    <span class="htt-step-badge">1</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('응답 방식', 'teaching-tools'); ?></p>
                        <div class="htt-quiz-type-tabs" data-field="response-types">
                            <label><input type="radio" name="htt-quiz-type" value="choice" checked><span><?php esc_html_e('객관식', 'teaching-tools'); ?></span></label>
                            <label><input type="radio" name="htt-quiz-type" value="scale"><span><?php esc_html_e('척도', 'teaching-tools'); ?></span></label>
                            <label><input type="radio" name="htt-quiz-type" value="text"><span><?php esc_html_e('한줄 응답', 'teaching-tools'); ?></span></label>
                            <label><input type="radio" name="htt-quiz-type" value="wordcloud"><span><?php esc_html_e('워드클라우드', 'teaching-tools'); ?></span></label>
                            <label><input type="radio" name="htt-quiz-type" value="qna"><span><?php esc_html_e('익명 질문', 'teaching-tools'); ?></span></label>
                        </div>
                    </div>
                </div>

                <div class="htt-step">
                    <span class="htt-step-badge">2</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('질문', 'teaching-tools'); ?></p>
                        <label>
                            <span data-field="question-label"><?php esc_html_e('질문 또는 안내', 'teaching-tools'); ?></span>
                            <input type="text" maxlength="200" data-field="question" placeholder="<?php echo esc_attr__('예: 오늘 배운 내용 중 가장 중요한 것은?', 'teaching-tools'); ?>">
                        </label>

                        <div data-field="choice-fields">
                            <div data-field="options-list"></div>
                            <button type="button" class="ojp-button ojp-button--plain" data-action="add-option"><?php esc_html_e('선택지 추가', 'teaching-tools'); ?></button>
                            <label class="htt-quiz-graded">
                                <input type="checkbox" data-field="graded">
                                <?php esc_html_e('정답이 있는 퀴즈', 'teaching-tools'); ?>
                            </label>
                        </div>

                        <div class="htt-quiz-scale-fields" data-field="scale-fields" hidden>
                            <label>
                                <?php esc_html_e('낮은 값 설명', 'teaching-tools'); ?>
                                <input type="text" maxlength="40" data-field="scale-low" value="전혀 아니에요">
                            </label>
                            <label>
                                <?php esc_html_e('높은 값 설명', 'teaching-tools'); ?>
                                <input type="text" maxlength="40" data-field="scale-high" value="매우 그래요">
                            </label>
                            <label>
                                <?php esc_html_e('최댓값', 'teaching-tools'); ?>
                                <select data-field="scale-max">
                                    <option value="4">4</option>
                                    <option value="5" selected>5</option>
                                    <option value="7">7</option>
                                    <option value="10">10</option>
                                </select>
                            </label>
                        </div>

                        <p class="htt-quiz-type-help" data-field="type-help"></p>
                        <div class="htt-quiz-text-controls" data-field="text-controls" hidden>
                            <label>
                                <?php esc_html_e('차단할 표현', 'teaching-tools'); ?>
                                <input type="text" maxlength="500" data-field="blocked-words" placeholder="<?php echo esc_attr__('쉼표로 구분', 'teaching-tools'); ?>">
                            </label>
                            <label class="htt-option-toggle">
                                <input type="checkbox" data-field="moderation-required">
                                <span><?php esc_html_e('교사가 승인한 응답만 집계·공개', 'teaching-tools'); ?></span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="htt-step">
                    <span class="htt-step-badge">3</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('진행 방식', 'teaching-tools'); ?></p>
                        <div class="htt-mode-toggle">
                            <label>
                                <input type="radio" name="htt-quiz-mode" value="solo" checked>
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('user', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text"><strong><?php esc_html_e('교사 화면만', 'teaching-tools'); ?></strong><span><?php esc_html_e('미리보기', 'teaching-tools'); ?></span></span>
                            </label>
                            <label>
                                <input type="radio" name="htt-quiz-mode" value="live">
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('users', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text"><strong><?php esc_html_e('학생 기기로 응답', 'teaching-tools'); ?></strong><span><?php esc_html_e('동시 참여', 'teaching-tools'); ?></span></span>
                            </label>
                        </div>
                    </div>
                </div>

                <button type="button" class="ojp-button ojp-button--primary ojp-button--large htt-primary-action" data-action="start"><?php esc_html_e('응답 받기', 'teaching-tools'); ?></button>
            </div>

            <div class="htt-quiz-messages" data-field="messages" role="status"></div>

            <div class="htt-quiz-result" data-field="result" hidden>
                <div class="htt-quiz-sessionbar">
                    <div class="htt-timer-code" data-field="join-info" hidden>
                        <p><?php esc_html_e('참여 코드', 'teaching-tools'); ?></p>
                        <strong data-field="join-code"></strong>
                        <button type="button" class="ojp-button ojp-button--tinted ojp-button--small htt-join-link" data-action="copy-link"><?php echo Ui::icon('link', 17); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e('참여 링크 복사', 'teaching-tools'); ?></button>
                    </div>
                    <div class="htt-quiz-live-count"><strong data-field="response-count">0</strong><span><?php esc_html_e('응답', 'teaching-tools'); ?></span></div>
                </div>

                <p class="htt-quiz-question" data-field="question-display"></p>
                <div class="htt-quiz-bars" data-field="bars"></div>
                <div class="htt-quiz-text-wall" data-field="text-wall" hidden></div>
                <div class="htt-quiz-wordcloud" data-field="wordcloud" hidden></div>
                <div class="htt-quiz-qna" data-field="qna" hidden></div>

                <div class="htt-quiz-result-actions">
                    <button type="button" class="ojp-button ojp-button--plain" data-action="toggle-accepting"><?php esc_html_e('응답 마감', 'teaching-tools'); ?></button>
                    <button type="button" class="ojp-button ojp-button--primary" data-action="reveal"><?php esc_html_e('학생 화면에 결과 공개', 'teaching-tools'); ?></button>
                    <button type="button" class="ojp-button ojp-button--plain" data-action="restart"><?php esc_html_e('새로 시작', 'teaching-tools'); ?></button>
                </div>
            </div>
        </div>
        <?php

        return Ui::page(__('빠른 응답', 'teaching-tools'), (string) ob_get_clean(), 'wide', true);
    }
}
