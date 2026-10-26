<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Rest;

use OJML\Playgrounds\Domain\PortfolioTemplate;
use OJML\Playgrounds\Domain\PortfolioRequirements;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Ops\Lease;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Personal reusable copies. Assignment snapshots never depend on mutable library entries. */
final class TemplateLibraryRoute
{
    public function __construct(private readonly \OJML\Playgrounds\Database\Repository $repository) {}
    public static function key(int $actor): string { return 'oj_ml_template_library_' . $actor; }

    public function register(): void
    {
        register_rest_route(Routes::NAMESPACE, '/console/template-library', [
            ['methods' => 'GET', 'callback' => [$this, 'handle'], 'permission_callback' => [Permissions::class, 'manageRead']],
            ['methods' => 'POST', 'callback' => [$this, 'handle'], 'permission_callback' => [Permissions::class, 'manageMutation']],
        ]);
        register_rest_route(Routes::NAMESPACE, '/console/template-library/(?P<uuid>[a-f0-9-]{36})', [
            'methods' => 'DELETE', 'callback' => [$this, 'handle'], 'permission_callback' => [Permissions::class, 'manageMutation'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $correlation = wp_generate_uuid4();
        try {
            $method = $request->get_method();
            $guard = $method === 'GET' ? Permissions::manageRead($request) : Permissions::manageMutation($request);
            if ($guard instanceof WP_Error) { return $guard; }
            $key = self::key(get_current_user_id());
            $lease = Lease::acquire($key);
            $items = get_option($key, []);
            if (!is_array($items)) { throw new Problem('OJ_ML_LIBRARY_UNAVAILABLE', '양식 보관함을 읽지 못했습니다.', 503); }
            if ($method === 'POST') {
                $body = $request->get_json_params();
                if (!is_array($body)) { throw new Problem('OJ_ML_LIBRARY_INVALID', '저장할 양식을 입력하세요.', 400); }
                $id = $body['id'] ?? '';
                if (!is_string($id) || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $id) !== 1) {
                    throw new Problem('OJ_ML_LIBRARY_INVALID', '저장 요청 ID가 올바르지 않습니다.', 400);
                }
                $template = PortfolioTemplate::normalize($body['template'] ?? null);
                if ($template === null) { throw new Problem('OJ_ML_LIBRARY_INVALID', '양식을 먼저 작성하세요.', 400); }
                $item = ['id' => $id, 'template' => $template, 'requirements' => PortfolioRequirements::normalize($body['requirements'] ?? [], $template)];
                $existing = array_values(array_filter($items, static fn (array $entry): bool => $entry['id'] === $id));
                if ($existing !== []) {
                    if ($existing[0] !== $item) { throw new Problem('OJ_ML_LIBRARY_CONFLICT', '같은 요청 ID로 다른 양식을 저장할 수 없습니다.', 409); }
                } else {
                    if (count($items) >= 20) { throw new Problem('OJ_ML_LIBRARY_FULL', '개인 양식은 최대 20개입니다. 사용하지 않는 양식을 삭제하세요.', 409); }
                    $items[] = $item;
                    $this->persist($key, $items, 'template_library.saved', $id, $correlation);
                }
            } elseif ($method === 'DELETE') {
                $next = array_values(array_filter($items, static fn (array $entry): bool => $entry['id'] !== $request['uuid']));
                if ($next !== $items) { $this->persist($key, $next, 'template_library.deleted', (string) $request['uuid'], $correlation); }
                $items = $next;
            }
            $response = new WP_REST_Response(['data' => ['items' => array_values($items)]], 200);
            $response->header('Cache-Control', 'no-store, private');
            $response->header('X-Correlation-ID', $correlation);
            return $response;
        } catch (Problem $problem) { return $problem->toWpError($correlation); }
        catch (Throwable) { return (new Problem('OJ_ML_LIBRARY_UNAVAILABLE', '양식 보관함 요청을 처리하지 못했습니다.', 503))->toWpError($correlation); }
    }

    private function persist(string $key, array $items, string $event, string $id, string $correlation): void
    {
        global $wpdb;
        $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->options));
        if (strtoupper((string) $engine) !== 'INNODB') {
            throw new Problem('OJ_ML_LIBRARY_ENGINE_UNSUPPORTED', '감사 기록과 양식을 함께 저장하려면 WordPress options 테이블의 InnoDB 구성이 필요합니다.', 503);
        }
        if ($wpdb->query('START TRANSACTION') === false) { throw new Problem('OJ_ML_LIBRARY_SAVE_FAILED', '저장 트랜잭션을 시작하지 못했습니다.', 503); }
        try {
            if (!update_option($key, $items, false)) { throw new Problem('OJ_ML_LIBRARY_SAVE_FAILED', '양식 보관함을 저장하지 못했습니다.', 503); }
            $this->repository->insertEvent(null, null, get_current_user_id(), $event, ['templateId' => $id, 'count' => count($items)], $correlation);
            if ($wpdb->query('COMMIT') === false) { throw new Problem('OJ_ML_LIBRARY_SAVE_FAILED', '양식 저장을 확인하지 못했습니다.', 503); }
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            wp_cache_delete($key, 'options'); wp_cache_delete('notoptions', 'options');
            throw $error;
        }
    }
}
