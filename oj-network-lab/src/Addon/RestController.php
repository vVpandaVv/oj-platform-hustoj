<?php
declare(strict_types=1);
namespace OjNetworkLab\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use OjNetworkLab\Core\Plugin;
use OjNetworkLab\Storage\Projects;
final class RestController
{
    public static function registerRoutes(): void
    {
        foreach ([['/projects', 'GET', 'listing'], ['/projects', 'POST', 'save'], ['/projects/(?P<id>\d+)', 'GET', 'read'], ['/projects/(?P<id>\d+)', 'PUT', 'save'], ['/projects/(?P<id>\d+)', 'DELETE', 'delete']] as [$path, $method, $handler]) {
            register_rest_route('oj-network-lab/v1', $path, ['methods' => $method, 'permission_callback' => [self::class, 'authorize'], 'callback' => static fn (\WP_REST_Request $request) => self::dispatch($handler, $request)]);
        }
    }
    public static function authorize(\WP_REST_Request $request): bool|\WP_Error
    {
        if (!is_user_logged_in()) { return new \WP_Error('ojnl_login', '로그인 후 이용해 주세요.', ['status' => 401]); }
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) { return new \WP_Error('ojnl_nonce', '로그인 상태를 확인하려면 페이지를 새로고침하세요.', ['status' => 403]); }
        if (!class_exists(AddonCapabilityKeyV1::class) || Plugin::$runtime?->snapshot()->status() !== 'accepted') { return new \WP_Error('ojnl_platform', '플랫폼의 네트워크 실습 애드온 상태를 확인하세요.', ['status' => 503]); }
        if (!current_user_can(AddonCapabilityKeyV1::wordpress(Registration::LEARN)) && !current_user_can(AddonCapabilityKeyV1::wordpress(Registration::TEACH)) && !current_user_can('manage_options')) { return new \WP_Error('ojnl_forbidden', '네트워크 실습 권한이 없습니다.', ['status' => 403]); }
        if (get_option('oj_network_lab_schema', '') !== '1') { return new \WP_Error('ojnl_storage', '저장소 준비가 필요합니다. 관리자에서 활성화 상태를 확인하세요.', ['status' => 503]); }
        if (strlen($request->get_body()) > 262144) { return new \WP_Error('ojnl_size', '요청 크기를 초과했습니다.', ['status' => 413]); }
        return true;
    }
    public static function dispatch(string $handler, \WP_REST_Request $request): mixed
    {
        $auth = self::authorize($request); if ($auth !== true) { return $auth; }
        global $wpdb; $store = new Projects($wpdb); $owner = get_current_user_id(); $id = (int) $request->get_param('id');
        try {
            if ($handler === 'listing') { $result = ['projects' => $store->list($owner)]; }
            elseif ($handler === 'read') { $result = Projects::response($store->read($owner, $id)); }
            elseif ($handler === 'save') { $input = $request->get_json_params(); if (!is_array($input)) { throw new \InvalidArgumentException('JSON 형식이 필요합니다.'); } $result = $store->save($owner, $id, $input); Plugin::audit('save', $result['id']); }
            elseif ($handler === 'delete') { $input = $request->get_json_params(); if (!is_array($input) || !is_int($input['revision'] ?? null)) { throw new \InvalidArgumentException('삭제할 버전을 확인하세요.'); } $store->delete($owner, $id, $input['revision']); Plugin::audit('delete', $id); $result = ['deleted' => true]; }
            else { throw new \InvalidArgumentException('지원하지 않는 요청입니다.'); }
            $response = rest_ensure_response($result); $response->header('Cache-Control', 'private, no-store'); return $response;
        } catch (\InvalidArgumentException $error) { return new \WP_Error('ojnl_input', $error->getMessage(), ['status' => 400]); }
        catch (\OutOfBoundsException $error) { return new \WP_Error('ojnl_missing', $error->getMessage(), ['status' => 404]); }
        catch (\RuntimeException $error) { $status = in_array($error->getCode(), [409, 429], true) ? $error->getCode() : 503; return new \WP_Error('ojnl_save', $status === 503 ? '저장소에 연결하지 못했습니다. 로컬 초안 또는 파일 내보내기를 이용하세요.' : $error->getMessage(), ['status' => $status]); }
        catch (\Throwable) { return new \WP_Error('ojnl_unavailable', '요청을 처리하지 못했습니다. 로컬 초안을 보관하고 다시 시도하세요.', ['status' => 503]); }
    }
}
