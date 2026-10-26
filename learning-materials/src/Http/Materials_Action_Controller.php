<?php
declare(strict_types=1);

namespace LearningMaterials\Http;

use DomainException;
use LearningMaterials\Materials\Material_Access;
use LearningMaterials\Materials\Material_Repository;
use LearningMaterials\Materials\Material_Storage;
use LearningMaterials\Materials\Preview_Link;
use LearningMaterials\Materials\Google_Link;
use LearningMaterials\Materials\Material_Settings;
use LearningMaterials\Support\Capabilities;
use Throwable;

/**
 * Everything AddonPageHandlerV1 can't do (form submission, file upload,
 * binary streaming) lives here as plain WordPress admin-post.php actions —
 * AddonPageRequestV1/AddonPageResponseV1 are GET/HTML only, see
 * Oj_Platform_Registration's class doc comment.
 */
final class Materials_Action_Controller {
    private const CONSOLE_INDEX_URL_PATH = '/oj-console/addons/learning-materials';

    public static function register(): void {
        add_action('admin_post_learning_materials_save', array(__CLASS__, 'save'));
        add_action('admin_post_learning_materials_delete', array(__CLASS__, 'delete'));
        add_action('admin_post_learning_materials_view', array(__CLASS__, 'view'));
        add_action('admin_post_learning_materials_download', array(__CLASS__, 'download'));
        add_action('admin_post_learning_materials_preview_link', array(__CLASS__, 'preview_link'));
        add_action('admin_post_learning_materials_preview_file', array(__CLASS__, 'preview_file'));
        add_action('admin_post_nopriv_learning_materials_preview_file', array(__CLASS__, 'preview_file'));
    }

