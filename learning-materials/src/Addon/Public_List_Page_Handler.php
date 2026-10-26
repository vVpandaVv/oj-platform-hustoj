<?php
declare(strict_types=1);

namespace LearningMaterials\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use LearningMaterials\Materials\Material_Access;
use LearningMaterials\Materials\Material_Repository;
use LearningMaterials\Materials\Material_Catalog;

final class Public_List_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        nocache_headers();
        $repository = new Material_Repository();
        $access = new Material_Access();
        $viewer_user_id = $request->actorUserId();

        $result = Material_Catalog::page($repository->all(), $repository->group_ids_map(), $request->query(),
            static fn (array $ids): bool => $access->can_view($viewer_user_id, $ids));
        $visible = $result['items'];
        $group_labels = array();
        foreach ($result['groups'] as $id) $group_labels[$id] = $access->scope_label($viewer_user_id, array($id)) ?: $id;
        $list_url = home_url('/oj-addons/learning-materials');

        ob_start();
        ?>
        <div class="lmt-public">
            <header class="ojp-page-header">
                <div>
                    <p class="ojp-eyebrow"><?php esc_html_e('수업', 'learning-materials'); ?></p>
                    <h1><?php esc_html_e('학습자료', 'learning-materials'); ?></h1>
                    <p><?php esc_html_e('공유된 학습자료입니다.', 'learning-materials'); ?></p>
                </div>
            </header>

            <?php Material_List_Ui::filters($result, $list_url, $group_labels); ?>

            <?php if (array() === $visible) : ?>
                <?php echo Page_Response::empty_state(
                    __('표시할 학습자료가 없습니다', 'learning-materials'),
                    __('검색 조건을 바꾸거나 나중에 다시 확인해 주세요.', 'learning-materials'),
                    $list_url,
                    __('필터 초기화', 'learning-materials')
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
            <?php else : ?>
                <div class="lmt-card-grid">
                    <?php foreach ($visible as $material) : ?>
                        <a class="lmt-card" href="<?php echo esc_url(home_url('/oj-addons/learning-materials/' . $material->id)); ?>">
                            <span class="ojp-status-badge ojp-status-badge--neutral"><?php echo esc_html(strtoupper($material->extension)); ?></span>
                            <h3><?php echo esc_html($material->title); ?></h3>
                            <?php Material_List_Ui::badges($material); ?>
                            <p><?php echo esc_html($material->formatted_size()); ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php Material_List_Ui::pagination($result, $list_url); ?>
        </div>
        <?php

        return Page_Response::make(__('학습자료', 'learning-materials'), (string) ob_get_clean());
    }
}
