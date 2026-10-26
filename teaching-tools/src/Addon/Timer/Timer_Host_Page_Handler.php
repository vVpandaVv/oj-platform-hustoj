<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Timer;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use TeachingTools\Addon\Ui;

final class Timer_Host_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('teaching-tools/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'joinUrl' => home_url('/oj-addons/teaching-tools/join'),
        );

        ob_start();
        ?>
        <div class="htt-timer-host" data-htt-timer-host data-htt-host data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <?php echo Ui::tool_header(__('타이머', 'teaching-tools'), __('수업 활동 시간을 재고 화면에 크게 표시합니다.', 'teaching-tools')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

            <div class="htt-timer-setup" data-field="setup">
                <div class="htt-step">
                    <span class="htt-step-badge">1</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('시간 설정', 'teaching-tools'); ?></p>
                        <label>
                            <?php esc_html_e('분', 'teaching-tools'); ?>
                            <input type="number" min="0" max="180" value="5" data-field="minutes">
                        </label>
                        <label>
                            <?php esc_html_e('초', 'teaching-tools'); ?>
                            <input type="number" min="0" max="59" value="0" data-field="seconds">
                        </label>
                    </div>
                </div>

                <div class="htt-step">
                    <span class="htt-step-badge">2</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('진행 방식', 'teaching-tools'); ?></p>
                        <div class="htt-mode-toggle">
                            <label>
                                <input type="radio" name="htt-timer-mode" value="solo" checked>
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('user', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text">
                                    <strong><?php esc_html_e('교사 화면만', 'teaching-tools'); ?></strong>
                                    <span><?php esc_html_e('단일 화면', 'teaching-tools'); ?></span>
                                </span>
                            </label>
                            <label>
                                <input type="radio" name="htt-timer-mode" value="live">
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('users', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text">
                                    <strong><?php esc_html_e('학생 기기에도 표시', 'teaching-tools'); ?></strong>
                                    <span><?php esc_html_e('동시 참여', 'teaching-tools'); ?></span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="htt-step">
                    <span class="htt-step-badge">3</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('종료 알림음', 'teaching-tools'); ?></p>
                        <label class="htt-timer-sound-field">
                            <select data-field="sound">
                                <option value="none"><?php esc_html_e('없음(무음)', 'teaching-tools'); ?></option>
                                <option value="chime" selected><?php esc_html_e('차임벨', 'teaching-tools'); ?></option>
                                <option value="bell"><?php esc_html_e('종소리', 'teaching-tools'); ?></option>
                                <option value="alarm"><?php esc_html_e('알람', 'teaching-tools'); ?></option>
                                <option value="ding"><?php esc_html_e('딩동', 'teaching-tools'); ?></option>
                            </select>
                            <button type="button" class="ojp-button ojp-button--plain" data-action="preview-sound"><?php esc_html_e('미리듣기', 'teaching-tools'); ?></button>
                        </label>
                    </div>
                </div>

                <button type="button" class="ojp-button ojp-button--primary ojp-button--large htt-primary-action" data-action="start"><?php esc_html_e('시작', 'teaching-tools'); ?></button>
            </div>

            <div class="htt-timer-display" data-field="display" hidden>
                <div class="htt-timer-code" data-field="join-info" hidden>
                    <p><?php esc_html_e('참여 코드', 'teaching-tools'); ?></p>
                    <strong data-field="join-code"></strong>
                    <span data-field="participant-count"></span>
                    <br>
                    <button type="button" class="ojp-button ojp-button--tinted ojp-button--small htt-join-link" data-action="copy-link"><?php esc_html_e('참여 링크 복사', 'teaching-tools'); ?></button>
                </div>
                <div class="htt-timer-clock" data-field="clock">00:00</div>
                <div class="htt-timer-ended" data-field="ended-message" hidden><?php esc_html_e('시간이 종료되었습니다', 'teaching-tools'); ?></div>
                <div class="htt-timer-controls">
                    <button type="button" class="ojp-button" data-action="toggle"><?php esc_html_e('일시정지', 'teaching-tools'); ?></button>
                    <button type="button" class="ojp-button ojp-button--plain" data-action="reset"><?php esc_html_e('새 타이머', 'teaching-tools'); ?></button>
                </div>
            </div>
        </div>
        <?php

        return Ui::page(__('타이머', 'teaching-tools'), (string) ob_get_clean(), 'wide', true);
    }
}
