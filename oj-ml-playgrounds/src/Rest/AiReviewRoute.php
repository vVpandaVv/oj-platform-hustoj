<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Rest;

use OJML\Playgrounds\Application\AiReviewService;
use OJML\Playgrounds\Domain\Problem;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class AiReviewRoute
{
    public function __construct(private readonly AiReviewService $service, private readonly Presenter $presenter)
    {
    }

    public function register(): void
    {
        register_rest_route(Routes::NAMESPACE, '/console/attempts/(?P<uuid>[0-9A-Fa-f-]{36})/ai-review', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle'],
            'permission_callback' => [Permissions::class, 'reviewMutation'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $correlation = wp_generate_uuid4();
        try {
            $input = $request->get_json_params();
            if (!is_array($input)) {
                throw new Problem('OJ_ML_AI_REQUEST_INVALID', '올바른 JSON 요청을 보내세요.', 400);
            }
            $data = $this->service->request((string) $request['uuid'], get_current_user_id(), $input, $correlation);
            if (isset($data['review'])) {
                $data = ['review' => $this->presenter->review($data['review'], true)];
            }
            $response = new WP_REST_Response(['data' => $data], 200);
            $response->header('Cache-Control', 'no-store, private');
            $response->header('Vary', 'Cookie');
            $response->header('X-Content-Type-Options', 'nosniff');
            $response->header('X-Correlation-ID', $correlation);
            return $response;
        } catch (Problem $problem) {
            return $problem->toWpError($correlation);
        } catch (Throwable) {
            // Do not log portfolio answers, provider responses, or private paths.
            return (new Problem('OJ_ML_AI_INTERNAL_ERROR', 'AI 평가 연결을 처리하지 못했습니다. 수동 평가는 계속할 수 있습니다.', 500))->toWpError($correlation);
        }
    }
}
