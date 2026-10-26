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
use OjCodeLab\Support\Dataset_Limits;

/**
 * Teacher-facing "추천 데이터" curation screen: search Kaggle, pick a
 * dataset + file, add it to the list every student sees in their notebook's
 * data-import panel. resources/js/datasets-admin.js owns the behavior.
 */
final class Console_Datasets_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('oj-code-lab/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        );

        $effective_max_bytes = min(Dataset_Limits::max_bytes(), Dataset_Limits::php_upload_max_bytes());
        $max_label = Dataset_Limits::max_megabytes_label($effective_max_bytes);

        ob_start();
        ?>
        <div class="ocl-console ocl-console-datasets" data-ocl-datasets data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <header class="ojp-page-header">
                <div>
                    <p class="ojp-eyebrow"><?php esc_html_e('학습 활동', 'oj-code-lab'); ?></p>
                    <h1><?php esc_html_e('추천 데이터', 'oj-code-lab'); ?></h1>
                    <p><?php esc_html_e('여기에 추가한 데이터는 모든 학생의 코드 실습실 "추천 데이터" 목록에 나타납니다.', 'oj-code-lab'); ?></p>
                </div>
            </header>

            <div class="ocl-messages" data-field="messages" aria-live="polite"></div>
            <p class="ocl-description"><?php esc_html_e('CSV·JSONL·NDJSON과 이 파일을 담은 ZIP을 지원합니다. ZIP에 지원 파일이 하나이면 자동으로 가져옵니다. 여러 개이면 원하는 파일을 압축에서 꺼내 따로 추가해 주세요.', 'oj-code-lab'); ?></p>

            <section class="ocl-panel">
                <h2><?php esc_html_e('Kaggle에서 찾기', 'oj-code-lab'); ?></h2>
                <form class="ocl-dataset-search" data-field="search-form" role="search">
                    <label class="ojp-visually-hidden" for="ocl-datasets-search"><?php esc_html_e('Kaggle 검색어', 'oj-code-lab'); ?></label>
                    <input type="text" id="ocl-datasets-search" class="ojp-input" data-field="search-input" placeholder="<?php esc_attr_e('예: titanic, traffic accident', 'oj-code-lab'); ?>">
                    <button type="submit" class="ojp-button"><?php esc_html_e('검색', 'oj-code-lab'); ?></button>
                </form>
                <div class="ocl-dataset-list" data-field="search-results"></div>
                <div data-field="files-section" hidden>
                    <h3 data-field="files-title"></h3>
                    <div class="ocl-dataset-list" data-field="files-list"></div>
                </div>
            </section>

            <section class="ocl-panel">
                <h2><?php esc_html_e('URL로 추가', 'oj-code-lab'); ?></h2>
                <p class="ocl-description"><?php esc_html_e('CSV·JSONL·NDJSON·ZIP 파일을 바로 다운로드하는 주소를 입력합니다. 공공데이터포털 파일데이터, GitHub 파일 링크(blob 링크도 자동 변환합니다), 학교 서버 주소를 쓸 수 있습니다.', 'oj-code-lab'); ?></p>
                <form class="ocl-form" data-field="url-form">
                    <div class="ojp-field">
                        <label for="ocl-url-label"><?php esc_html_e('이름', 'oj-code-lab'); ?></label>
                        <input type="text" id="ocl-url-label" class="ojp-input" data-field="url-label" required>
                    </div>
                    <div class="ojp-field">
                        <label for="ocl-url-icon"><?php esc_html_e('아이콘 (선택)', 'oj-code-lab'); ?></label>
                        <input type="text" id="ocl-url-icon" class="ojp-input" data-field="url-icon" placeholder="📊">
                    </div>
                    <div class="ojp-field">
                        <label for="ocl-url-value"><?php esc_html_e('데이터 URL', 'oj-code-lab'); ?></label>
                        <input type="text" id="ocl-url-value" class="ojp-input" data-field="url-value" placeholder="https://..." required>
                    </div>
                    <div class="ocl-form-actions">
                        <button type="submit" class="ojp-button ojp-button--tinted"><?php esc_html_e('추가', 'oj-code-lab'); ?></button>
                    </div>
                </form>
            </section>

            <section class="ocl-panel">
                <h2><?php esc_html_e('파일 올리기', 'oj-code-lab'); ?></h2>
                <p class="ocl-description"><?php esc_html_e('CSV·JSONL·NDJSON·ZIP 파일을 여기서 바로 올립니다. 공개 URL 없이 이 사이트 안에 저장합니다.', 'oj-code-lab'); ?></p>
                <p class="ocl-description">
                    <?php
                    printf(
                        /* translators: %s: maximum upload size in megabytes */
                        esc_html__('최대 %sMB까지 올릴 수 있습니다.', 'oj-code-lab'),
                        esc_html($max_label)
                    );
                    ?>
                </p>
                <form class="ocl-form" data-field="upload-form">
                    <div class="ojp-field">
                        <label for="ocl-upload-label"><?php esc_html_e('이름', 'oj-code-lab'); ?></label>
                        <input type="text" id="ocl-upload-label" class="ojp-input" data-field="upload-label" required>
                    </div>
                    <div class="ojp-field">
                        <label for="ocl-upload-icon"><?php esc_html_e('아이콘 (선택)', 'oj-code-lab'); ?></label>
                        <input type="text" id="ocl-upload-icon" class="ojp-input" data-field="upload-icon" placeholder="📊">
                    </div>
                    <div class="ojp-field">
                        <label for="ocl-upload-file"><?php esc_html_e('데이터 파일', 'oj-code-lab'); ?></label>
                        <input type="file" id="ocl-upload-file" class="ojp-input ocl-file-input" data-field="upload-file" accept=".csv,.jsonl,.ndjson,.zip" required>
                    </div>
                    <div class="ocl-form-actions">
                        <button type="submit" class="ojp-button ojp-button--tinted"><?php esc_html_e('올리기', 'oj-code-lab'); ?></button>
                    </div>
                </form>
            </section>

            <section class="ocl-panel">
                <div class="ocl-panel-heading">
                    <h2><?php esc_html_e('현재 추천 목록', 'oj-code-lab'); ?></h2>
                    <button type="button" class="ojp-button ojp-button--small" data-action="restore-samples"><?php esc_html_e('샘플 데이터 복구', 'oj-code-lab'); ?></button>
                </div>
                <p class="ocl-description"><?php esc_html_e('기본 샘플 데이터(타이타닉, 서울 공공자전거 등)를 실수로 지웠다면 "샘플 데이터 복구"로 다시 넣을 수 있습니다. 남아 있는 항목은 중복으로 추가하지 않습니다.', 'oj-code-lab'); ?></p>
                <div class="ojp-table-wrap">
                    <table class="ojp-table">
                        <thead>
                            <tr>
                                <th><span class="ojp-visually-hidden"><?php esc_html_e('아이콘', 'oj-code-lab'); ?></span></th>
                                <th><?php esc_html_e('이름', 'oj-code-lab'); ?></th>
                                <th><?php esc_html_e('출처', 'oj-code-lab'); ?></th>
                                <th><span class="ojp-visually-hidden"><?php esc_html_e('작업', 'oj-code-lab'); ?></span></th>
                            </tr>
                        </thead>
                        <tbody data-field="dataset-body"></tbody>
                    </table>
                </div>
            </section>
        </div>
        <?php

        return Page_Response::console(__('추천 데이터', 'oj-code-lab'), (string) ob_get_clean());
    }
}
