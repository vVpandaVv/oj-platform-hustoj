<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Rest;

use OJML\Playgrounds\Application\ReconsiderationService;
use OJML\Playgrounds\Domain\Problem;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ReconsiderationRoute
{
    public function __construct(
        private readonly ReconsiderationService $service,
        private readonly Presenter $presenter
    ) {
    }

    public function register(): void
    {
        register_rest_route(Routes::NAMESPACE, '/console/attempts/(?P<uuid>[0-9A-Fa-f-]{36})/reconsider', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'reconsider'],
            'permission_callback' => [Permissions::class, 'manageMutation'],
        ]);
    }

    public function reconsider(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $correlationId = $this->correlationId();
        try {
            $input = $request->get_json_params();
            if (!is_array($input)) {
                throw new Problem('OJ_ML_RECONSIDERATION_INPUT_INVALID', 'JSON 형식의 재검토 사유와 현재 검사 식별자가 필요합니다.', 400);
            }
            $result = $this->service->reconsider(
                (string) $request['uuid'], get_current_user_id(), $input, $correlationId
            );
            $response = new WP_REST_Response([
                'data' => [
                    'attempt' => $this->presenter->attempt($result['attempt'], true),
                    'created' => $result['created'],
                ],
            ], $result['httpStatus']);
            $response->header('Cache-Control', 'no-store, private');
            $response->header('Vary', 'Cookie');
            $response->header('X-Content-Type-Options', 'nosniff');
            $response->header('X-Correlation-ID', $correlationId);
            return $response;
        } catch (Problem $problem) {
            return $problem->toWpError($correlationId);
        } catch (Throwable $throwable) {
            try {
                do_action('oj_ml_playgrounds_rest_exception', $throwable, $correlationId);
            } catch (Throwable) {
                // Diagnostic listeners must not turn this response into a fatal error.
            }
            return (new Problem('OJ_ML_INTERNAL_ERROR', '재검토 요청을 처리하는 중 서버 오류가 발생했습니다.', 500))
                ->toWpError($correlationId);
        }
    }

    private function correlationId(): string
    {
        $candidate = isset($_SERVER['HTTP_X_REQUEST_ID']) && is_string($_SERVER['HTTP_X_REQUEST_ID'])
            ? trim($_SERVER['HTTP_X_REQUEST_ID'])
            : '';
        return preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $candidate) === 1 ? $candidate : wp_generate_uuid4();
    }
}
