<?php
declare(strict_types=1);

namespace EduGames\Blocks;

use EduGames\Games\Modules\Image_Data\Image_Data_Module;

final class Image_Data_Renderer extends Abstract_Game_Renderer {
    /**
     * @param array<string, mixed> $attributes
     */
    public static function render(array $attributes = array(), bool $is_block = false): string {
        $config = self::build_config(Image_Data_Module::ID, Image_Data_Module::rules());
        $wrapper = self::wrapper_attributes($is_block, 'image-data-block');

        ob_start();
        ?>
        <section <?php echo $wrapper; ?> data-image-data data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <div class="oj-image-shell">
                <header class="oj-image-topbar">
                    <div>
                        <p class="oj-image-kicker"><?php esc_html_e('픽셀 데이터', 'edu-games'); ?></p>
                        <h2><?php esc_html_e('이미지 데이터 표현', 'edu-games'); ?></h2>
                    </div>
                    <div class="oj-image-actions">
                        <button type="button" class="oj-image-button oj-image-button-ghost" data-action="clear"><?php esc_html_e('비우기', 'edu-games'); ?></button>
                        <button type="button" class="oj-image-button oj-image-button-ghost" data-action="load-sample"><?php esc_html_e('샘플 불러오기', 'edu-games'); ?></button>
                        <button type="button" class="oj-image-button oj-image-button-primary" data-action="check"><?php esc_html_e('정답 확인', 'edu-games'); ?></button>
                    </div>
                </header>

                <div class="oj-image-controls" aria-label="<?php esc_attr_e('이미지 데이터 활동 설정', 'edu-games'); ?>">
                    <div class="oj-image-mode-tabs" data-field="modes" role="tablist"></div>
                    <label><span>격자 크기</span><select data-field="grid-size"><option value="8">8×8 입문</option><option value="16" selected>16×16</option></select></label>
                    <label class="oj-image-sample-select">
                        <span><?php esc_html_e('불러올 샘플', 'edu-games'); ?></span>
                        <select data-field="sample"></select>
                    </label>
                </div>

                <div class="oj-image-stats" aria-label="<?php esc_attr_e('이미지 데이터 상태', 'edu-games'); ?>">
                    <div class="oj-image-stat">
                        <span><?php esc_html_e('크기', 'edu-games'); ?></span>
                        <strong data-field="size">16x16</strong>
                    </div>
                    <div class="oj-image-stat">
                        <span><?php esc_html_e('선택 색상', 'edu-games'); ?></span>
                        <strong data-field="selected">0</strong>
                    </div>
                    <div class="oj-image-stat">
                        <span><?php esc_html_e('칠한 픽셀', 'edu-games'); ?></span>
                        <strong data-field="filled">0</strong>
                    </div>
                    <div class="oj-image-stat">
                        <span><?php esc_html_e('그림 부분 정확도', 'edu-games'); ?></span>
                        <strong data-field="accuracy">-</strong>
                    </div>
                    <div class="oj-image-stat"><span>잘못 칠한 배경</span><strong data-field="background-errors">-</strong></div>
                </div>

                <main class="oj-image-layout">
                    <section class="oj-image-panel oj-image-canvas-panel" aria-label="<?php esc_attr_e('픽셀 캔버스', 'edu-games'); ?>">
                        <div class="oj-image-panel-head">
                            <div>
                                <span data-field="sample-meta"></span>
                                <h3 data-field="sample-title"></h3>
                            </div>
                        </div>
                        <div class="oj-image-drawbar" data-field="draw-tools" hidden>
                            <div class="oj-image-toolset" data-field="tools" role="toolbar" aria-label="<?php esc_attr_e('그리기 도구', 'edu-games'); ?>"></div>
                            <div class="oj-image-history-tools">
                                <button type="button" class="oj-image-button oj-image-button-ghost" data-action="undo"><?php esc_html_e('되돌리기', 'edu-games'); ?></button>
                                <button type="button" class="oj-image-button oj-image-button-ghost" data-action="redo"><?php esc_html_e('다시하기', 'edu-games'); ?></button>
                                <button type="button" class="oj-image-button oj-image-button-ghost" data-action="export">배열 저장</button>
                                <label class="oj-image-button oj-image-button-ghost">배열 열기<input type="file" accept="application/json,.json" data-field="import" hidden></label>
                            </div>
                            <div class="oj-image-preview-box">
                                <span><?php esc_html_e('미리보기', 'edu-games'); ?></span>
                                <div class="oj-image-preview" data-field="preview"></div>
                            </div>
                        </div>
                        <div class="oj-image-board-wrap">
                            <div class="oj-image-board-stage">
                                <div class="oj-image-board" data-field="board"></div>
                                <div class="oj-image-data" data-field="data"></div>
                            </div>
                        </div>
                        <p class="oj-image-feedback" data-field="feedback" aria-live="polite"></p>
                    </section>

                    <aside class="oj-image-side">
                        <section class="oj-image-panel">
                            <div class="oj-image-panel-head">
                                <span><?php esc_html_e('16색 팔레트', 'edu-games'); ?></span>
                                <span><?php esc_html_e('0-F', 'edu-games'); ?></span>
                            </div>
                            <div class="oj-image-palette" data-field="palette"></div>
                        </section>

                    </aside>
                </main>

                <section class="oj-image-panel oj-image-answer-panel" data-field="answer-panel" hidden>
                    <div class="oj-image-panel-head">
                        <span><?php esc_html_e('정답 보기', 'edu-games'); ?></span>
                        <span data-field="answer-prompt"><?php esc_html_e('복원한 그림의 이름을 선택하세요.', 'edu-games'); ?></span>
                    </div>
                    <div class="oj-image-answer-choices" data-field="answer-choices"></div>
                </section>
                <details class="oj-image-collection"><summary data-field="collection-progress">복원 도감 · 이 기기</summary><div data-field="collection"></div></details>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }
}
