<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Timeattack;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use TeachingTools\Addon\Ui;

final class Timeattack_Host_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('teaching-tools/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'joinUrl' => home_url('/oj-addons/teaching-tools/join'),
        );

        ob_start();
        ?>
        <div class="htt-timeattack-host" data-htt-timeattack-host data-htt-host data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <?php echo Ui::tool_header(__('문제 풀이 타임어택', 'teaching-tools'), __('정해진 시간 안에 문제를 풀고 실시간으로 결과를 확인합니다.', 'teaching-tools')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

            <div class="htt-timeattack-setup" data-field="setup">
                <div class="htt-step">
                    <span class="htt-step-badge">1</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('문제 선택 (여러 문제 추가 가능)', 'teaching-tools'); ?></p>
                        <label>
                            <?php esc_html_e('문제 검색 (번호 또는 제목)', 'teaching-tools'); ?>
                            <input type="text" data-field="problem-search" placeholder="<?php echo esc_attr__('예: 1000 또는 A+B', 'teaching-tools'); ?>">
                            <button type="button" class="ojp-button ojp-button--plain" data-action="search-problem"><?php esc_html_e('검색', 'teaching-tools'); ?></button>
                        </label>
                        <div data-field="problem-results" class="htt-timeattack-problem-results"></div>
                        <div data-field="selected-problems" class="htt-timeattack-selected-list"></div>
                    </div>
                </div>

                <div class="htt-step">
                    <span class="htt-step-badge">2</span>
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
                            <?php esc_html_e('명단 직접 입력은 지원하지 않습니다 — 제출 여부 확인을 위해 학급/그룹 명단이 필요합니다', 'teaching-tools'); ?>
                            <textarea data-field="students" readonly></textarea>
                        </label>
                    </div>
                </div>

                <div class="htt-step">
                    <span class="htt-step-badge">3</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('시간과 진행 방식', 'teaching-tools'); ?></p>
                        <label>
                            <?php esc_html_e('시간(분)', 'teaching-tools'); ?>
                            <input type="number" min="1" max="180" value="10" data-field="minutes">
                        </label>

                        <div class="htt-mode-toggle">
                            <label>
                                <input type="radio" name="htt-timeattack-mode" value="solo" checked>
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('user', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text">
                                    <strong><?php esc_html_e('교사 화면만', 'teaching-tools'); ?></strong>
                                    <span><?php esc_html_e('단일 화면', 'teaching-tools'); ?></span>
                                </span>
                            </label>
                            <label>
                                <input type="radio" name="htt-timeattack-mode" value="live">
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('users', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text">
                                    <strong><?php esc_html_e('학생 기기에도 표시', 'teaching-tools'); ?></strong>
                                    <span><?php esc_html_e('동시 참여', 'teaching-tools'); ?></span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <button type="button" class="ojp-button ojp-button--primary ojp-button--large htt-primary-action" data-action="start"><?php esc_html_e('타임어택 시작', 'teaching-tools'); ?></button>
            </div>

            <div class="htt-timeattack-messages" data-field="messages"></div>

            <div class="htt-timeattack-result" data-field="result" hidden>
                <div class="htt-timer-code" data-field="join-info" hidden>
                    <p><?php esc_html_e('참여 코드', 'teaching-tools'); ?></p>
                    <strong data-field="join-code"></strong>
                    <br>
                    <button type="button" class="ojp-button ojp-button--tinted ojp-button--small htt-join-link" data-action="copy-link"><?php esc_html_e('참여 링크 복사', 'teaching-tools'); ?></button>
                </div>

                <div class="htt-timeattack-problem-title" data-field="problem-title"></div>
                <div class="htt-timer-clock" data-field="clock">00:00</div>
                <p data-field="solved-count" class="ojp-status-badge ojp-status-badge--success"></p>
                <div class="htt-timeattack-list" data-field="status-list"></div>

                <button type="button" class="ojp-button ojp-button--plain" data-action="restart"><?php esc_html_e('종료', 'teaching-tools'); ?></button>
            </div>
        </div>
        <?php

        return Ui::page(__('문제 풀이 타임어택', 'teaching-tools'), (string) ob_get_clean(), 'wide', true);
    }
}
