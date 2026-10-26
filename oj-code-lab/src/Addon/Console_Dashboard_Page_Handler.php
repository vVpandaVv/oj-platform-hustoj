<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/**
 * Teacher console dashboard: pick a class/group, see every student's
 * notebook status (cell count, last saved time, whether the last run had
 * an error), and open one student's notebook read-only. All data fetching
 * happens in resources/js/dashboard.js against Rest\Controllers\{Contexts,
 * Roster}_Controller — this handler only emits the markup skeleton.
 */
final class Console_Dashboard_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('oj-code-lab/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        );

        ob_start();
        ?>
        <div class="ocl-console ocl-console-dashboard" data-ocl-dashboard data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <header class="ojp-page-header">
                <div>
                    <p class="ojp-eyebrow"><?php esc_html_e('학습 활동', 'oj-code-lab'); ?></p>
                    <h1><?php esc_html_e('코드 실습실', 'oj-code-lab'); ?></h1>
                    <p><?php esc_html_e('학급이나 모둠을 고르면 학생별 노트북 저장 현황을 볼 수 있습니다.', 'oj-code-lab'); ?></p>
                </div>
            </header>

            <div class="ocl-messages" data-field="messages" aria-live="polite"></div>

            <section class="ocl-panel">
                <div class="ojp-field">
                    <label for="ocl-dashboard-context"><?php esc_html_e('학급/모둠', 'oj-code-lab'); ?></label>
                    <select id="ocl-dashboard-context" class="ojp-select" data-field="context">
                        <option value=""><?php esc_html_e('불러오는 중…', 'oj-code-lab'); ?></option>
                    </select>
                </div>
            </section>

            <section class="ocl-panel" data-field="roster-panel" hidden>
                <h2><?php esc_html_e('학생 노트북', 'oj-code-lab'); ?></h2>
                <div class="ojp-table-wrap">
                    <table class="ojp-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('학생', 'oj-code-lab'); ?></th>
                                <th><?php esc_html_e('셀 개수', 'oj-code-lab'); ?></th>
                                <th><?php esc_html_e('최근 저장', 'oj-code-lab'); ?></th>
                                <th><?php esc_html_e('오류', 'oj-code-lab'); ?></th>
                                <th><span class="ojp-visually-hidden"><?php esc_html_e('작업', 'oj-code-lab'); ?></span></th>
                            </tr>
                        </thead>
                        <tbody data-field="roster-body"></tbody>
                    </table>
                </div>
            </section>

            <section class="ocl-panel" data-field="viewer-panel" hidden>
                <h2 data-field="viewer-title"></h2>
                <p class="ocl-description"><?php esc_html_e('읽기 전용입니다. 여기서는 코드를 실행하거나 편집할 수 없습니다.', 'oj-code-lab'); ?></p>
                <div data-field="viewer-cells"></div>
                <button type="button" class="ojp-button" data-action="close-viewer"><?php esc_html_e('닫기', 'oj-code-lab'); ?></button>
            </section>
        </div>
        <?php

        return Page_Response::console(__('코드 실습실', 'oj-code-lab'), (string) ob_get_clean());
    }
}
