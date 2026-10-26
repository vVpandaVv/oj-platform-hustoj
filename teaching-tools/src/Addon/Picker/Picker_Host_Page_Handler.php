<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Picker;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use TeachingTools\Addon\Ui;

final class Picker_Host_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('teaching-tools/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'joinUrl' => home_url('/oj-addons/teaching-tools/join'),
        );

        ob_start();
        ?>
        <div class="htt-picker-host" data-htt-picker-host data-htt-host data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <?php echo Ui::tool_header(__('뽑기', 'teaching-tools'), __('명단에서 한 명 또는 여러 명을 무작위로 뽑습니다.', 'teaching-tools')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

            <div class="htt-picker-setup" data-field="setup">
                <div class="htt-step">
                    <span class="htt-step-badge">1</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('명단 준비', 'teaching-tools'); ?></p>
                        <label>
                            <?php esc_html_e('명단 가져오기', 'teaching-tools'); ?>
                            <select data-field="context-select">
                                <option value=""><?php esc_html_e('학급/그룹 선택', 'teaching-tools'); ?></option>
                            </select>
                            <button type="button" class="ojp-button ojp-button--plain" data-action="load-contexts"><?php esc_html_e('목록 새로고침', 'teaching-tools'); ?></button>
                            <button type="button" class="ojp-button" data-action="load-roster"><?php esc_html_e('명단 불러오기', 'teaching-tools'); ?></button>
                        </label>

                        <?php echo Ui::student_name_mode_control(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

                        <label>
                            <?php esc_html_e('명단 직접 입력 (한 줄에 한 명)', 'teaching-tools'); ?>
                            <textarea data-field="students"></textarea>
                        </label>
                    </div>
                </div>

                <div class="htt-step">
                    <span class="htt-step-badge">2</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('뽑기 설정', 'teaching-tools'); ?></p>
                        <label>
                            <?php esc_html_e('한 번에 뽑을 인원수', 'teaching-tools'); ?>
                            <input type="number" min="1" max="50" value="1" data-field="pick-count">
                        </label>
                        <label>
                            <input type="checkbox" data-field="exclude-drawn" checked>
                            <?php esc_html_e('이미 뽑힌 학생 제외 (모두 뽑힐 때까지 중복 없음)', 'teaching-tools'); ?>
                        </label>
                    </div>
                </div>

                <div class="htt-step">
                    <span class="htt-step-badge">3</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('진행 방식', 'teaching-tools'); ?></p>
                        <div class="htt-mode-toggle">
                            <label>
                                <input type="radio" name="htt-picker-mode" value="solo" checked>
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('user', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text">
                                    <strong><?php esc_html_e('교사 화면만', 'teaching-tools'); ?></strong>
                                    <span><?php esc_html_e('단일 화면', 'teaching-tools'); ?></span>
                                </span>
                            </label>
                            <label>
                                <input type="radio" name="htt-picker-mode" value="live">
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('users', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text">
                                    <strong><?php esc_html_e('학생 기기에도 결과 표시', 'teaching-tools'); ?></strong>
                                    <span><?php esc_html_e('동시 참여', 'teaching-tools'); ?></span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <button type="button" class="ojp-button ojp-button--primary ojp-button--large htt-primary-action" data-action="start"><?php esc_html_e('뽑기 시작', 'teaching-tools'); ?></button>
            </div>

            <div class="htt-picker-messages" data-field="messages"></div>

            <div class="htt-picker-result" data-field="result" hidden>
                <div class="htt-timer-code" data-field="join-info" hidden>
                    <p><?php esc_html_e('참여 코드', 'teaching-tools'); ?></p>
                    <strong data-field="join-code"></strong>
                    <br>
                    <button type="button" class="ojp-button ojp-button--tinted ojp-button--small htt-join-link" data-action="copy-link"><?php esc_html_e('참여 링크 복사', 'teaching-tools'); ?></button>
                </div>

                <p data-field="pool-count" class="ojp-status-badge ojp-status-badge--neutral"></p>
                <div class="htt-picker-latest" data-field="latest"></div>
                <button type="button" class="ojp-button ojp-button--primary ojp-button--large htt-primary-action" data-action="draw"><?php esc_html_e('뽑기!', 'teaching-tools'); ?></button>
                <div class="htt-picker-history" data-field="history"></div>
                <button type="button" class="ojp-button ojp-button--plain" data-action="restart"><?php esc_html_e('새로 시작', 'teaching-tools'); ?></button>
            </div>
        </div>
        <?php

        return Ui::page(__('뽑기', 'teaching-tools'), (string) ob_get_clean(), 'wide', true);
    }
}