    public static function save(): void {
        check_admin_referer('learning_materials_save');
        self::require_manage();

        $material_id = isset($_POST['material_id']) && is_scalar($_POST['material_id']) ? (int) $_POST['material_id'] : 0;
        $title = isset($_POST['title']) && is_string($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $description = isset($_POST['description']) && is_string($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $file = self::uploaded_file('file');
        $source_url = isset($_POST['source_url']) && is_string($_POST['source_url']) ? trim(wp_unslash($_POST['source_url'])) : '';
        $retry_url = $material_id > 0
            ? home_url(self::CONSOLE_INDEX_URL_PATH . '/' . $material_id . '/edit')
            : home_url(self::CONSOLE_INDEX_URL_PATH . '/new');

        try {
            if ('' === trim($title)) {
                throw new DomainException(__('학습자료 제목을 입력해 주세요.', 'learning-materials'));
            }
            if (preg_match_all('/./us', $title) > 200 || preg_match_all('/./us', $description) > 4000) {
                throw new DomainException(__('제목은 200자, 설명은 4000자 이내로 입력해 주세요.', 'learning-materials'));
            }
            $group_ids = self::sanitized_group_ids();

            $repository = new Material_Repository();
            $storage = new Material_Storage();
            if (null !== $file && $source_url !== '') {
                throw new DomainException(__('파일과 Google 링크 중 하나만 입력해 주세요.', 'learning-materials'));
            }
            $linked = null;
            if ($source_url !== '') {
                $google = Google_Link::parse($source_url);
                $linked = array('storage_filename' => '', 'original_filename' => 'Google 공유 링크',
                    'extension' => 'google', 'mime_type' => 'text/html', 'file_size' => 0,
                    'source_url' => $google['source_url']);
            }

            if ($material_id > 0) {
                $existing = $repository->find($material_id);
                if (null === $existing) {
                    throw new DomainException(__('학습자료를 찾을 수 없습니다.', 'learning-materials'));
                }
                if ($existing->is_google_link() && $linked === null && $file === null) {
                    throw new DomainException(__('파일 또는 Google 공유 링크를 입력해 주세요.', 'learning-materials'));
                }
                $settings = Material_Settings::from_input(array_map(static fn ($v) => is_string($v) ? wp_unslash($v) : $v, $_POST), wp_timezone(), $existing);
                $stored = $linked ?? (null !== $file ? $storage->store($file) : null);
                try {
                    $repository->update($material_id, $title, $description, $stored, $group_ids, $settings);
                } catch (Throwable $error) {
                    if (null !== $stored && $stored['storage_filename'] !== '') $storage->delete($stored['storage_filename']);
                    throw $error;
                }
                if (null !== $stored && ! $existing->is_google_link()) {
                    $storage->delete($existing->storage_filename);
                }
            } else {
                if (null === $file && null === $linked) {
                    throw new DomainException(__('파일 또는 Google 공유 링크를 입력해 주세요.', 'learning-materials'));
                }
                $settings = Material_Settings::from_input(array_map(static fn ($v) => is_string($v) ? wp_unslash($v) : $v, $_POST), wp_timezone());
                $stored = $linked ?? $storage->store($file);
                try {
                    $repository->create($title, $description, $stored, get_current_user_id(), $group_ids, $settings);
                } catch (Throwable $error) {
                    if ($stored['storage_filename'] !== '') $storage->delete($stored['storage_filename']);
                    throw $error;
                }
            }
        } catch (Throwable $error) {
            wp_safe_redirect(add_query_arg('lmt_error', $error->getMessage(), $retry_url));
            exit;
        }

        wp_safe_redirect(add_query_arg('lmt_notice', $material_id > 0 ? 'saved' : 'created', home_url(self::CONSOLE_INDEX_URL_PATH)));
        exit;
    }

    public static function delete(): void {
        check_admin_referer('learning_materials_delete');
        self::require_manage();

        $material_id = isset($_POST['material_id']) && is_scalar($_POST['material_id']) ? (int) $_POST['material_id'] : 0;
        $repository = new Material_Repository();
        $material = $repository->find($material_id);
        if (null !== $material) {
            try {
                $repository->delete($material_id);
                if (! $material->is_google_link()) (new Material_Storage())->delete($material->storage_filename);
            } catch (Throwable $error) {
                wp_safe_redirect(add_query_arg('lmt_error', $error->getMessage(), home_url(self::CONSOLE_INDEX_URL_PATH)));
                exit;
            }
        }

        wp_safe_redirect(add_query_arg('lmt_notice', 'deleted', home_url(self::CONSOLE_INDEX_URL_PATH)));
        exit;
    }

    public static function view(): void {
        self::stream(true);
    }

    public static function download(): void {
        self::stream(false);
    }

    public static function preview_link(): void {
        nocache_headers();
        if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || ! check_ajax_referer('learning_materials_preview', 'nonce', false)) {
            wp_send_json_error(array('message' => __('미리보기 요청을 확인할 수 없습니다.', 'learning-materials')), 403);
            return;
        }
        $id = isset($_POST['material_id']) && is_scalar($_POST['material_id']) ? (int) $_POST['material_id'] : 0;
        $repository = new Material_Repository();
        $material = $repository->find($id);
        $groups = null !== $material ? $repository->group_ids_for($id) : array();
        if (null === $material || ! is_user_logged_in() || (! Capabilities::can_view() && ! Capabilities::can_manage()) || ! (new Material_Access())->can_view(get_current_user_id(), $groups)
            || (! $material->is_available() && ! Capabilities::can_manage()) || ! $material->uses_google_viewer()) {
            wp_send_json_error(array('message' => __('미리보기를 사용할 수 없습니다.', 'learning-materials')), 404);
            return;
        }
        try {
            $data = Preview_Link::data($material, $groups, Capabilities::can_manage());
        } catch (DomainException $error) {
            wp_send_json_error(array('message' => $error->getMessage()), 400);
            return;
        }
        wp_send_json_success($data);
    }

    public static function preview_file(): void {
        self::stream(true, true);
    }

    private static function stream(bool $inline, bool $signed = false): void {
        if (! in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', array('GET', 'HEAD'), true)) {
            self::not_found();
        }
        $material_id = isset($_GET['material_id']) && is_scalar($_GET['material_id']) ? (int) $_GET['material_id'] : 0;
        $repository = new Material_Repository();
        $material = $repository->find($material_id);
        $group_ids = null !== $material ? $repository->group_ids_for($material_id) : array();

        $expires = isset($_GET['expires']) && is_scalar($_GET['expires']) ? (int) $_GET['expires'] : 0;
        $signature = isset($_GET['signature']) && is_string($_GET['signature']) ? $_GET['signature'] : '';
        $manager_preview = ($_GET['manager_preview'] ?? '') === '1';
        $allowed = null !== $material && ! $material->is_google_link() && ($signed
            ? ($material->uses_google_viewer()
                && ($manager_preview || $material->is_available())
                && Preview_Link::valid($material, $group_ids, $expires, $signature, wp_salt('auth'), time(), $manager_preview))
            : (is_user_logged_in() && (Capabilities::can_view() || Capabilities::can_manage()) && (new Material_Access())->can_view(get_current_user_id(), $group_ids)
                && ($material->is_available() || Capabilities::can_manage())
                && (! $inline || $material->is_viewable_inline())));
        if (! $allowed) {
            self::not_found();
        }

        try {
            $handle = (new Material_Storage())->open($material->storage_filename);
            $stat = fstat($handle);
            $size = (int) ($stat['size'] ?? 0);
            if ($stat === false || $size !== $material->file_size) {
                fclose($handle);
                self::not_found();
            }
        } catch (Throwable $error) {
            self::not_found();
            return;
        }

        $fallback = 'material-' . $material->id . '.' . $material->extension;
        $encoded = rawurlencode(str_replace(array("\r", "\n"), '', $material->original_filename));

        nocache_headers();
        $mime = ($inline || $signed) ? $material->content_type() : $material->mime_type;
        header('Content-Type: ' . $mime);
        header(
            'Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . $encoded
        );
        $start = 0;
        $end = $size - 1;
        header('Accept-Ranges: bytes');
        if (isset($_SERVER['HTTP_RANGE']) && 'HEAD' !== ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
            $range = $_SERVER['HTTP_RANGE'];
            if (! is_string($range) || preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $matches) !== 1
                || ($matches[1] === '' && $matches[2] === '')) {
                status_header(416);
                header('Content-Range: bytes */' . $size);
                fclose($handle);
                exit;
            }
            $start = $matches[1] === '' ? max(0, $size - (int) $matches[2]) : (int) $matches[1];
            $end = $matches[1] === '' || $matches[2] === '' ? $size - 1 : min($size - 1, (int) $matches[2]);
            if ($start > $end || $start >= $size) {
                status_header(416);
                header('Content-Range: bytes */' . $size);
                fclose($handle);
                exit;
            }
            status_header(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        $remaining = max(0, $end - $start + 1);
        header('Content-Length: ' . $remaining);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header("Content-Security-Policy: default-src 'none'");
        header('Referrer-Policy: no-referrer');
        if ('HEAD' === ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
            fclose($handle);
            exit;
        }
        fseek($handle, $start);
        while ($remaining > 0 && ! feof($handle)) {
            $chunk = fread($handle, min(65536, $remaining));
            if ($chunk === false || $chunk === '') break;
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw file bytes
            $remaining -= strlen($chunk);
        }
        fclose($handle);
        exit;
    }

    private static function not_found(): void {
        status_header(404);
        nocache_headers();
        header('Content-Type: text/plain; charset=UTF-8');
        if ('HEAD' === ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
            exit;
        }
        echo esc_html__('학습자료를 찾을 수 없습니다.', 'learning-materials');
        exit;
    }

    private static function require_manage(): void {
        if (! Capabilities::can_manage()) {
            wp_die(
                esc_html__('이 작업을 수행할 권한이 없습니다.', 'learning-materials'),
                esc_html__('권한 없음', 'learning-materials'),
                array('response' => 403)
            );
        }
    }

    /** @return list<string> */
    private static function sanitized_group_ids(): array {
        if (! isset($_POST['group_ids'])) {
            return array();
        }
        if (! is_array($_POST['group_ids'])) throw new DomainException(__('그룹 설정을 확인해 주세요.', 'learning-materials'));

        $ids = array();
        foreach ($_POST['group_ids'] as $value) {
            if (! is_string($value) && ! is_int($value)) throw new DomainException(__('그룹 설정을 확인해 주세요.', 'learning-materials'));
            $id = sanitize_text_field(wp_unslash((string) $value));
            if ($id !== '') $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    /**
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
     */
    private static function uploaded_file(string $field): ?array {
        if (! isset($_FILES[$field]) || ! is_array($_FILES[$field])) {
            return null;
        }
        $file = $_FILES[$field];
        if (UPLOAD_ERR_NO_FILE === (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            return null;
        }

        return $file;
    }
}
