<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Seating;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use TeachingTools\Addon\Ui;

final class Seating_Host_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('teaching-tools/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'joinUrl' => home_url('/oj-addons/teaching-tools/join'),
        );

        ob_start();
        ?>
        <div class="htt-seating-host" data-htt-seating-host data-htt-host data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <?php echo Ui::tool_header(__('자리배치', 'teaching-tools'), __('학급 명단으로 자리를 무작위 배치합니다.', 'teaching-tools')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

            <div class="htt-seating-setup" data-field="setup">
                <div class="htt-step">
                    <span class="htt-step-badge">1</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('명단 준비', 'teaching-tools'); ?></p>
                        <label>
                            <?php esc_html_e('저장된 배치 불러오기', 'teaching-tools'); ?>
                            <select data-field="layout-select">
                                <option value=""><?php esc_html_e('저장된 배치 선택', 'teaching-tools'); ?></option>
                            </select>
                            <button type="button" class="ojp-button ojp-button--plain" data-action="load-layout"><?php esc_html_e('불러오기', 'teaching-tools'); ?></button>
                            <button type="button" class="ojp-button ojp-button--danger" data-action="delete-layout"><?php esc_html_e('삭제', 'teaching-tools'); ?></button>
                        </label>

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
                        <p class="htt-step-title"><?php esc_html_e('배치 설정', 'teaching-tools'); ?></p>
                        <label>
                            <?php esc_html_e('가로 줄 수 (칠판 앞 기준 rows)', 'teaching-tools'); ?>
                            <input type="number" min="1" max="20" value="5" data-field="rows">
                        </label>
                        <label>
                            <?php esc_html_e('세로 칸 수 (cols)', 'teaching-tools'); ?>
                            <input type="number" min="1" max="20" value="6" data-field="cols">
                        </label>

                        <div>
                            <p><?php esc_html_e('제외할 자리 (클릭해서 선택)', 'teaching-tools'); ?></p>
                            <div class="htt-seat-board htt-seat-board-preview" data-field="exclusion-grid"></div>
                        </div>

                        <details class="htt-collapsible">
                            <summary><?php esc_html_e('선택 조건 (선택 사항)', 'teaching-tools'); ?></summary>
                            <p class="htt-condition-help"><?php esc_html_e('명단의 이름, 관리용 이름 또는 #학생ID를 입력할 수 있습니다. 동명이인은 명단에 표시된 구분 이름이나 #학생ID를 사용하세요.', 'teaching-tools'); ?></p>
                            <label>
                                <?php esc_html_e('앞자리 배치 (한 줄에 한 명 — 이름·관리용 이름·#학생ID)', 'teaching-tools'); ?>
                                <textarea data-field="front-names"></textarea>
                            </label>
                            <label>
                                <?php esc_html_e('떨어뜨려 앉히기 (한 줄에 두 명, 쉼표로 구분)', 'teaching-tools'); ?>
                                <textarea data-field="apart-pairs"></textarea>
                            </label>
                            <label>
                                <?php esc_html_e('최소 간격', 'teaching-tools'); ?>
                                <input type="number" min="1" max="10" value="2" data-field="apart-gap">
                            </label>
                            <label>
                                <input type="checkbox" data-field="diagonal" checked>
                                <?php esc_html_e('대각선도 가깝다고 간주', 'teaching-tools'); ?>
                            </label>
                            <label>
                                <?php esc_html_e('짝 지어 앉히기 (한 줄에 두 명, 쉼표로 구분 — 같은 책상 좌우 자리로만 붙입니다)', 'teaching-tools'); ?>
                                <textarea data-field="buddy-pairs"></textarea>
                            </label>
                        </details>
                    </div>
                </div>

                <div class="htt-step">
                    <span class="htt-step-badge">3</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('진행 방식', 'teaching-tools'); ?></p>
                        <div class="htt-mode-toggle">
                            <label>
                                <input type="radio" name="htt-seating-mode" value="solo" checked>
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('user', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text">
                                    <strong><?php esc_html_e('교사 화면만', 'teaching-tools'); ?></strong>
                                    <span><?php esc_html_e('단일 화면', 'teaching-tools'); ?></span>
                                </span>
                            </label>
                            <label>
                                <input type="radio" name="htt-seating-mode" value="live" data-field="live-mode-input" disabled>
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('users', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text">
                                    <strong><?php esc_html_e('학생이 "내 자리 찾기"로 참여', 'teaching-tools'); ?></strong>
                                    <span><?php esc_html_e('학급 명단 필요', 'teaching-tools'); ?></span>
                                </span>
                            </label>
                        </div>
                        <label class="htt-option-toggle">
                            <input type="checkbox" data-field="reveal-mode">
                            <span><?php esc_html_e('결과를 가린 뒤 자리를 클릭해 한 명씩 공개', 'teaching-tools'); ?></span>
                        </label>
                    </div>
                </div>

                <button type="button" class="ojp-button ojp-button--primary ojp-button--large htt-primary-action" data-action="arrange"><?php esc_html_e('자리 배치 실행', 'teaching-tools'); ?></button>
            </div>

            <div class="htt-seating-messages" data-field="messages"></div>

            <div class="htt-seating-result" data-field="result" hidden>
                <div class="htt-timer-code" data-field="join-info" hidden>
                    <p><?php esc_html_e('참여 코드', 'teaching-tools'); ?></p>
                    <strong data-field="join-code"></strong>
                    <br>
                    <button type="button" class="ojp-button ojp-button--tinted ojp-button--small htt-join-link" data-action="copy-link"><?php esc_html_e('참여 링크 복사', 'teaching-tools'); ?></button>
                </div>
                <div class="htt-seating-result-actions">
                    <?php echo Ui::student_name_mode_control(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
                    <button type="button" class="ojp-button ojp-button--plain" data-action="reveal-all" hidden>
                        <?php echo Ui::icon('eye', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php esc_html_e('모두 공개', 'teaching-tools'); ?>
                    </button>
                    <button type="button" class="ojp-button ojp-button--plain" data-action="download-image">
                        <?php echo Ui::icon('download', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php esc_html_e('이미지 저장', 'teaching-tools'); ?>
                    </button>
                    <button type="button" class="ojp-button ojp-button--plain" data-action="print">
                        <?php echo Ui::icon('print', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php esc_html_e('인쇄', 'teaching-tools'); ?>
                    </button>
                </div>
                <div class="htt-seat-board" data-field="board"></div>

                <label>
                    <?php esc_html_e('배치 이름', 'teaching-tools'); ?>
                    <input type="text" data-field="layout-name" placeholder="<?php echo esc_attr__('예: 3반 2학기 좌석', 'teaching-tools'); ?>">
                </label>
                <button type="button" class="ojp-button ojp-button--plain" data-action="save-layout"><?php esc_html_e('이 배치 저장', 'teaching-tools'); ?></button>
                <span data-field="save-layout-status"></span>

                <button type="button" class="ojp-button ojp-button--plain" data-action="new-arrangement"><?php esc_html_e('새로 배치하기', 'teaching-tools'); ?></button>
            </div>
        </div>
        <?php

        return Ui::page(__('자리배치', 'teaching-tools'), (string) ob_get_clean(), 'wide', true);
    }
}
