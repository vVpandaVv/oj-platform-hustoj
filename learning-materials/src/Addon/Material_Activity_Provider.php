<?php
declare(strict_types=1);

namespace LearningMaterials\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityProviderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityV1;
use LearningMaterials\Materials\Material_Access;
use LearningMaterials\Materials\Material_Catalog;
use LearningMaterials\Materials\Material_Repository;
use LearningMaterials\Support\Capabilities;
use Throwable;

final class Material_Activity_Provider implements AddonActivityProviderV1 {
    public function search(AddonActivityQueryV1 $query): AddonActivityPageV1 {
        // The catalog must not advertise files that the protected stream denies.
        if ($query->actorUserId() !== get_current_user_id()
            || (! Capabilities::can_view() && ! Capabilities::can_manage())) {
            return new AddonActivityPageV1(true, array(), null);
        }
        try {
            $repository = new Material_Repository();
            $access = new Material_Access();
            $group_map = $repository->group_ids_map();
            $page = Material_Catalog::page(
                $repository->all(),
                $group_map,
                array('q' => $query->search()),
                static fn (array $group_ids): bool => $access->can_view($query->actorUserId(), $group_ids),
                false,
                null,
                $query->limit()
            );
            $items = array_map(
                static fn ($material): AddonActivityV1 => new AddonActivityV1(
                    'learning-materials:' . $material->id,
                    $material->title,
                    $material->description,
                    add_query_arg(
                        array('action' => 'learning_materials_view', 'material_id' => $material->id),
                        admin_url('admin-post.php')
                    ),
                    'material',
                    __('학습자료', 'learning-materials'),
                    $material->content_type()
                ),
                $page['items']
            );

            return new AddonActivityPageV1(true, $items, null);
        } catch (Throwable) {
            // A provider failure is isolated by the platform activity catalog.
            return new AddonActivityPageV1(true, array(), null);
        }
    }
}
