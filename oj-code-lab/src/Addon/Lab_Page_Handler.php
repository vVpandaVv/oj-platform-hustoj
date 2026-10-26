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
use OjCodeLab\Support\Worker_Script_Route;

/**
 * Renders the notebook shell. All code execution happens client-side in
 * the browser (Pyodide/WASM) — this handler only hands the page its own
 * REST endpoint and nonce; resources/js/lab.js owns the rest.
 */
final class Lab_Page_Handler implements AddonPageHandlerV1 {
    /** Lucide "play" and "plus", drawn by the platform .ojp-icon rule. */
    private const ICON_PLAY = '<svg class="ojp-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><polygon points="6 3 20 12 6 21 6 3"/></svg>';
    private const ICON_PLUS = '<svg class="ojp-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M5 12h14"/><path d="M12 5v14"/></svg>';

    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('oj-code-lab/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'editor' => Runtime_Bridge::editor_config(),
            // Served through WordPress (Support\Worker_Script_Route), not
            // as a static file — a static file can't carry the
            // Cross-Origin-Resource-Policy header this Worker script needs
            // once Cross_Origin_Isolation turns on COEP:require-corp for
            // this page (confirmed as a real bug on a live install: without
            // it, `new Worker(...)` silently never loads).
            'workerUrl' => esc_url_raw(Worker_Script_Route::url()),
        );

        ob_start();
        ?>
        <div class="ocl-lab" data-ocl-lab data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <header class="ojp-page-header ocl-lab-header">
                <div>
                    <p class="ojp-eyebrow"><?php esc_html_e('학습 활동', 'oj-code-lab'); ?></p>
                    <h1><?php esc_html_e('코드 실습실', 'oj-code-lab'); ?></h1>
                    <p><?php esc_html_e('파이썬 코드를 자유롭게 연습합니다. 코드는 내 브라우저에서만 실행되고, 저장하면 다음에 이어서 볼 수 있습니다.', 'oj-code-lab'); ?></p>
                </div>
            </header>
            <p class="ocl-network-notice"><?php esc_html_e('처음 실행할 때 외부 CDN에서 Python 실행 환경을 받습니다. 코드·텍스트·입력·출력은 자동 저장되며 담당 교사가 볼 수 있습니다.', 'oj-code-lab'); ?></p>
            <div class="ocl-toolbar" role="toolbar" aria-label="<?php esc_attr_e('노트북 도구', 'oj-code-lab'); ?>">
                <div class="ocl-toolbar-group">
                    <button type="button" class="ojp-button ojp-button--primary ojp-button--small" data-action="run-all"><?php echo self::ICON_PLAY; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e('전체 실행', 'oj-code-lab'); ?></span></button>
                    <button type="button" class="ojp-button ojp-button--small" data-action="stop"><?php esc_html_e('실행 중단', 'oj-code-lab'); ?></button>
                    <button type="button" class="ojp-button ojp-button--small" data-action="restart"><?php esc_html_e('런타임 재시작', 'oj-code-lab'); ?></button>
                </div>
                <div class="ocl-toolbar-group">
                    <button type="button" class="ojp-button ojp-button--plain ojp-button--small" data-action="add-cell"><?php echo self::ICON_PLUS; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e('코드 셀', 'oj-code-lab'); ?></span></button>
                    <button type="button" class="ojp-button ojp-button--plain ojp-button--small" data-action="add-text-cell"><?php echo self::ICON_PLUS; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e('텍스트 셀', 'oj-code-lab'); ?></span></button>
                </div>
                <div class="ocl-toolbar-group">
                    <button type="button" class="ojp-button ojp-button--small" data-action="toggle-datasets"><?php esc_html_e('데이터 가져오기', 'oj-code-lab'); ?></button>
                    <button type="button" class="ojp-button ojp-button--small" data-action="download"><?php esc_html_e('노트북 다운로드', 'oj-code-lab'); ?></button>
                    <button type="button" class="ojp-button ojp-button--small" data-action="reload"><?php esc_html_e('최신 노트북 불러오기', 'oj-code-lab'); ?></button>
                </div>
                <div class="ocl-toolbar-group ocl-toolbar-save">
                    <span class="ocl-status" data-field="status" role="status" aria-live="polite"></span>
                    <button type="button" class="ojp-button ojp-button--tinted ojp-button--small" data-action="save"><?php esc_html_e('저장', 'oj-code-lab'); ?></button>
                </div>
            </div>
            <div class="ocl-datasets" data-field="datasets-panel" hidden>
                <p class="ocl-description"><?php esc_html_e('CSV·JSONL·NDJSON 또는 ZIP 파일을 고릅니다. ZIP 안에 지원 파일이 하나이면 자동으로 가져옵니다. 여러 개이면 원하는 파일을 따로 고르거나 선생님께 그 파일을 추천 데이터로 추가해 달라고 요청하세요.', 'oj-code-lab'); ?></p>
                <div class="ocl-datasets-section">
                    <h2><?php esc_html_e('추천 데이터', 'oj-code-lab'); ?></h2>
                    <div class="ocl-dataset-list" data-field="recommended-list">
                        <p class="ocl-empty"><?php esc_html_e('불러오는 중…', 'oj-code-lab'); ?></p>
                    </div>
                </div>
                <div class="ocl-datasets-section">
                    <h2><?php esc_html_e('Kaggle에서 검색', 'oj-code-lab'); ?></h2>
                    <form class="ocl-dataset-search" data-field="search-form" role="search">
                        <label class="ojp-visually-hidden" for="ocl-lab-search"><?php esc_html_e('Kaggle 검색어', 'oj-code-lab'); ?></label>
                        <input type="text" id="ocl-lab-search" class="ojp-input" data-field="search-input" placeholder="<?php esc_attr_e('예: titanic, traffic accident', 'oj-code-lab'); ?>">
                        <button type="submit" class="ojp-button ojp-button--small"><?php esc_html_e('검색', 'oj-code-lab'); ?></button>
                    </form>
                    <div class="ocl-dataset-list" data-field="search-results"></div>
                </div>
                <div class="ocl-datasets-section" data-field="files-section" hidden>
                    <h2 data-field="files-title"></h2>
                    <div class="ocl-dataset-list" data-field="files-list"></div>
                </div>
            </div>
            <div class="ocl-cells" data-field="cells">
                <p class="ocl-empty" data-field="loading"><?php esc_html_e('불러오는 중…', 'oj-code-lab'); ?></p>
            </div>
        </div>
        <?php

        return Page_Response::notebook(__('코드 실습실', 'oj-code-lab'), (string) ob_get_clean());
    }
}
