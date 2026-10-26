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
use OjCodeLab\Datasets\Kaggle_Settings;

/**
 * 교사 메뉴 › 코드 실습실 › 설정: stores the site-wide Kaggle API credentials
 * (docs/README §Kaggle 연동). Open to site administrators and to teachers
 * granted "코드 실습실 설정" (Capabilities::SETTINGS). The key is never redisplayed once saved —
 * only whether it's currently configured.
 */
final class Console_Settings_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('oj-code-lab/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'configured' => Kaggle_Settings::is_configured(),
            'username' => Kaggle_Settings::username(),
        );

        ob_start();
        ?>
        <div class="ocl-console ocl-console-settings" data-ocl-settings data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <header class="ojp-page-header">
                <div>
                    <p class="ojp-eyebrow"><?php esc_html_e('학습 활동', 'oj-code-lab'); ?></p>
                    <h1><?php esc_html_e('설정', 'oj-code-lab'); ?></h1>
                    <p><?php esc_html_e('추천 데이터를 Kaggle에서 찾을 때 쓰는 계정을 연결합니다. 이 정보는 학생 브라우저에 전달하지 않으며, 검색과 다운로드는 항상 이 서버를 거칩니다.', 'oj-code-lab'); ?></p>
                </div>
            </header>

            <div class="ocl-messages" data-field="messages" aria-live="polite"></div>

            <section class="ocl-panel">
                <h2><?php esc_html_e('Kaggle 연동', 'oj-code-lab'); ?></h2>
                <p class="ocl-status-line" data-field="status"></p>
                <p class="ocl-description"><?php esc_html_e('연동 후 검색이나 가져오기를 요청하면 사용자 이름, API 키, 검색어, 데이터 식별자를 사이트 서버에서 Kaggle로 보냅니다.', 'oj-code-lab'); ?> <a href="https://www.kaggle.com/terms" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Kaggle 이용약관', 'oj-code-lab'); ?></a> · <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer"><?php esc_html_e('개인정보 안내', 'oj-code-lab'); ?></a></p>
                <form data-field="settings-form" class="ocl-form">
                    <div class="ojp-field">
                        <label for="ocl-kaggle-username"><?php esc_html_e('Kaggle 사용자 이름', 'oj-code-lab'); ?></label>
                        <input type="text" id="ocl-kaggle-username" class="ojp-input" data-field="username" autocomplete="off">
                    </div>
                    <div class="ojp-field">
                        <label for="ocl-kaggle-key"><?php esc_html_e('Kaggle API 키', 'oj-code-lab'); ?></label>
                        <input type="password" id="ocl-kaggle-key" class="ojp-input" data-field="key" autocomplete="off" aria-describedby="ocl-kaggle-key-help">
                        <p class="ojp-field-help" id="ocl-kaggle-key-help"><?php esc_html_e('바꿀 때만 입력합니다. 비워 두면 저장된 키를 그대로 씁니다.', 'oj-code-lab'); ?></p>
                    </div>
                    <label class="ojp-toggle-row"><input type="checkbox" data-field="clear"> <span><?php esc_html_e('저장된 Kaggle 연동 정보 삭제', 'oj-code-lab'); ?></span></label>
                    <div class="ocl-form-actions">
                        <button type="submit" class="ojp-button ojp-button--primary"><?php esc_html_e('저장', 'oj-code-lab'); ?></button>
                    </div>
                </form>
            </section>
        </div>
        <?php

        return Page_Response::console(__('코드 실습실 설정', 'oj-code-lab'), (string) ob_get_clean());
    }
}
