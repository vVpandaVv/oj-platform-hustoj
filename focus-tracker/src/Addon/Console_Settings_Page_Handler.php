<?php
declare(strict_types=1);

namespace FocusTracker\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use FocusTracker\Admin\Settings_Page;
use FocusTracker\Support\Capabilities;
use FocusTracker\Support\Settings;

/**
 * OJ console › 수업 › 집중도 평가 › 설정: the site-wide defaults (weights,
 * event retention, activity window, tracked URL patterns). Posts to the
 * admin-post handler in Admin\Settings_Page, which keeps the option name,
 * sanitising, nonce and permission check in one place and returns here
 * with ?ftk_notice=saved.
 */
final class Console_Settings_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $title = __('집중도 평가 설정', 'focus-tracker');

        if (! Capabilities::can_manage_settings()) {
            $body = '<div class="ftk-console">' . self::header()
                . '<div class="ojp-empty-state"><div><strong>' . esc_html__('설정을 바꿀 권한이 없습니다', 'focus-tracker') . '</strong>'
                . '<p>' . esc_html__('사이트 관리자나 "집중도 평가 설정" 권한을 받은 선생님만 바꿀 수 있습니다.', 'focus-tracker') . '</p></div></div>'
                . '</div>';

            return Page_Response::make($title, $body);
        }

        $settings = Settings::all();
        $notice = $request->query()['ftk_notice'] ?? '';
        $saved = 'saved' === $notice;

        ob_start();
        ?>
        <div class="ftk-console">
            <?php echo self::header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
            <?php echo Operations_Panel::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

            <?php if ($saved) : ?>
                <div class="ojp-notice ojp-notice--success" role="status" data-ojp-flash><strong><?php esc_html_e('설정을 저장했습니다', 'focus-tracker'); ?></strong></div>
            <?php endif; ?>

            <form class="ojp-form-stack ftk-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(Settings_Page::ACTION); ?>">
                <?php wp_nonce_field(Settings_Page::NONCE_ACTION, Settings_Page::NONCE_FIELD); ?>

                <fieldset class="ojp-fieldset">
                    <legend><?php esc_html_e('기본 가중치', 'focus-tracker'); ?></legend>
                    <p class="ojp-field-help"><?php esc_html_e('새 활동을 만들 때 기본으로 채워지는 값입니다. 활동별로 다르게 지정할 수 있습니다.', 'focus-tracker'); ?></p>
                    <div class="ftk-weights">
                        <div class="ojp-field">
                            <label for="ftk-set-weight-focus"><?php esc_html_e('화면 활성', 'focus-tracker'); ?></label>
                            <input class="ojp-input" id="ftk-set-weight-focus" type="number" step="0.5" min="0" name="weight_focus" value="<?php echo esc_attr((string) $settings['weight_focus']); ?>">
                        </div>
                        <div class="ojp-field">
                            <label for="ftk-set-weight-selfreg"><?php esc_html_e('복귀', 'focus-tracker'); ?></label>
                            <input class="ojp-input" id="ftk-set-weight-selfreg" type="number" step="0.5" min="0" name="weight_selfreg" value="<?php echo esc_attr((string) $settings['weight_selfreg']); ?>">
                        </div>
                        <div class="ojp-field">
                            <label for="ftk-set-weight-activity"><?php esc_html_e('입력·제출', 'focus-tracker'); ?></label>
                            <input class="ojp-input" id="ftk-set-weight-activity" type="number" step="0.5" min="0" name="weight_activity" value="<?php echo esc_attr((string) $settings['weight_activity']); ?>">
                        </div>
                    </div>
                </fieldset>

                <fieldset class="ojp-fieldset">
                    <legend><?php esc_html_e('자동 학습 확인', 'focus-tracker'); ?></legend>
                    <label><input type="checkbox" name="checks_enabled" value="1" <?php checked((bool) $settings['checks_enabled']); ?>> <?php esc_html_e('검증된 확인 문항을 자동으로 제시', 'focus-tracker'); ?></label>
                    <p class="ojp-field-help"><?php esc_html_e('교사가 문항을 만들거나 채점할 필요가 없습니다. 본인의 제출 코드, 작성 중인 초안, 공개 예제를 활용합니다. 초안 원문은 저장하지 않으며, 오답·모름·무응답은 미집중 판정이나 집중 점수에 반영하지 않습니다.', 'focus-tracker'); ?></p>
                    <div class="ojp-field">
                        <label for="ftk-check-cooldown"><?php esc_html_e('문항 사이 최소 간격(초)', 'focus-tracker'); ?></label>
                        <input class="ojp-input" id="ftk-check-cooldown" type="number" min="60" max="3600" name="check_cooldown_seconds" value="<?php echo esc_attr((string) $settings['check_cooldown_seconds']); ?>">
                    </div>
                    <div class="ojp-field">
                        <label for="ftk-check-daily-limit"><?php esc_html_e('학생별 하루 최대 문항 수', 'focus-tracker'); ?></label>
                        <input class="ojp-input" id="ftk-check-daily-limit" type="number" min="1" max="20" name="check_daily_limit" value="<?php echo esc_attr((string) $settings['check_daily_limit']); ?>">
                    </div>
                </fieldset>

                <div class="ojp-field">
                    <label for="ftk-retention-days"><?php esc_html_e('원시 이벤트 보존 기간(일)', 'focus-tracker'); ?></label>
                    <input class="ojp-input" id="ftk-retention-days" type="number" min="1" max="36500" name="retention_days" value="<?php echo esc_attr((string) $settings['retention_days']); ?>" aria-describedby="ftk-retention-days-help">
                    <p class="ojp-field-help" id="ftk-retention-days-help"><?php esc_html_e('원시 기록은 활동별·일별 집계를 안전하게 저장한 뒤 삭제합니다. 집계를 저장하지 못한 기록은 유지하고 다시 처리합니다.', 'focus-tracker'); ?></p>
                </div>

                <div class="ojp-field">
                    <label for="ftk-check-retention-days"><?php esc_html_e('확인 문항·응답 보관 기간(일)', 'focus-tracker'); ?></label>
                    <input class="ojp-input" id="ftk-check-retention-days" type="number" min="1" max="36500" name="check_retention_days" value="<?php echo esc_attr((string) $settings['check_retention_days']); ?>" aria-describedby="ftk-check-retention-help">
                    <p class="ojp-field-help" id="ftk-check-retention-help"><?php esc_html_e('문항에 필요한 코드 일부와 응답은 이 기간이 지나면 일별 집계를 보존한 뒤 삭제합니다. 원시 활동 기록과 별도로 정합니다.', 'focus-tracker'); ?></p>
                </div>

                <div class="ojp-field">
                    <label for="ftk-aggregate-retention-days"><?php esc_html_e('활동·일별 집계 보관 기간(일, 0은 무제한)', 'focus-tracker'); ?></label>
                    <input class="ojp-input" id="ftk-aggregate-retention-days" type="number" min="0" max="36500" name="aggregate_retention_days" value="<?php echo esc_attr((string) $settings['aggregate_retention_days']); ?>" aria-describedby="ftk-aggregate-retention-help">
                    <p class="ojp-field-help" id="ftk-aggregate-retention-help"><?php esc_html_e('기본값 0은 기존 집계를 계속 보관합니다. 제한하려면 원시 기록·문항 보관 기간보다 최소 1일 길게 지정하세요. 만료한 집계는 조회로 다시 만들지 않습니다. 아직 원시 자료가 남아 있으면 안전하게 보관을 마친 뒤 집계를 삭제합니다.', 'focus-tracker'); ?></p>
                </div>

                <div class="ojp-field">
                    <label for="ftk-activity-window"><?php esc_html_e('학습 활동 판정 구간(초)', 'focus-tracker'); ?></label>
                    <input class="ojp-input" id="ftk-activity-window" type="number" min="5" name="activity_window_seconds" value="<?php echo esc_attr((string) $settings['activity_window_seconds']); ?>" aria-describedby="ftk-activity-window-help">
                    <p class="ojp-field-help" id="ftk-activity-window-help"><?php esc_html_e('이 구간 안에 코드 수정, 실행, 제출이 한 번이라도 있으면 활동 구간으로 계산합니다.', 'focus-tracker'); ?></p>
                </div>

                <div class="ojp-field">
                    <label for="ftk-url-patterns"><?php esc_html_e('기록할 페이지 주소 패턴', 'focus-tracker'); ?></label>
                    <textarea class="ojp-textarea" id="ftk-url-patterns" name="tracked_url_patterns" rows="4" aria-describedby="ftk-url-patterns-help"><?php echo esc_textarea(implode("\n", $settings['tracked_url_patterns'])); ?></textarea>
                    <p class="ojp-field-help" id="ftk-url-patterns-help"><?php esc_html_e('정규식을 한 줄에 하나씩 적습니다. 예: #^/(problems|submit)(/|$)# — 맞는 경로에서만 기록합니다. 잘못된 식은 저장하지 않습니다.', 'focus-tracker'); ?></p>
                </div>

                <div class="ojp-form-actions">
                    <button type="submit" class="ojp-button ojp-button--primary"><?php esc_html_e('저장', 'focus-tracker'); ?></button>
                </div>
            </form>
        </div>
        <?php

        return Page_Response::make($title, (string) ob_get_clean());
    }

    private static function header(): string {
        return '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">' . esc_html__('수업', 'focus-tracker') . '</p>'
            . '<h1>' . esc_html__('집중도 평가 설정', 'focus-tracker') . '</h1>'
            . '<p>' . esc_html__('새 활동의 기본 가중치, 기록 보존 기간, 기록할 페이지를 정합니다.', 'focus-tracker') . '</p>'
            . '</div></header>';
    }
}
