<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Quickboard;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use TeachingTools\Addon\Ui;

final class Quickboard_Host_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('teaching-tools/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'joinUrl' => home_url('/oj-addons/teaching-tools/join'),
        );

        ob_start();
        ?>
        <div class="htt-quickboard-host" data-htt-quickboard-host data-htt-host data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <?php echo Ui::tool_header(__('학생 미니 판서', 'teaching-tools'), __('학생이 각자 그린 답을 한 화면에서 확인하고 크게 띄웁니다.', 'teaching-tools')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

            <div class="htt-quickboard-setup" data-field="setup">
                <div class="htt-step">
                    <span class="htt-step-badge">1</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('판서 주제', 'teaching-tools'); ?></p>
                        <label>
                            <?php esc_html_e('학생에게 보여줄 질문 또는 안내', 'teaching-tools'); ?>
                            <input type="text" maxlength="200" data-field="prompt" placeholder="<?php echo esc_attr__('예: 분수 3/4을 그림으로 나타내세요.', 'teaching-tools'); ?>">
                        </label>
                        <label class="htt-quickboard-anonymous">
                            <input type="checkbox" data-field="anonymous">
                            <?php esc_html_e('교사 화면에서도 이름 대신 응답 번호로 표시', 'teaching-tools'); ?>
                        </label>
                    </div>
                </div>
                <button type="button" class="ojp-button ojp-button--primary ojp-button--large htt-primary-action" data-action="start">
                    <?php echo Ui::icon('whiteboard', 20); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php esc_html_e('미니 판서 시작', 'teaching-tools'); ?>
                </button>
            </div>

            <div class="htt-quickboard-message" data-field="message" role="status" hidden></div>

            <div class="htt-quickboard-session" data-field="session" hidden>
                <div class="htt-quickboard-sessionbar">
                    <div class="htt-classroom-code">
                        <span><?php esc_html_e('참여 코드', 'teaching-tools'); ?></span>
                        <strong data-field="join-code"></strong>
                    </div>
                    <p data-field="summary"><?php esc_html_e('응답을 기다리고 있습니다.', 'teaching-tools'); ?></p>
                    <div>
                        <button type="button" class="ojp-button ojp-button--tinted" data-action="copy-link"><?php echo Ui::icon('link', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e('참여 링크 복사', 'teaching-tools'); ?></button>
                        <button type="button" class="ojp-button ojp-button--plain" data-action="clear-all"><?php esc_html_e('모두 지우기', 'teaching-tools'); ?></button>
                        <button type="button" class="ojp-button ojp-button--plain" data-action="end"><?php esc_html_e('종료', 'teaching-tools'); ?></button>
                    </div>
                </div>
                <h2 class="htt-quickboard-prompt" data-field="prompt-display"></h2>
                <div class="htt-quickboard-gallery" data-field="gallery"></div>
            </div>

            <div class="htt-quickboard-spotlight" data-field="spotlight" hidden role="dialog" aria-modal="true" aria-labelledby="htt-quickboard-spotlight-title">
                <div class="htt-quickboard-spotlight-inner">
                    <div class="htt-quickboard-spotlight-heading">
                        <h2 id="htt-quickboard-spotlight-title" data-field="spotlight-title"></h2>
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="close-spotlight" aria-label="<?php echo esc_attr__('크게 보기 닫기', 'teaching-tools'); ?>"><?php echo Ui::icon('close', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                    </div>
                    <canvas data-field="spotlight-canvas" width="1000" height="600"></canvas>
                </div>
            </div>
        </div>
        <?php

        return Ui::page(__('학생 미니 판서', 'teaching-tools'), (string) ob_get_clean(), 'wide', true);
    }
}
