<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Grouping;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use TeachingTools\Addon\Ui;

final class Grouping_Host_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('teaching-tools/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'joinUrl' => home_url('/oj-addons/teaching-tools/join'),
        );

        ob_start();
        ?>
        <div class="htt-grouping-host" data-htt-grouping-host data-htt-host data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <?php echo Ui::tool_header(__('조 짜기', 'teaching-tools'), __('제출 기록을 활용해 실력이 균형 잡힌 팀을 짭니다.', 'teaching-tools')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

            <div class="htt-grouping-setup" data-field="setup">
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
                        <p class="htt-step-title"><?php esc_html_e('조 짜기 설정', 'teaching-tools'); ?></p>
                        <label>
                            <?php esc_html_e('조 개수', 'teaching-tools'); ?>
                            <input type="number" min="2" max="20" value="4" data-field="group-count">
                        </label>

                        <label>
                            <input type="checkbox" data-field="balanced">
                            <?php esc_html_e('실력 균형 배정 (제출 기록 기반 — 학급에서 명단을 가져왔을 때만 사용 가능)', 'teaching-tools'); ?>
                        </label>
                    </div>
                </div>

                <div class="htt-step">
                    <span class="htt-step-badge">3</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('진행 방식', 'teaching-tools'); ?></p>
                        <div class="htt-mode-toggle">
                            <label>
                                <input type="radio" name="htt-grouping-mode" value="solo" checked>
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('user', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text">
                                    <strong><?php esc_html_e('교사 화면만', 'teaching-tools'); ?></strong>
                                    <span><?php esc_html_e('단일 화면', 'teaching-tools'); ?></span>
                                </span>
                            </label>
                            <label>
                                <input type="radio" name="htt-grouping-mode" value="live" data-field="live-mode-input" disabled>
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('users', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text">
                                    <strong><?php esc_html_e('학생이 "내 조 확인"으로 참여', 'teaching-tools'); ?></strong>
                                    <span><?php esc_html_e('학급 명단 필요', 'teaching-tools'); ?></span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <button type="button" class="ojp-button ojp-button--primary ojp-button--large htt-primary-action" data-action="run"><?php esc_html_e('조 짜기 실행', 'teaching-tools'); ?></button>
            </div>

            <div class="htt-grouping-messages" data-field="messages"></div>

            <div class="htt-grouping-result" data-field="result" hidden>
                <div class="htt-timer-code" data-field="join-info" hidden>
                    <p><?php esc_html_e('참여 코드', 'teaching-tools'); ?></p>
                    <strong data-field="join-code"></strong>
                    <br>
                    <button type="button" class="ojp-button ojp-button--tinted ojp-button--small htt-join-link" data-action="copy-link"><?php esc_html_e('참여 링크 복사', 'teaching-tools'); ?></button>
                </div>

                <div class="htt-grouping-groups" data-field="groups"></div>
                <button type="button" class="ojp-button ojp-button--plain" data-action="restart"><?php esc_html_e('새로 짜기', 'teaching-tools'); ?></button>
            </div>
        </div>
        <?php

        return Ui::page(__('조 짜기', 'teaching-tools'), (string) ob_get_clean(), 'wide', true);
    }
}
