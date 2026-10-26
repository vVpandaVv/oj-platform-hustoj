<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Operations;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use TeachingTools\Addon\Ui;

final class Operations_Host_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $config = array(
            'restUrl' => esc_url_raw(rest_url('teaching-tools/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        );
        ob_start();
        ?>
        <div class="htt-operations" data-htt-operations data-htt-host data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <?php echo Ui::tool_header(__('수업 운영 보드', 'teaching-tools'), __('수업 순서, 활동 모드, 신호등, 타이머와 교실 소음을 한 화면에서 관리합니다.', 'teaching-tools'), false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

            <div class="htt-operations-topbar">
                <span data-field="save-status" role="status"><?php esc_html_e('이 브라우저에 자동 저장됩니다.', 'teaching-tools'); ?></span>
                <div class="htt-operations-templates">
                    <input type="text" maxlength="120" data-field="template-name" placeholder="<?php echo esc_attr__('수업안 이름', 'teaching-tools'); ?>" aria-label="<?php echo esc_attr__('수업안 이름', 'teaching-tools'); ?>">
                    <select data-field="template-select" aria-label="<?php echo esc_attr__('저장된 수업안', 'teaching-tools'); ?>"><option value=""><?php esc_html_e('저장된 수업안', 'teaching-tools'); ?></option></select>
                    <button type="button" class="ojp-button ojp-button--plain" data-action="template-save"><?php esc_html_e('저장', 'teaching-tools'); ?></button>
                    <button type="button" class="ojp-button ojp-button--plain" data-action="template-load"><?php esc_html_e('불러오기', 'teaching-tools'); ?></button>
                    <button type="button" class="ojp-button ojp-button--danger" data-action="template-delete"><?php esc_html_e('삭제', 'teaching-tools'); ?></button>
                </div>
                <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="fullscreen" aria-label="<?php echo esc_attr__('전체화면', 'teaching-tools'); ?>" title="<?php echo esc_attr__('전체화면', 'teaching-tools'); ?>"><?php echo Ui::icon('fullscreen'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
            </div>

            <div class="htt-operations-board" data-field="board">
                <section class="htt-operations-agenda" aria-labelledby="htt-operations-agenda-title">
                    <div class="htt-operations-section-heading">
                        <div><p><?php esc_html_e('오늘 수업', 'teaching-tools'); ?></p><h2 id="htt-operations-agenda-title"><?php esc_html_e('수업 순서', 'teaching-tools'); ?></h2></div>
                    </div>
                    <form class="htt-operations-agenda-form" data-field="agenda-form">
                        <input type="text" maxlength="80" data-field="agenda-input" placeholder="<?php echo esc_attr__('활동을 입력하세요.', 'teaching-tools'); ?>">
                        <button type="submit" class="ojp-button ojp-button--primary htt-icon-button" aria-label="<?php echo esc_attr__('활동 추가', 'teaching-tools'); ?>" title="<?php echo esc_attr__('활동 추가', 'teaching-tools'); ?>"><?php echo Ui::icon('plus'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                    </form>
                    <ol class="htt-operations-agenda-list" data-field="agenda-list"></ol>
                </section>

                <section class="htt-operations-focus" aria-labelledby="htt-operations-mode-title">
                    <div class="htt-operations-section-heading">
                        <div><p><?php esc_html_e('지금', 'teaching-tools'); ?></p><h2 id="htt-operations-mode-title"><?php esc_html_e('활동 모드', 'teaching-tools'); ?></h2></div>
                    </div>
                    <div class="htt-operations-mode-display" data-field="mode-display">교사 설명</div>
                    <div class="htt-operations-mode-options" data-field="mode-options">
                        <button type="button" data-mode="explain"><?php esc_html_e('설명', 'teaching-tools'); ?></button>
                        <button type="button" data-mode="solo"><?php esc_html_e('개별', 'teaching-tools'); ?></button>
                        <button type="button" data-mode="pair"><?php esc_html_e('짝', 'teaching-tools'); ?></button>
                        <button type="button" data-mode="group"><?php esc_html_e('모둠', 'teaching-tools'); ?></button>
                        <button type="button" data-mode="wrap">정리</button>
                    </div>

                    <div class="htt-operations-traffic" data-field="traffic" aria-label="<?php echo esc_attr__('교실 신호등', 'teaching-tools'); ?>">
                        <button type="button" data-light="red" aria-label="<?php echo esc_attr__('멈춤', 'teaching-tools'); ?>"><span></span><strong><?php esc_html_e('멈춤', 'teaching-tools'); ?></strong></button>
                        <button type="button" data-light="yellow" aria-label="<?php echo esc_attr__('준비', 'teaching-tools'); ?>"><span></span><strong><?php esc_html_e('준비', 'teaching-tools'); ?></strong></button>
                        <button type="button" data-light="green" class="is-active" aria-label="<?php echo esc_attr__('진행', 'teaching-tools'); ?>"><span></span><strong><?php esc_html_e('진행', 'teaching-tools'); ?></strong></button>
                    </div>
                </section>

                <section class="htt-operations-timer" aria-labelledby="htt-operations-timer-title">
                    <div class="htt-operations-section-heading">
                        <div><p><?php esc_html_e('남은 시간', 'teaching-tools'); ?></p><h2 id="htt-operations-timer-title"><?php esc_html_e('활동 타이머', 'teaching-tools'); ?></h2></div>
                    </div>
                    <div class="htt-operations-clock" data-field="clock">05:00</div>
                    <div class="htt-operations-timer-controls">
                        <label><span><?php esc_html_e('분', 'teaching-tools'); ?></span><input type="number" min="1" max="180" value="5" data-field="minutes"></label>
                        <button type="button" class="ojp-button ojp-button--primary htt-icon-button" data-action="timer-start" aria-label="<?php echo esc_attr__('타이머 시작', 'teaching-tools'); ?>" title="<?php echo esc_attr__('시작', 'teaching-tools'); ?>"><?php echo Ui::icon('play'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="timer-pause" aria-label="<?php echo esc_attr__('타이머 일시정지', 'teaching-tools'); ?>" title="<?php echo esc_attr__('일시정지', 'teaching-tools'); ?>"><?php echo Ui::icon('pause'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="timer-reset" aria-label="<?php echo esc_attr__('타이머 초기화', 'teaching-tools'); ?>" title="<?php echo esc_attr__('초기화', 'teaching-tools'); ?>"><?php echo Ui::icon('reset'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                    </div>

                    <div class="htt-operations-noise">
                        <div class="htt-operations-section-heading">
                            <div><p><?php esc_html_e('선택 기능', 'teaching-tools'); ?></p><h2><?php esc_html_e('소음 신호', 'teaching-tools'); ?></h2></div>
                            <button type="button" class="ojp-button ojp-button--tinted" data-action="noise-toggle"><?php echo Ui::icon('volume', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span data-field="noise-toggle-label"><?php esc_html_e('마이크 켜기', 'teaching-tools'); ?></span></button>
                        </div>
                        <div class="htt-operations-noise-meter" data-field="noise-meter"><span data-field="noise-level"></span><i data-field="noise-threshold-marker"></i></div>
                        <label class="htt-operations-threshold"><?php esc_html_e('주의 기준', 'teaching-tools'); ?><input type="range" min="10" max="90" value="55" data-field="noise-threshold"><output data-field="noise-threshold-output">55</output></label>
                        <p data-field="noise-status"><?php esc_html_e('마이크를 켜면 현재 기기에서만 소음 크기를 계산합니다.', 'teaching-tools'); ?></p>
                    </div>
                </section>
            </div>
        </div>
        <?php

        return Ui::page(__('수업 운영 보드', 'teaching-tools'), (string) ob_get_clean(), 'wide', true);
    }
}
