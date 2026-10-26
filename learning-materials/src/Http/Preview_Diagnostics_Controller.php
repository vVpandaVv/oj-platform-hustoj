<?php
declare(strict_types=1);

namespace LearningMaterials\Http;

use LearningMaterials\Materials\Material_Repository;
use LearningMaterials\Materials\Preview_Diagnostics;
use LearningMaterials\Support\Capabilities;

final class Preview_Diagnostics_Controller {
    public static function register(): void {
        add_action('admin_post_learning_materials_diagnose', array(__CLASS__, 'run'));
    }

    public static function run(): void {
        nocache_headers();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ! Capabilities::can_manage()
            || ! check_ajax_referer('learning_materials_diagnostics', 'nonce', false)) {
            wp_send_json_error(array('message' => __('진단 권한 또는 요청을 확인해 주세요.', 'learning-materials')), 403);
            return;
        }
        $id = isset($_POST['material_id']) && is_scalar($_POST['material_id']) ? (int) $_POST['material_id'] : 0;
        $repository = new Material_Repository();
        $material = $repository->find($id);
        if ($material === null) {
            wp_send_json_error(array('message' => __('학습자료를 찾을 수 없습니다.', 'learning-materials')), 404);
            return;
        }
        $expiry = isset($_POST['expires_at']) && is_scalar($_POST['expires_at']) && (int) $_POST['expires_at'] > 0 ? (int) $_POST['expires_at'] : null;
        wp_send_json_success(array('checks' => Preview_Diagnostics::run($material, $repository->group_ids_for($id), $expiry), 'checked_at' => gmdate(DATE_ATOM)));
    }
}
