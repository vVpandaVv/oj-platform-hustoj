<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Ladder;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use TeachingTools\Addon\Ui;

final class Ladder_Host_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('teaching-tools/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'joinUrl' => home_url('/oj-addons/teaching-tools/join'),
        );

        ob_start();
        ?>
        <div class="htt-ladder-host" data-htt-ladder-host data-htt-host data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <?php echo Ui::tool_header(__('사다리타기', 'teaching-tools'), __('학생이 각자 기기로 참여하는 사다리타기 게임입니다.', 'teaching-tools')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

            <div class="htt-ladder-setup" data-field="setup">
                <div class="htt-step">
                    <span class="htt-step-badge">1</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('인원수와 결과 목록', 'teaching-tools'); ?></p>
                        <label>
                            <?php esc_html_e('참여 인원수 (줄 수)', 'teaching-tools'); ?>
                            <input type="number" min="2" max="30" value="6" data-field="participant-count">
                        </label>
                        <label>
                            <?php esc_html_e('결과 목록 (한 줄에 하나, 인원수와 같아야 함)', 'teaching-tools'); ?>
                            <textarea data-field="results" placeholder="1등&#10;2등&#10;꽝&#10;꽝&#10;청소&#10;발표"></textarea>
                        </label>
                    </div>
                </div>

                <div class="htt-step">
                    <span class="htt-step-badge">2</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('진행 방식', 'teaching-tools'); ?></p>
                        <div class="htt-mode-toggle">
                            <label>
                                <input type="radio" name="htt-ladder-mode" value="live" checked>
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('users', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text">
                                    <strong><?php esc_html_e('학생이 각자 기기로 줄 선택', 'teaching-tools'); ?></strong>
                                    <span><?php esc_html_e('동시 참여', 'teaching-tools'); ?></span>
                                </span>
                            </label>
                            <label>
                                <input type="radio" name="htt-ladder-mode" value="solo">
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('user', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text">
                                    <strong><?php esc_html_e('교사 화면만', 'teaching-tools'); ?></strong>
                                    <span><?php esc_html_e('혼자 진행', 'teaching-tools'); ?></span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <button type="button" class="ojp-button ojp-button--primary ojp-button--large htt-primary-action" data-action="start"><?php esc_html_e('사다리 생성', 'teaching-tools'); ?></button>
            </div>

            <div class="htt-ladder-messages" data-field="messages"></div>

            <div class="htt-ladder-progress" data-field="progress" hidden>
                <div class="htt-timer-code" data-field="join-info" hidden>
                    <p><?php esc_html_e('참여 코드', 'teaching-tools'); ?></p>
                    <strong data-field="join-code"></strong>
                    <br>
                    <button type="button" class="ojp-button ojp-button--tinted ojp-button--small htt-join-link" data-action="copy-link"><?php esc_html_e('참여 링크 복사', 'teaching-tools'); ?></button>
                </div>

                <p data-field="claim-count" class="ojp-status-badge ojp-status-badge--info"></p>
                <div class="htt-ladder-columns" data-field="columns"></div>
                <div class="htt-ladder-solo-animation" data-field="solo-animation" hidden></div>

                <button type="button" class="ojp-button ojp-button--primary ojp-button--large htt-primary-action" data-action="reveal"><?php esc_html_e('전체 결과 공개', 'teaching-tools'); ?></button>
                <div class="htt-ladder-reveal" data-field="reveal" hidden></div>

                <button type="button" class="ojp-button ojp-button--plain" data-action="restart"><?php esc_html_e('새로 시작', 'teaching-tools'); ?></button>
            </div>
        </div>
        <?php

        return Ui::page(__('사다리타기', 'teaching-tools'), (string) ob_get_clean(), 'wide', true);
    }
}
