<?php
declare(strict_types=1);

namespace TeachingTools\Rest\Controllers;

use TeachingTools\Addon\Whiteboard\Whiteboard_Image_Repository;
use TeachingTools\Sessions\Session_Code_Guard;
use TeachingTools\Sessions\Tool_Session_Repository;
use TeachingTools\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class Whiteboard_Controller {
    private const CODE_PATTERN = '(?P<code>[A-Za-z0-9]{6})';

    public static function register_routes(): void {
        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/whiteboard-image',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'upload_image'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            )
        );
    }

    public static function register_download(): void {
        add_action('admin_post_teaching_tools_whiteboard_image', array(__CLASS__, 'serve_image'));
    }

    public static function upload_image(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $guard = Session_Code_Guard::check($user_id, (string) $request['code']);
        if (true !== $guard) {
            return $guard;
        }

        $code = (string) $request['code'];
        $session = Tool_Session_Repository::find_active_by_code($code);
        $host_role = null === $session ? null : Tool_Session_Repository::host_role((int) $session['id'], $user_id);

        if (null === $session || 'whiteboard' !== (string) $session['tool_type'] || ! in_array($host_role, array('owner', 'cohost'), true)) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return new WP_Error(
                'teaching_tools_session_not_found',
                __('참여 코드를 확인해 주세요.', 'teaching-tools'),
                array('status' => 404)
            );
        }

        $files = $request->get_file_params();
        $file = is_array($files) && isset($files['image']) && is_array($files['image']) ? $files['image'] : null;

        if (null === $file || UPLOAD_ERR_OK !== (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            return new WP_Error(
                'teaching_tools_whiteboard_upload_failed',
                __('이미지를 업로드하지 못했습니다.', 'teaching-tools'),
                array('status' => 400)
            );
        }

        $background = 'background' === (string) $request->get_param('purpose');
        $state = json_decode((string) ($session['state'] ?? '{}'), true);
        if ($background && ('session' !== ($state['materialSharing'] ?? '') || 'live' !== ($session['mode'] ?? ''))) {
            return new WP_Error('teaching_tools_background_sharing_disabled', __('이 수업의 배경 공유가 꺼져 있습니다.', 'teaching-tools'), array('status' => 403));
        }
        if ($background && ! function_exists('openssl_encrypt')) {
            return new WP_Error('teaching_tools_background_crypto_unavailable', __('배경 공유를 위해 서버 PHP의 OpenSSL 확장을 활성화해 주세요.', 'teaching-tools'), array('status' => 503));
        }
        $saved = Whiteboard_Image_Repository::save_upload((int) $session['id'], $file, $background);
        if (null === $saved) {
            return new WP_Error(
                'teaching_tools_whiteboard_upload_invalid',
                __('5MB 이하 PNG/JPEG/GIF/WEBP 이미지만 사용할 수 있습니다. 수업당 삽입 이미지 30개·공유 배경 500개 한도도 확인하세요.', 'teaching-tools'),
                array('status' => 400)
            );
        }

        $url = add_query_arg(
            array(
                'action' => 'teaching_tools_whiteboard_image',
                'code' => strtoupper($code),
                'file' => $saved['filename'],
            ),
            admin_url('admin-post.php')
        );

        return rest_ensure_response(array(
            'url' => esc_url_raw($url),
            'width' => $saved['width'],
            'height' => $saved['height'],
        ));
    }

    public static function serve_image(): void {
        $user_id = get_current_user_id();
        $code = strtoupper(sanitize_text_field((string) ($_GET['code'] ?? '')));
        $filename = sanitize_file_name((string) ($_GET['file'] ?? ''));
        $session = Tool_Session_Repository::find_active_by_code($code);
        $authorized = null !== $session
            && 'whiteboard' === (string) $session['tool_type']
            && (
                null !== Tool_Session_Repository::host_role((int) $session['id'], $user_id)
                || Tool_Session_Repository::is_participant((int) $session['id'], $user_id)
            );
        if ($authorized && str_starts_with($filename, 'bg-')) {
            $state = json_decode((string) ($session['state'] ?? '{}'), true);
            $authorized = 'session' === ($state['materialSharing'] ?? '') && 'live' === ($session['mode'] ?? '');
        }
        $image = $authorized
            ? Whiteboard_Image_Repository::find((int) $session['id'], $filename)
            : null;
        if (null === $image) {
            status_header(404);
            nocache_headers();
            exit;
        }

        nocache_headers();
        header('Content-Type: ' . $image['mime']);
        header('Content-Length: ' . (string) (isset($image['body']) ? strlen($image['body']) : filesize($image['path'])));
        header('Content-Disposition: inline; filename="whiteboard-image.' . pathinfo($filename, PATHINFO_EXTENSION) . '"');
        header('X-Content-Type-Options: nosniff');
        if (isset($image['body'])) { echo $image['body']; }
        else { readfile($image['path']); }
        exit;
    }
}
