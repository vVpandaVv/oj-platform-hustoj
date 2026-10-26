<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Rest;

use OJML\Playgrounds\Application\TeachingDatasetService;
use OJML\Playgrounds\Domain\Problem;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class TeachingDatasetRoute
{
    private ?WP_REST_Response $binaryResponse = null;
    private string $binaryContent = '';

    public function __construct(private readonly TeachingDatasetService $service) {}

    public function register(): void
    {
        register_rest_route(Routes::NAMESPACE, '/console/datasets', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'listing'], 'permission_callback' => [Permissions::class, 'manageRead']],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'upload'], 'permission_callback' => [Permissions::class, 'manageMutation']],
        ]);
        register_rest_route(Routes::NAMESPACE, '/console/datasets/(?P<id>[a-f0-9]{32})', [
            'methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'hide'], 'permission_callback' => [Permissions::class, 'manageMutation'],
        ]);
        register_rest_route(Routes::NAMESPACE, '/assignments/(?P<id>\d+)/dataset', [
            'methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'download'], 'permission_callback' => [Permissions::class, 'learnerRead'],
        ]);
        add_filter('rest_pre_serve_request', [$this, 'serveBinary'], 10, 4);
    }

    public function listing(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->json(fn (): array => $this->service->listForTeacher(get_current_user_id()));
    }

    public function upload(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->json(function (string $correlationId) use ($request): array {
            $files = $request->get_file_params();
            if (!is_array($files['dataset'] ?? null) || count($files) !== 1) { throw new Problem('OJ_ML_DATASET_UPLOAD_INVALID', 'CSV 파일 한 개를 dataset 항목으로 올려 주세요.', 400); }
            return ['dataset' => $this->service->upload(get_current_user_id(), $files['dataset'], $correlationId)];
        }, 201);
    }

    public function hide(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->json(fn (string $correlationId): array => $this->service->hide(get_current_user_id(), (string) $request['id'], $correlationId));
    }

    public function download(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $correlationId = wp_generate_uuid4();
        try {
            foreach (['_envelope', '_jsonp', '_embed'] as $parameter) {
                if ($request->get_param($parameter) !== null) { throw new Problem('OJ_ML_DATASET_DOWNLOAD_INVALID', 'CSV 다운로드에는 응답 변환 옵션을 사용할 수 없습니다.', 400); }
            }
            $file = $this->service->downloadForLearner((int) $request['id'], get_current_user_id());
            // Deliberately keep bytes out of JSON/batch responses; only the exact authorized
            // response instance is sent by serveBinary during the outer HTTP response.
            $response = $this->headers(new WP_REST_Response(null, 200), $correlationId);
            $response->header('Content-Type', 'text/csv; charset=utf-8');
            $response->header('Content-Disposition', 'attachment; filename="dataset.csv"; filename*=UTF-8\'\'' . rawurlencode($file['name']));
            $response->header('Content-Length', (string) $file['bytes']);
            $response->header('Content-Security-Policy', "default-src 'none'; sandbox");
            $response->header('X-Dataset-SHA256', $file['sha256']);
            $this->binaryResponse = $response; $this->binaryContent = $file['content'];
            return $response;
        } catch (Problem $problem) { return $problem->toWpError($correlationId); }
        catch (Throwable $error) { return $this->failure($error, $correlationId); }
    }

    public function serveBinary(bool $served, mixed $response, WP_REST_Request $request, mixed $server): bool
    {
        if ($served || $this->binaryResponse === null || $response !== $this->binaryResponse) { return $served; }
        $content = $this->binaryContent; $this->binaryContent = ''; $this->binaryResponse = null;
        if ($request->get_method() !== 'HEAD') { echo $content; }
        return true;
    }

    private function json(callable $operation, int $status = 200): WP_REST_Response|WP_Error
    {
        $correlationId = wp_generate_uuid4();
        try { return $this->headers(new WP_REST_Response(['data' => $operation($correlationId)], $status), $correlationId); }
        catch (Problem $problem) { return $problem->toWpError($correlationId); }
        catch (Throwable $error) { return $this->failure($error, $correlationId); }
    }

    private function headers(WP_REST_Response $response, string $correlationId): WP_REST_Response
    {
        $response->header('Cache-Control', 'no-store, private');
        $response->header('Vary', 'Cookie');
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('X-Correlation-ID', $correlationId);
        return $response;
    }

    private function failure(Throwable $error, string $correlationId): WP_Error
    {
        try { do_action('oj_ml_playgrounds_rest_exception', $error, $correlationId); } catch (Throwable) {}
        return (new Problem('OJ_ML_INTERNAL_ERROR', '수업 자료 요청을 처리하지 못했습니다.', 500))->toWpError($correlationId);
    }
}
