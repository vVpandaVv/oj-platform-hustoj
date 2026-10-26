<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Whiteboard;

use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use TeachingTools\Addon\Seating\Runtime_Bridge;
use TeachingTools\Addon\Ui;
use TeachingTools\Sessions\Session_State_Policy;

final class Whiteboard_Host_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $materials = self::learning_materials();
        $config = array(
            'restUrl' => esc_url_raw(rest_url('teaching-tools/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'joinUrl' => home_url('/oj-addons/teaching-tools/join'),
            'materials' => $materials,
            'maxPages' => Session_State_Policy::MAX_WHITEBOARD_PAGES,
        );

        ob_start();
        ?>
        <div class="htt-whiteboard-host" data-htt-whiteboard-host data-htt-host data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
            <?php echo Ui::tool_header(__('판서', 'teaching-tools'), __('수업용 디지털 판서 캔버스입니다.', 'teaching-tools')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

            <div class="htt-whiteboard-setup" data-field="setup">
                <div class="htt-step">
                    <span class="htt-step-badge">1</span>
                    <div class="htt-step-body">
                        <p class="htt-step-title"><?php esc_html_e('진행 방식', 'teaching-tools'); ?></p>
                        <div class="htt-mode-toggle">
                            <label>
                                <input type="radio" name="htt-whiteboard-mode" value="solo" checked>
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('user', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text">
                                    <strong><?php esc_html_e('교사 화면만', 'teaching-tools'); ?></strong>
                                    <span><?php esc_html_e('단일 화면', 'teaching-tools'); ?></span>
                                </span>
                            </label>
                            <label>
                                <input type="radio" name="htt-whiteboard-mode" value="live">
                                <span class="htt-mode-toggle-icon" aria-hidden="true"><?php echo Ui::icon('users', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="htt-mode-toggle-text">
                                    <strong><?php esc_html_e('학생 화면에도 실시간 표시', 'teaching-tools'); ?></strong>
                                    <span><?php esc_html_e('동시 참여', 'teaching-tools'); ?></span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
                <?php if (array() !== $materials) : ?>
                    <div class="htt-step">
                        <span class="htt-step-badge">2</span>
                        <div class="htt-step-body">
                            <p class="htt-step-title"><?php esc_html_e('학습자료 배경', 'teaching-tools'); ?></p>
                            <div class="htt-whiteboard-material-fields">
                                <label>
                                    <?php esc_html_e('PDF 자료', 'teaching-tools'); ?>
                                    <select data-field="material-select">
                                        <option value=""><?php esc_html_e('빈 칠판', 'teaching-tools'); ?></option>
                                        <?php foreach ($materials as $material) : ?>
                                            <option value="<?php echo esc_attr((string) $material['id']); ?>"><?php echo esc_html((string) $material['title']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <?php esc_html_e('가져올 범위', 'teaching-tools'); ?>
                                    <select data-field="material-import-mode"><option value="all"><?php esc_html_e('전체 페이지', 'teaching-tools'); ?></option><option value="single"><?php esc_html_e('선택한 한 쪽', 'teaching-tools'); ?></option></select>
                                </label>
                                <label>
                                    <?php esc_html_e('시작 쪽', 'teaching-tools'); ?>
                                    <input type="number" min="1" max="999" value="1" data-field="material-page">
                                </label>
                            </div>
                            <button type="button" class="ojp-button ojp-button--plain" data-action="preview-initial-material"><?php esc_html_e('PDF 미리보기', 'teaching-tools'); ?></button>
                        </div>
                    </div>
                <?php endif; ?>
                <label class="htt-whiteboard-material-help"><?php esc_html_e('학생 PDF 배경', 'teaching-tools'); ?>
                    <select data-field="background-sharing"><option value="permissions"><?php esc_html_e('기존 자료 열람 권한 유지', 'teaching-tools'); ?></option><option value="session"><?php esc_html_e('이 수업 참가자에게 배경 공유', 'teaching-tools'); ?></option></select>
                </label>
                <button type="button" class="ojp-button ojp-button--primary ojp-button--large htt-primary-action" data-action="start"><?php esc_html_e('칠판 열기', 'teaching-tools'); ?></button>
            </div>

            <div class="htt-whiteboard-messages" data-field="messages"></div>

            <div class="htt-whiteboard-board" data-field="board" hidden>
                <p class="htt-whiteboard-connection" data-field="connection-status" role="status" hidden></p>
                <p class="htt-whiteboard-connection" data-field="background-sharing-status" role="status" hidden></p>
                <details class="htt-whiteboard-join" data-field="join-info" open hidden>
                    <summary><?php esc_html_e('참여 코드 · QR', 'teaching-tools'); ?><span class="htt-join-collapse"><?php esc_html_e('접기', 'teaching-tools'); ?></span><span class="htt-join-expand"><?php esc_html_e('펼치기', 'teaching-tools'); ?></span></summary>
                    <div class="htt-timer-code">
                    <p><?php esc_html_e('참여 코드', 'teaching-tools'); ?></p>
                    <strong data-field="join-code"></strong>
                    <br>
                    <button type="button" class="ojp-button ojp-button--tinted ojp-button--small htt-join-link" data-action="copy-link"><?php esc_html_e('참여 링크 복사', 'teaching-tools'); ?></button>
                    </div>
                </details>

                <div class="htt-whiteboard-toolbar">
                    <span class="htt-whiteboard-tools" data-field="tools">
                        <button type="button" class="htt-whiteboard-tool is-active" data-tool="pen" aria-label="<?php echo esc_attr__('펜', 'teaching-tools'); ?>" title="<?php echo esc_attr__('펜', 'teaching-tools'); ?>"><?php echo Ui::icon('pen'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <button type="button" class="htt-whiteboard-tool" data-tool="eraser" aria-label="<?php echo esc_attr__('지우개', 'teaching-tools'); ?>" title="<?php echo esc_attr__('지우개', 'teaching-tools'); ?>"><?php echo Ui::icon('eraser'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <button type="button" class="htt-whiteboard-tool" data-tool="line" aria-label="<?php echo esc_attr__('직선', 'teaching-tools'); ?>" title="<?php echo esc_attr__('직선', 'teaching-tools'); ?>"><?php echo Ui::icon('line'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <button type="button" class="htt-whiteboard-tool" data-tool="rect" aria-label="<?php echo esc_attr__('사각형', 'teaching-tools'); ?>" title="<?php echo esc_attr__('사각형', 'teaching-tools'); ?>"><?php echo Ui::icon('rect'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <button type="button" class="htt-whiteboard-tool" data-tool="circle" aria-label="<?php echo esc_attr__('원', 'teaching-tools'); ?>" title="<?php echo esc_attr__('원', 'teaching-tools'); ?>"><?php echo Ui::icon('circle'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <button type="button" class="htt-whiteboard-tool" data-tool="arrow" aria-label="<?php echo esc_attr__('화살표', 'teaching-tools'); ?>" title="<?php echo esc_attr__('화살표', 'teaching-tools'); ?>"><?php echo Ui::icon('arrow'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <button type="button" class="htt-whiteboard-tool" data-tool="text" aria-label="<?php echo esc_attr__('텍스트', 'teaching-tools'); ?>" title="<?php echo esc_attr__('텍스트', 'teaching-tools'); ?>"><?php echo Ui::icon('text'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                    </span>

                    <span class="htt-whiteboard-colors" data-field="colors">
                        <button type="button" class="htt-whiteboard-color is-active" data-color="#1a1a1a" style="background:#1a1a1a" aria-label="검정"></button>
                        <button type="button" class="htt-whiteboard-color" data-color="#e03131" style="background:#e03131" aria-label="빨강"></button>
                        <button type="button" class="htt-whiteboard-color" data-color="#1971c2" style="background:#1971c2" aria-label="파랑"></button>
                        <button type="button" class="htt-whiteboard-color" data-color="#2f9e44" style="background:#2f9e44" aria-label="초록"></button>
                        <button type="button" class="htt-whiteboard-color" data-color="#f08c00" style="background:#f08c00" aria-label="주황"></button>
                    </span>
                    <label>
                        <?php esc_html_e('굵기', 'teaching-tools'); ?>
                        <input type="range" min="2" max="16" value="4" data-field="width">
                    </label>

                    <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="undo" aria-label="<?php echo esc_attr__('되돌리기', 'teaching-tools'); ?>" title="<?php echo esc_attr__('되돌리기', 'teaching-tools'); ?>"><?php echo Ui::icon('undo'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                    <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="redo" aria-label="<?php echo esc_attr__('다시하기', 'teaching-tools'); ?>" title="<?php echo esc_attr__('다시하기', 'teaching-tools'); ?>"><?php echo Ui::icon('redo'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                    <button type="button" class="ojp-button ojp-button--plain htt-icon-button htt-icon-button--danger" data-action="clear" aria-label="<?php echo esc_attr__('전체 지우기', 'teaching-tools'); ?>" title="<?php echo esc_attr__('전체 지우기', 'teaching-tools'); ?>"><?php echo Ui::icon('trash'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>

                    <span class="htt-whiteboard-pages">
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="prev-page" aria-label="<?php echo esc_attr__('이전 페이지', 'teaching-tools'); ?>"><?php echo Ui::icon('chevron-left'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <span data-field="page-indicator" class="ojp-status-badge ojp-status-badge--neutral">1 / 1</span>
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="next-page" aria-label="<?php echo esc_attr__('다음 페이지', 'teaching-tools'); ?>"><?php echo Ui::icon('chevron-right'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <button type="button" class="ojp-button ojp-button--plain" data-action="add-page"><?php esc_html_e('페이지 추가', 'teaching-tools'); ?></button>
                        <button type="button" class="ojp-button ojp-button--plain" data-action="choose-material"><?php esc_html_e('PDF 배경', 'teaching-tools'); ?></button>
                        <select data-field="background-sharing-live" aria-label="<?php echo esc_attr__('학생 PDF 배경', 'teaching-tools'); ?>"><option value="permissions"><?php esc_html_e('자료 권한 유지', 'teaching-tools'); ?></option><option value="session"><?php esc_html_e('수업 배경 공유', 'teaching-tools'); ?></option></select>
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="retry-background-sharing" aria-label="<?php echo esc_attr__('배경 공유 다시 시도', 'teaching-tools'); ?>" title="<?php echo esc_attr__('배경 공유 다시 시도', 'teaching-tools'); ?>" hidden><?php echo Ui::icon('reset'); ?></button>
                    </span>

                    <label class="htt-whiteboard-upload">
                        <?php esc_html_e('이미지 삽입', 'teaching-tools'); ?>
                        <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" data-field="image-upload">
                    </label>

                    <button type="button" class="ojp-button ojp-button--plain" data-action="save-image"><?php esc_html_e('이미지로 저장', 'teaching-tools'); ?></button>
                    <button type="button" class="ojp-button ojp-button--plain" data-action="fullscreen"><?php esc_html_e('전체화면', 'teaching-tools'); ?></button>
                    <button type="button" class="ojp-button ojp-button--danger" data-action="close"><?php esc_html_e('칠판 닫기', 'teaching-tools'); ?></button>
                </div>

                <div class="htt-whiteboard-canvas-wrap" data-field="canvas-wrap">
                    <canvas class="htt-whiteboard-material-frame" data-field="material-frame" width="1000" height="600" aria-label="<?php echo esc_attr__('학습자료 PDF 배경', 'teaching-tools'); ?>" hidden></canvas>
                    <canvas class="htt-whiteboard-canvas" data-field="canvas" width="1000" height="600" tabindex="0" draggable="false" aria-label="<?php echo esc_attr__('판서 입력', 'teaching-tools'); ?>"></canvas>
                    <canvas class="htt-whiteboard-canvas htt-whiteboard-canvas-preview" data-field="preview-canvas" width="1000" height="600"></canvas>
                </div>
            </div>
                <dialog class="htt-pdf-dialog" data-field="material-dialog" aria-labelledby="htt-pdf-dialog-title">
                    <div class="htt-pdf-heading"><h2 id="htt-pdf-dialog-title"><?php esc_html_e('PDF 페이지 선택', 'teaching-tools'); ?></h2>
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="close-material" aria-label="<?php echo esc_attr__('닫기', 'teaching-tools'); ?>"><?php echo Ui::icon('close'); ?></button>
                    </div>
                    <div class="htt-pdf-controls">
                        <label><?php esc_html_e('PDF 자료', 'teaching-tools'); ?><select data-field="pdf-material"></select></label>
                        <label><?php esc_html_e('가져올 범위', 'teaching-tools'); ?><select data-field="pdf-import-mode"><option value="all"><?php esc_html_e('전체 페이지', 'teaching-tools'); ?></option><option value="single"><?php esc_html_e('선택한 한 쪽', 'teaching-tools'); ?></option></select></label>
                        <button type="button" class="ojp-button ojp-button--plain" data-action="refresh-materials"><?php esc_html_e('자료 새로고침', 'teaching-tools'); ?></button>
                        <label><?php esc_html_e('쪽', 'teaching-tools'); ?><input type="number" data-field="pdf-page" value="1" min="1" max="1"></label>
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="pdf-prev" aria-label="<?php echo esc_attr__('이전 쪽', 'teaching-tools'); ?>"><?php echo Ui::icon('chevron-left'); ?></button>
                        <span data-field="pdf-page-count"></span>
                        <button type="button" class="ojp-button ojp-button--plain htt-icon-button" data-action="pdf-next" aria-label="<?php echo esc_attr__('다음 쪽', 'teaching-tools'); ?>"><?php echo Ui::icon('chevron-right'); ?></button>
                    </div>
                    <p data-field="pdf-status" role="status"></p>
                    <canvas class="htt-pdf-preview" data-field="pdf-preview" width="1000" height="600"></canvas>
                    <div class="htt-pdf-actions">
                        <button type="button" class="ojp-button ojp-button--plain" data-action="add-blank-page"><?php esc_html_e('빈 페이지 추가', 'teaching-tools'); ?></button>
                        <button type="button" class="ojp-button ojp-button--plain" data-action="apply-material"><?php esc_html_e('현재 페이지 배경으로 적용', 'teaching-tools'); ?></button>
                        <button type="button" class="ojp-button ojp-button--primary" data-action="insert-material"><?php esc_html_e('새 페이지로 추가', 'teaching-tools'); ?></button>
                    </div>
                </dialog>
        </div>
        <?php

        return Ui::page(__('판서', 'teaching-tools'), (string) ob_get_clean(), 'wide', false);
    }

    /** @return list<array{id:string,title:string,url:string}> */
    private static function learning_materials(): array {
        $gateway = Runtime_Bridge::integrations();
        if (null === $gateway) {
            return array();
        }

        try {
            $page = $gateway->searchActivities(new AddonActivityQueryV1(
                get_current_user_id(),
                '',
                array('material'),
                100
            ));
            if (! $page->isSuccessful()) {
                return array();
            }

            return array_values(array_map(
                static fn ($activity): array => array(
                    'id' => $activity->key(),
                    'title' => $activity->title(),
                    'url' => $activity->url(),
                ),
                array_filter(
                    $page->items(),
                    static fn ($activity): bool => 'application/pdf' === strtolower(trim(explode(';', $activity->contentType())[0]))
                )
            ));
        } catch (\Throwable $error) {
            return array();
        }
    }
}
