<?php
declare(strict_types=1);

namespace FocusTracker\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/**
 * Teacher console dashboard (see docs/DESIGN.md §7, §10 Phase 1): create an
 * activity, pick one, see its roster+score table, drill into one learner's
 * timeline. All the actual data fetching/rendering happens in
 * resources/js/dashboard.js against the REST endpoints in
 * Rest\Controllers\{Activities,Scores,Contexts}_Controller — this handler
 * only emits the markup skeleton plus the `data-config` bootstrap payload,
 * following the same pattern as teaching-tools' host page handlers.
 *
 * The markup uses the platform's shared ojp-* parts (D-010); the addon CSS
 * only lays them out.
 */
final class Console_Dashboard_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('focus-tracker/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'serverNowMs' => \FocusTracker\Support\Clock::now_ms(),
        );

        ob_start();
        ?>
        <div class="ftk-console" data-ftk-dashboard data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <header class="ojp-page-header">
                <div>
                    <p class="ojp-eyebrow"><?php esc_html_e('수업', 'focus-tracker'); ?></p>
                    <h1><?php esc_html_e('집중도 평가', 'focus-tracker'); ?></h1>
                    <p><?php esc_html_e('화면 활성 상태와 편집·제출 신호로 학습 참여 지표를 계산합니다. 생각하기·읽기·풀이의 질은 이 기록만으로 판단할 수 없습니다.', 'focus-tracker'); ?></p>
                    <p class="ojp-muted"><?php esc_html_e('학생이 속한 학급·그룹의 활동 시간에만 대상 문제의 기록을 수집하고 자동 학습 확인을 제시합니다. 활동이 끝나면 기록과 문항 제시를 중단합니다.', 'focus-tracker'); ?></p>
                </div>
            </header>

            <div class="ftk-messages" data-field="messages" aria-live="polite"></div>

            <section class="ftk-section" aria-labelledby="ftk-create-title">
                <h2 id="ftk-create-title"><?php esc_html_e('새 활동', 'focus-tracker'); ?></h2>
                <form data-field="create-form" class="ojp-form-stack ftk-form">
                    <div class="ojp-form-grid">
                        <div class="ojp-field">
                            <label for="ftk-name"><?php esc_html_e('활동 이름', 'focus-tracker'); ?></label>
                            <input class="ojp-input" id="ftk-name" type="text" data-field="name" maxlength="191" required placeholder="<?php esc_attr_e('예: 2학기 중간 수행평가', 'focus-tracker'); ?>">
                        </div>
                        <div class="ojp-field">
                            <label for="ftk-context"><?php esc_html_e('대상 학급/그룹', 'focus-tracker'); ?></label>
                            <select class="ojp-select" id="ftk-context" data-field="context" required>
                                <option value=""><?php esc_html_e('불러오는 중…', 'focus-tracker'); ?></option>
                            </select>
                        </div>
                        <div class="ojp-field">
                            <label for="ftk-starts-at"><?php esc_html_e('시작 시각', 'focus-tracker'); ?></label>
                            <input class="ojp-input" id="ftk-starts-at" type="datetime-local" step="1" data-field="starts-at" required>
                        </div>
                        <div class="ojp-field">
                            <label for="ftk-ends-at"><?php esc_html_e('종료 시각', 'focus-tracker'); ?></label>
                            <input class="ojp-input" id="ftk-ends-at" type="datetime-local" step="1" data-field="ends-at" required>
                        </div>
                    </div>
                    <p class="ojp-field-help" data-field="schedule-timezone"></p>
                    <div class="ojp-field">
                        <label for="ftk-problem-ids"><?php esc_html_e('대상 문제 번호 (선택)', 'focus-tracker'); ?></label>
                        <input class="ojp-input" id="ftk-problem-ids" type="text" data-field="problem-ids" placeholder="1001, 1002" aria-describedby="ftk-problem-ids-help">
                        <p class="ojp-field-help" id="ftk-problem-ids-help"><?php esc_html_e('쉼표로 구분합니다. 비우면 모든 문제를 기록합니다.', 'focus-tracker'); ?></p>
                    </div>
                    <fieldset class="ojp-fieldset">
                        <legend><?php esc_html_e('가중치', 'focus-tracker'); ?></legend>
                        <p class="ojp-field-help"><?php esc_html_e('기본값은 화면 활성 8, 복귀 6, 입력·제출 6입니다. 읽기·생각하기 중심 수업은 입력·제출 가중치를 0으로 설정할 수 있습니다. 자동 지표를 성적 확정에 단독 사용하지 마세요.', 'focus-tracker'); ?></p>
                        <div class="ftk-weights">
                            <div class="ojp-field">
                                <label for="ftk-weight-focus"><?php esc_html_e('화면 활성', 'focus-tracker'); ?></label>
                                <input class="ojp-input" id="ftk-weight-focus" type="number" step="0.5" min="0" max="100" data-field="weight-focus" placeholder="8">
                            </div>
                            <div class="ojp-field">
                                <label for="ftk-weight-selfreg"><?php esc_html_e('복귀', 'focus-tracker'); ?></label>
                                <input class="ojp-input" id="ftk-weight-selfreg" type="number" step="0.5" min="0" max="100" data-field="weight-selfreg" placeholder="6">
                            </div>
                            <div class="ojp-field">
                                <label for="ftk-weight-activity"><?php esc_html_e('입력·제출', 'focus-tracker'); ?></label>
                                <input class="ojp-input" id="ftk-weight-activity" type="number" step="0.5" min="0" max="100" data-field="weight-activity" placeholder="6">
                            </div>
                        </div>
                    </fieldset>
                    <div class="ojp-form-actions">
                        <button type="submit" class="ojp-button ojp-button--primary"><?php esc_html_e('활동 만들기', 'focus-tracker'); ?></button>
                    </div>
                </form>
            </section>

            <section class="ftk-section" aria-labelledby="ftk-list-title">
                <h2 id="ftk-list-title"><?php esc_html_e('활동 목록', 'focus-tracker'); ?></h2>
                <div class="ojp-field ftk-schedule-filter">
                    <label for="ftk-activity-context-filter"><?php esc_html_e('학급/그룹별 일정', 'focus-tracker'); ?></label>
                    <select class="ojp-select" id="ftk-activity-context-filter" data-field="activity-context-filter">
                        <option value=""><?php esc_html_e('모든 학급/그룹', 'focus-tracker'); ?></option>
                    </select>
                </div>
                <div data-field="activity-list">
                    <p class="ojp-muted"><?php esc_html_e('불러오는 중…', 'focus-tracker'); ?></p>
                </div>
            </section>

            <section class="ftk-section ojp-card" data-field="edit-panel" aria-labelledby="ftk-edit-title" hidden>
                <h2 id="ftk-edit-title"><?php esc_html_e('활동 시간 수정', 'focus-tracker'); ?></h2>
                <p class="ojp-muted" data-field="edit-context"></p>
                <form class="ojp-form-stack ftk-form" data-field="edit-form">
                    <div class="ojp-field">
                        <label for="ftk-edit-name"><?php esc_html_e('활동 이름', 'focus-tracker'); ?></label>
                        <input class="ojp-input" id="ftk-edit-name" type="text" data-field="edit-name" maxlength="191" required>
                    </div>
                    <div class="ojp-form-grid">
                        <div class="ojp-field">
                            <label for="ftk-edit-starts-at"><?php esc_html_e('시작 시각', 'focus-tracker'); ?></label>
                            <input class="ojp-input" id="ftk-edit-starts-at" type="datetime-local" step="1" data-field="edit-starts-at" required>
                        </div>
                        <div class="ojp-field">
                            <label for="ftk-edit-ends-at"><?php esc_html_e('종료 시각', 'focus-tracker'); ?></label>
                            <input class="ojp-input" id="ftk-edit-ends-at" type="datetime-local" step="1" data-field="edit-ends-at" required>
                        </div>
                    </div>
                    <p class="ojp-field-help" data-field="schedule-timezone"></p>
                    <div class="ojp-field">
                        <label for="ftk-edit-problem-ids"><?php esc_html_e('대상 문제 번호 (선택)', 'focus-tracker'); ?></label>
                        <input class="ojp-input" id="ftk-edit-problem-ids" type="text" data-field="edit-problem-ids" placeholder="1001, 1002">
                    </div>
                    <fieldset class="ojp-fieldset">
                        <legend><?php esc_html_e('가중치', 'focus-tracker'); ?></legend>
                        <div class="ftk-weights">
                            <?php foreach (array('focus' => '화면 활성', 'selfreg' => '복귀', 'activity' => '입력·제출') as $key => $label): ?>
                            <div class="ojp-field">
                                <label for="ftk-edit-weight-<?php echo esc_attr($key); ?>"><?php echo esc_attr($label); ?></label>
                                <input class="ojp-input" id="ftk-edit-weight-<?php echo esc_attr($key); ?>" type="number" step="0.5" min="0" max="100" data-field="edit-weight-<?php echo esc_attr($key); ?>" required>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <div class="ojp-form-actions">
                        <button type="submit" class="ojp-button ojp-button--primary" data-action="save-activity"><?php esc_html_e('변경 저장', 'focus-tracker'); ?></button>
                        <button type="button" class="ojp-button ojp-button--plain" data-action="cancel-edit"><?php esc_html_e('취소', 'focus-tracker'); ?></button>
                    </div>
                </form>
            </section>

            <section class="ftk-section" data-field="scores-panel" aria-labelledby="ftk-scores-title" hidden>
                <h2 id="ftk-scores-title" data-field="scores-title"></h2>
                <p class="ojp-muted"><?php esc_html_e('진행 중 지표는 변합니다. 관측 자료가 없거나 일부 누락된 경우 성적으로 해석하지 마세요. 입력이 적어도 읽거나 생각하는 중일 수 있어 검토가 필요합니다.', 'focus-tracker'); ?></p>
                <div class="ojp-table-wrap">
                    <table class="ojp-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('학생', 'focus-tracker'); ?></th>
                                <th scope="col"><?php esc_html_e('화면 활성률', 'focus-tracker'); ?></th>
                                <th scope="col"><?php esc_html_e('이탈 횟수', 'focus-tracker'); ?></th>
                                <th scope="col"><?php esc_html_e('평균 복귀시간', 'focus-tracker'); ?></th>
                                <th scope="col"><?php esc_html_e('입력·제출 활동률', 'focus-tracker'); ?></th>
                                <th scope="col"><?php esc_html_e('참여 지표', 'focus-tracker'); ?></th>
                                <th scope="col"><?php esc_html_e('기록 상태', 'focus-tracker'); ?></th>
                                <th scope="col"><span class="ojp-visually-hidden"><?php esc_html_e('작업', 'focus-tracker'); ?></span></th>
                            </tr>
                        </thead>
                        <tbody data-field="scores-body"></tbody>
                    </table>
                </div>
            </section>

            <section class="ftk-section" data-field="timeline-panel" aria-labelledby="ftk-timeline-title" hidden>
                <div class="ojp-section-heading">
                    <h2 id="ftk-timeline-title" data-field="timeline-title"></h2>
                    <button type="button" class="ojp-button ojp-button--plain ojp-button--small" data-action="close-timeline"><?php esc_html_e('닫기', 'focus-tracker'); ?></button>
                </div>
                <div data-field="timeline-chart" class="ftk-timeline-chart"></div>
            </section>
            <section id="ftk-teacher-statistics" class="ftk-section ftk-statistics" data-ftk-statistics data-mode="teacher" data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>" hidden></section>
        </div>
        <?php

        return Page_Response::make(__('집중도 평가', 'focus-tracker'), (string) ob_get_clean());
    }
}
