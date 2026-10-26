<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Classroom;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use TeachingTools\Addon\Ui;

final class Classroom_Host_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('teaching-tools/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'currentUserId' => get_current_user_id(),
            'joinUrl' => home_url('/oj-addons/teaching-tools/join'),
            'reportUrl' => wp_nonce_url(
                admin_url('admin-post.php?action=teaching_tools_session_report'),
                'teaching_tools_session_report'
            ),
        );

        ob_start();
        ?>
        <div class="htt-classroom-host" data-htt-classroom-host data-htt-host data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <?php echo Ui::tool_header(__('수업 신호', 'teaching-tools'), __('학생의 현재 상태를 확인하고 다음 활동을 바로 보냅니다.', 'teaching-tools'), false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

            <div class="htt-classroom-setup" data-field="setup">
                <section class="htt-classroom-start-options" aria-labelledby="htt-classroom-participation-title">
                    <div class="htt-classroom-start-heading">
                        <h2 id="htt-classroom-participation-title"><?php esc_html_e('참여 방식', 'teaching-tools'); ?></h2>
                        <p><?php esc_html_e('코드를 안내하거나, 지정한 학급·그룹의 학생을 자동으로 연결합니다.', 'teaching-tools'); ?></p>
                    </div>
                    <div class="htt-classroom-join-mode" data-field="join-mode">
                        <label>
                            <input type="radio" name="htt-classroom-join-mode" value="code" checked>
                            <span>
                                <strong><?php esc_html_e('참여 코드', 'teaching-tools'); ?></strong>
                                <small><?php esc_html_e('학생이 6자리 코드를 입력합니다.', 'teaching-tools'); ?></small>
                            </span>
                        </label>
                        <label>
                            <input type="radio" name="htt-classroom-join-mode" value="context">
                            <span>
                                <strong><?php esc_html_e('학급·그룹 자동 참여', 'teaching-tools'); ?></strong>
                                <small><?php esc_html_e('대상 학생은 사이트에서 자동 연결됩니다.', 'teaching-tools'); ?></small>
                            </span>
                        </label>
                    </div>
                    <?php echo Ui::student_name_mode_control(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
                    <div class="htt-classroom-context-picker" data-field="context-picker" hidden>
                        <?php echo Ui::auto_open_control(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
                        <label>
                            <span><?php esc_html_e('자동 참여 대상', 'teaching-tools'); ?></span>
                            <select data-field="context-select">
                                <option value=""><?php esc_html_e('학급/그룹 선택', 'teaching-tools'); ?></option>
                            </select>
                        </label>
                        <button type="button" class="ojp-button ojp-button--plain" data-action="load-contexts"><?php esc_html_e('목록 새로고침', 'teaching-tools'); ?></button>
                        <p data-field="context-status" role="status"><?php esc_html_e('수업 시작 시점의 명단이 자동 등록됩니다.', 'teaching-tools'); ?></p>
                    </div>
                </section>
                <button type="button" class="ojp-button ojp-button--primary ojp-button--large htt-primary-action" data-action="start">
                    <?php echo Ui::icon('signal', 20); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php esc_html_e('실시간 수업 시작', 'teaching-tools'); ?>
                </button>
            </div>

            <div class="htt-classroom-message" data-field="message" role="status" hidden></div>

            <div class="htt-classroom-session" data-field="session" hidden>
                <div class="htt-classroom-sessionbar">
                    <div class="htt-classroom-session-meta">
                        <div class="htt-classroom-code">
                            <span><?php esc_html_e('참여 코드', 'teaching-tools'); ?></span>
                            <strong data-field="join-code"></strong>
                        </div>
                        <p class="htt-classroom-audience" data-field="audience" hidden>
                            <span><?php esc_html_e('자동 참여', 'teaching-tools'); ?></span>
                            <strong data-field="audience-label"></strong>
                            <button type="button" class="ojp-button ojp-button--plain ojp-button--small" data-action="refresh-roster"><?php esc_html_e('명단 갱신', 'teaching-tools'); ?></button>
                        </p>
                    </div>
                    <div class="htt-classroom-session-actions">
                        <button type="button" class="ojp-button ojp-button--tinted" data-action="copy-link">
                            <?php echo Ui::icon('link', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php esc_html_e('참여 링크 복사', 'teaching-tools'); ?>
                        </button>
                        <button type="button" class="ojp-button ojp-button--plain" data-action="download-report">
                            <?php echo Ui::icon('download', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php esc_html_e('CSV 보고서', 'teaching-tools'); ?>
                        </button>
                        <button type="button" class="ojp-button ojp-button--plain" data-action="end"><?php esc_html_e('수업 종료', 'teaching-tools'); ?></button>
                    </div>
                </div>

                <details class="htt-classroom-collaborators">
                    <summary><?php esc_html_e('공동 진행자', 'teaching-tools'); ?></summary>
                    <div class="htt-classroom-collaborator-form">
                        <label><?php esc_html_e('사용자 ID', 'teaching-tools'); ?><input type="number" min="1" data-field="host-id"></label>
                        <label><?php esc_html_e('역할', 'teaching-tools'); ?><select data-field="host-role"><option value="cohost"><?php esc_html_e('공동 진행', 'teaching-tools'); ?></option><option value="observer"><?php esc_html_e('관찰', 'teaching-tools'); ?></option></select></label>
                        <button type="button" class="ojp-button ojp-button--plain" data-action="host-add"><?php esc_html_e('추가', 'teaching-tools'); ?></button>
                    </div>
                    <div data-field="hosts"></div>
                </details>

                <section class="htt-classroom-section" aria-labelledby="htt-classroom-summary-title">
                    <div class="htt-classroom-section-heading">
                        <div>
                            <h2 id="htt-classroom-summary-title"><?php esc_html_e('수업 상태', 'teaching-tools'); ?></h2>
                            <p data-field="connection-status"><?php esc_html_e('실시간 연결을 준비하고 있습니다.', 'teaching-tools'); ?></p>
                        </div>
                        <button type="button" class="ojp-button ojp-button--plain ojp-button--small" data-action="clear-all"><?php esc_html_e('신호 모두 지우기', 'teaching-tools'); ?></button>
                    </div>
                    <div class="htt-classroom-stats" data-field="stats" aria-live="polite"></div>
                </section>

                <section class="htt-classroom-section" aria-labelledby="htt-classroom-activity-title">
                    <div class="htt-classroom-section-heading">
                        <div>
                            <h2 id="htt-classroom-activity-title"><?php esc_html_e('활동 보내기', 'teaching-tools'); ?></h2>
                        </div>
                    </div>
                    <div class="htt-classroom-activity-search">
                        <label>
                            <?php esc_html_e('플랫폼 활동 검색', 'teaching-tools'); ?>
                            <input type="search" maxlength="120" data-field="activity-search" placeholder="<?php echo esc_attr__('자료, 게임, 실습 검색', 'teaching-tools'); ?>">
                        </label>
                        <button type="button" class="ojp-button ojp-button--plain" data-action="search-activities"><?php esc_html_e('검색', 'teaching-tools'); ?></button>
                    </div>
                    <div class="htt-classroom-presets" data-field="presets" hidden></div>
                    <div class="htt-classroom-activity-form">
                        <label>
                            <?php esc_html_e('활동 이름', 'teaching-tools'); ?>
                            <input type="text" maxlength="100" data-field="activity-title" placeholder="<?php echo esc_attr__('예: 오늘의 읽기자료', 'teaching-tools'); ?>">
                        </label>
                        <label>
                            <?php esc_html_e('활동 주소', 'teaching-tools'); ?>
                            <input type="url" data-field="activity-url" placeholder="https://">
                        </label>
                        <label class="htt-classroom-activity-note">
                            <?php esc_html_e('짧은 안내', 'teaching-tools'); ?>
                            <input type="text" maxlength="240" data-field="activity-note" placeholder="<?php echo esc_attr__('선택 사항', 'teaching-tools'); ?>">
                        </label>
                        <label>
                            <?php esc_html_e('활동 대상', 'teaching-tools'); ?>
                            <select data-field="activity-target-mode">
                                <option value="all"><?php esc_html_e('참여 학생 전체', 'teaching-tools'); ?></option>
                                <option value="selected"><?php esc_html_e('선택한 학생', 'teaching-tools'); ?></option>
                            </select>
                        </label>
                        <input type="hidden" data-field="activity-target-ids">
                        <div class="htt-classroom-activity-targets" data-field="activity-targets" hidden></div>
                        <label data-field="activity-target-label-wrap" hidden>
                            <?php esc_html_e('대상 이름', 'teaching-tools'); ?>
                            <input type="text" maxlength="80" data-field="activity-target-label" placeholder="<?php echo esc_attr__('예: 심화 모둠', 'teaching-tools'); ?>">
                        </label>
                        <button type="button" class="ojp-button ojp-button--primary" data-action="send-activity">
                            <?php echo Ui::icon('send', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php esc_html_e('학생에게 보내기', 'teaching-tools'); ?>
                        </button>
                    </div>
                    <div class="htt-classroom-current-activity" data-field="current-activity" hidden>
                        <div>
                            <span><?php esc_html_e('현재 활동', 'teaching-tools'); ?></span>
                            <strong data-field="current-activity-title"></strong>
                        </div>
                        <button type="button" class="ojp-button ojp-button--plain ojp-button--small" data-action="clear-activity"><?php esc_html_e('내리기', 'teaching-tools'); ?></button>
                    </div>
                </section>

                <section class="htt-classroom-section" aria-labelledby="htt-classroom-participants-title">
                    <div class="htt-classroom-section-heading">
                        <div>
                            <h2 id="htt-classroom-participants-title"><?php esc_html_e('참여 학생', 'teaching-tools'); ?></h2>
                            <p data-field="participant-summary"><?php esc_html_e('아직 참여한 학생이 없습니다.', 'teaching-tools'); ?></p>
                        </div>
                    </div>
                    <div class="htt-classroom-participants" data-field="participants"></div>
                </section>
            </div>
        </div>
        <?php

        return Ui::page(__('수업 신호', 'teaching-tools'), (string) ob_get_clean(), 'wide', true);
    }
}
