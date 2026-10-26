<?php
declare(strict_types=1);

namespace EduGames\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/**
 * OJ Console screen showing, per class/group, each student's AI Model Lab
 * phase1/phase2/total scores for both activities. Read-only shell; the
 * table itself is fetched and drawn client-side by
 * resources/js/console-ai-model-lab-report.js against
 * AI_Model_Lab_Report_Controller's REST endpoints.
 */
final class Console_Ai_Model_Lab_Report_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('edu-games/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        );

        ob_start();
        ?>
        <div class="edu-games-ai-model-lab-report" data-ai-model-lab-report data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <?php
            echo Page_Response::header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
                __('학습 활동', 'edu-games'),
                __('AI 모델 훈련소 성적', 'edu-games'),
                __('학급이나 그룹을 고르면 학생별 1단계·2단계 점수와 총점을 볼 수 있습니다.', 'edu-games')
            );
            ?>
            <div class="ojp-filter-bar eg-report-controls">
                <div class="ojp-field ojp-field--inline eg-report-field">
                    <label for="ai-model-lab-report-context"><?php esc_html_e('학급·그룹', 'edu-games'); ?></label>
                    <select class="ojp-select" id="ai-model-lab-report-context" data-field="context-select"></select>
                </div>
            </div>
            <div class="eg-report-status" data-field="report-status" role="status"></div>
            <div data-field="report-table"></div>
        </div>
        <?php

        return Page_Response::console(__('AI 모델 훈련소 성적', 'edu-games'), (string) ob_get_clean());
    }
}
