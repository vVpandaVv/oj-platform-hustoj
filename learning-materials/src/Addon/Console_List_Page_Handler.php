<?php
declare(strict_types=1);

namespace LearningMaterials\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use LearningMaterials\Materials\Material_Access;
use LearningMaterials\Materials\Material_Repository;
use LearningMaterials\Materials\Material_Catalog;

final class Console_List_Page_Handler implements AddonPageHandlerV1 {
    /** Notices the save/delete actions redirect back with (?lmt_notice=). */
    private const NOTICES = array(
        'created' => '학습자료를 등록했습니다',
        'saved' => '변경 내용을 저장했습니다',
        'deleted' => '학습자료를 삭제했습니다',
    );

    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $repository = new Material_Repository();
        $access = new Material_Access();
        $choices = $access->group_choices($request->actorUserId());
        $group_map = $repository->group_ids_map();
        $result = Material_Catalog::page($repository->all(), $group_map, $request->query(), static fn (array $ids): bool => true, true, null, 20);
        $materials = $result['items'];
        $list_url = home_url('/oj-console/addons/learning-materials');
        $notice = $request->query()['lmt_notice'] ?? '';
        $notice = is_string($notice) ? (self::NOTICES[$notice] ?? '') : '';
        $error = $request->query()['lmt_error'] ?? '';
        $error = is_string($error) ? $error : '';
        $new_url = home_url('/oj-console/addons/learning-materials/new');

        ob_start();
        ?>
        <div class="lmt-console">
            <header class="ojp-page-header">
                <div>
                    <p class="ojp-eyebrow"><?php esc_html_e('수업', 'learning-materials'); ?></p>
                    <h1><?php esc_html_e('학습자료', 'learning-materials'); ?></h1>
                    <p><?php esc_html_e('파일이나 Google 공유 링크를 등록하고 공개 그룹을 정합니다.', 'learning-materials'); ?></p>
                </div>
                <div class="ojp-heading-actions">
                    <a class="ojp-button ojp-button--primary" href="<?php echo esc_url($new_url); ?>">
                        <?php esc_html_e('자료 올리기', 'learning-materials'); ?>
                    </a>
                </div>
            </header>

            <?php if ('' !== $notice) : ?>
                <div class="ojp-notice ojp-notice--success" role="status" data-ojp-flash><strong><?php echo esc_html($notice); ?></strong></div>
            <?php endif; ?>

            <?php Material_List_Ui::filters($result, $list_url, $choices, true); ?>
            <?php if ($error !== '') : ?>
                <div class="ojp-notice ojp-notice--danger" role="alert"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <?php if (array() === $materials) : ?>
                <?php echo Page_Response::empty_state(
                    __('표시할 학습자료가 없습니다', 'learning-materials'),
                    __('자료를 등록하거나 검색 조건을 변경해 주세요.', 'learning-materials'),
                    $new_url,
                    __('자료 올리기', 'learning-materials')
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
            <?php else : ?>
                <div class="ojp-table-wrap">
                    <table class="ojp-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('제목', 'learning-materials'); ?></th>
                            <th><?php esc_html_e('공개 범위', 'learning-materials'); ?></th>
                            <th><?php esc_html_e('크기', 'learning-materials'); ?></th>
                            <th><?php esc_html_e('올린 날', 'learning-materials'); ?></th>
                            <th><span class="ojp-visually-hidden"><?php esc_html_e('작업', 'learning-materials'); ?></span></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($materials as $material) : ?>
                            <?php $group_ids = $group_map[$material->id] ?? array(); ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url(home_url('/oj-addons/learning-materials/' . $material->id)); ?>"><?php echo esc_html($material->title); ?></a>
                                    <span class="ojp-status-badge ojp-status-badge--neutral"><?php echo esc_html(strtoupper($material->extension)); ?></span>
                                    <?php Material_List_Ui::badges($material, true); ?>
                                </td>
                                <td>
                                    <?php if (array() === $group_ids) : ?>
                                        <span class="ojp-status-badge ojp-status-badge--success"><?php esc_html_e('전체 공개', 'learning-materials'); ?></span>
                                    <?php else : ?>
                                        <?php echo esc_html(implode(', ', array_map(
                                            static fn (string $id): string => $choices[$id] ?? $id,
                                            $group_ids
                                        ))); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($material->formatted_size()); ?></td>
                                <td><?php echo esc_html($material->created_at); ?></td>
                                <td>
                                    <div class="lmt-row-actions">
                                        <a class="ojp-button ojp-button--small" href="<?php echo esc_url(home_url('/oj-console/addons/learning-materials/' . $material->id . '/edit')); ?>">
                                            <?php esc_html_e('편집', 'learning-materials'); ?>
                                        </a>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <input type="hidden" name="action" value="learning_materials_delete">
                                            <input type="hidden" name="material_id" value="<?php echo (int) $material->id; ?>">
                                            <?php wp_nonce_field('learning_materials_delete'); ?>
                                            <button type="submit" class="ojp-button ojp-button--small ojp-button--danger"
                                                data-ojp-confirm="<?php echo esc_attr(sprintf(
                                                    /* translators: %s: material title */
                                                    $material->is_google_link()
                                                        ? __('"%s" 링크를 삭제할까요? Google 원본은 유지됩니다.', 'learning-materials')
                                                        : __('"%s"을(를) 삭제할까요? 파일까지 지워지며 되돌릴 수 없습니다.', 'learning-materials'),
                                                    $material->title
                                                )); ?>"
                                                data-ojp-confirm-label="<?php esc_attr_e('삭제', 'learning-materials'); ?>">
                                                <?php esc_html_e('삭제', 'learning-materials'); ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php Material_List_Ui::pagination($result, $list_url); ?>
        </div>
        <?php

        return Page_Response::make(__('학습자료', 'learning-materials'), (string) ob_get_clean());
    }
}
