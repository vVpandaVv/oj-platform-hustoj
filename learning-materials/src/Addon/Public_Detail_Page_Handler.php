<?php
declare(strict_types=1);

namespace LearningMaterials\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use LearningMaterials\Materials\Material_Access;
use LearningMaterials\Materials\Material_Repository;
use LearningMaterials\Support\Capabilities;

final class Public_Detail_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        nocache_headers();
        $material_id = (int) ($request->parameters()['material_id'] ?? 0);
        $repository = new Material_Repository();
        $access = new Material_Access();
        $viewer_user_id = $request->actorUserId();

        $material = $repository->find($material_id);
        $group_ids = null !== $material ? $repository->group_ids_for($material_id) : array();

        if (null === $material || ! $access->can_view($viewer_user_id, $group_ids)
            || (! $material->is_available() && ! Capabilities::can_manage())) {
            return Page_Response::make(
                __('학습자료를 찾을 수 없습니다', 'learning-materials'),
                Page_Response::empty_state(
                    __('학습자료를 찾을 수 없습니다', 'learning-materials'),
                    __('자료가 삭제됐거나 이 자료를 볼 수 있는 그룹이 아닙니다.', 'learning-materials'),
                    home_url('/oj-addons/learning-materials'),
                    __('학습자료 목록', 'learning-materials')
                ),
                404
            );
        }

        $scope_label = array() === $group_ids
            ? __('전체 공개', 'learning-materials')
            : ($access->scope_label($viewer_user_id, $group_ids) ?: __('제한된 그룹 공개', 'learning-materials'));

        $view_url = add_query_arg(
            array('action' => 'learning_materials_view', 'material_id' => $material->id),
            admin_url('admin-post.php')
        );
        $download_url = add_query_arg(
            array('action' => 'learning_materials_download', 'material_id' => $material->id),
            admin_url('admin-post.php')
        );
        if ($material->is_google_link()) {
            $download_url = $material->source_url;
        }

        ob_start();
        ?>
        <div class="lmt-public">
            <header class="ojp-page-header">
                <div>
                    <p class="ojp-eyebrow"><?php echo esc_html(sprintf(
                        /* translators: %s: 전체 공개 or restricted group names */
                        __('학습자료 · %s', 'learning-materials'),
                        $scope_label
                    )); ?></p>
                    <h1><?php echo esc_html($material->title); ?></h1>
                    <?php Material_List_Ui::badges($material, Capabilities::can_manage()); ?>
                    <?php if ('' !== $material->description) : ?>
                        <p><?php echo nl2br(esc_html($material->description)); ?></p>
                    <?php endif; ?>
                </div>
                <div class="ojp-heading-actions">
                    <?php if ($material->is_viewable_inline()) : ?>
                        <a class="ojp-button" href="<?php echo esc_url($view_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('새 창에서 보기', 'learning-materials'); ?></a>
                    <?php endif; ?>
                    <a class="ojp-button ojp-button--primary" href="<?php echo esc_url($download_url); ?>" <?php if ($material->is_google_link()) : ?>target="_blank" rel="noopener noreferrer"<?php endif; ?>>
                        <?php echo $material->is_google_link() ? esc_html__('Google에서 열기', 'learning-materials') : esc_html(sprintf(
                            /* translators: %s: formatted file size */
                            __('다운로드 (%s)', 'learning-materials'),
                            $material->formatted_size()
                        )); ?>
                    </a>
                </div>
            </header>

            <section class="lmt-content-section">
                <?php if ($material->is_image()) : ?>
                    <img class="lmt-image-preview" src="<?php echo esc_url($view_url); ?>" alt="<?php echo esc_attr($material->title); ?>">
                <?php elseif ($material->is_viewable_inline()) : ?>
                    <iframe class="lmt-viewer" src="<?php echo esc_url($view_url); ?>" title="<?php echo esc_attr($material->title); ?>"></iframe>
                <?php elseif ($material->uses_google_viewer()) : ?>
                    <div class="lmt-google-preview"
                        data-endpoint="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                        data-material-id="<?php echo esc_attr((string) $material->id); ?>"
                        <?php if ($material->is_google_link()) : ?>
                        data-failure-message="<?php esc_attr_e('미리보기를 불러오지 못했습니다. Google에서 열어 주세요.', 'learning-materials'); ?>"
                        data-timeout-message="<?php esc_attr_e('자료가 보이지 않으면 Google에서 열어 주세요.', 'learning-materials'); ?>"
                        <?php endif; ?>
                        data-nonce="<?php echo esc_attr(wp_create_nonce('learning_materials_preview')); ?>">
                        <p class="lmt-preview-status" role="status" aria-live="polite"></p>
                        <div class="lmt-preview-frame"></div>
                        <div class="lmt-preview-actions">
                            <button type="button" class="ojp-button lmt-preview-open"><?php esc_html_e('다시 시도', 'learning-materials'); ?></button>
                            <a class="ojp-button lmt-preview-new-window" href="#" target="_blank" rel="noopener noreferrer" hidden><?php esc_html_e('새 창에서 보기', 'learning-materials'); ?></a>
                            <a class="ojp-button" href="<?php echo esc_url($download_url); ?>" <?php if ($material->is_google_link()) : ?>target="_blank" rel="noopener noreferrer"<?php endif; ?>><?php echo esc_html($material->is_google_link() ? __('Google에서 열기', 'learning-materials') : __('원본 다운로드', 'learning-materials')); ?></a>
                        </div>
                        <noscript><p><?php esc_html_e('미리보기에는 JavaScript가 필요합니다. 상단 버튼으로 원본을 열어 주세요.', 'learning-materials'); ?></p></noscript>
                    </div>
                <?php else : ?>
                    <div class="ojp-notice ojp-notice--info"><p><?php esc_html_e('이 형식은 미리보기를 지원하지 않습니다. 원본을 다운로드해서 열어 주세요.', 'learning-materials'); ?></p></div>
                <?php endif; ?>
            </section>
            <?php if (Capabilities::can_manage()) : ?>
                <details class="lmt-diagnostics" data-endpoint="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-material-id="<?php echo esc_attr((string) $material->id); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('learning_materials_diagnostics')); ?>">
                    <summary><?php esc_html_e('관리자 미리보기 진단', 'learning-materials'); ?></summary>
                    <p><?php esc_html_e('원본·임시 링크·외부 뷰어 응답을 확인합니다.', 'learning-materials'); ?></p>
                    <button type="button" class="ojp-button lmt-diagnostic-run"><?php esc_html_e('진단 실행', 'learning-materials'); ?></button>
                    <div class="lmt-diagnostic-results" role="status" aria-live="polite"></div>
                </details>
            <?php endif; ?>
        </div>
        <?php

        return Page_Response::make($material->title, (string) ob_get_clean());
    }
}
