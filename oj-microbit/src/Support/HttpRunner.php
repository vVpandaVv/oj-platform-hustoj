<?php
declare(strict_types=1);
namespace OjMicrobit\Support;

use OjMicrobit\Application\Runner;
use OjMicrobit\Domain\RunnerResultSchema;
use Throwable;

final class HttpRunner implements Runner
{
    public function status(): array
    {
        try {
            $data = $this->request('/health', null, 5);
            $this->compatible($data);
            if (($data['ready'] ?? $data['ok'] ?? false) !== true) { throw $this->failure('unavailable', 503, 5); }
            return ['ready' => true, 'runtimeVersion' => RunnerResultSchema::RUNTIME_VERSION, 'requestId' => RequestContext::id(), 'message' => '컴파일러와 시뮬레이터가 준비되었습니다.'];
        } catch (OperationError $error) {
            return ['ready' => false, 'code' => $error->errorCode, 'message' => $error->getMessage()] + $error->data();
        } catch (Throwable) {
            $error = $this->failure('unavailable', 503, 5);
            return ['ready' => false, 'code' => $error->errorCode, 'message' => $error->getMessage()] + $error->data();
        }
    }
    public function run(array $project, array $exercise): array
    {
        $data = $this->request('/v1/run', ['project' => $project, 'exercise' => $exercise], 60);
        $this->compatible($data);
        try { return RunnerResultSchema::normalize($data, $project, $exercise); }
        catch (Throwable) { throw $this->failure('invalid_response', 502); }
    }
    private function compatible(array $data): void
    {
        if (($data['runtimeVersion'] ?? null) !== RunnerResultSchema::RUNTIME_VERSION) { throw $this->failure('version_mismatch', 502); }
        if (($data['sandbox'] ?? null) !== 'docker-no-network') { throw $this->failure('isolation_mismatch', 502); }
    }
    private function request(string $path, ?array $body, int $timeout): array
    {
        if (!Settings::configured()) { throw $this->failure('not_configured', 503); }
        $settings = Settings::connection();
        try { $url = Settings::validateUrl($settings['url']) . $path; }
        catch (Throwable) { throw $this->failure('not_configured', 503); }
        $arguments = ['timeout' => $timeout, 'redirection' => 0, 'limit_response_size' => 4194304,
            'headers' => ['Authorization' => 'Bearer ' . $settings['token'], 'Accept' => 'application/json', 'X-Request-ID' => RequestContext::id()]];
        $started = hrtime(true);
        try {
            if ($body !== null) {
                $arguments['headers']['Content-Type'] = 'application/json';
                $arguments['body'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $response = wp_remote_post($url, $arguments);
            } else { $response = wp_remote_get($url, $arguments); }
        } catch (Throwable) { throw $this->failure('connection_failed', 503, 5, $started); }
        if (is_wp_error($response)) {
            $transportCode = (string) $response->get_error_code();
            $message = method_exists($response, 'get_error_message') ? $response->get_error_message() : ($response->message ?? '');
            $timedOut = in_array($transportCode, ['http_request_timeout', 'operation_timed_out', 'timeout'], true)
                || is_string($message) && preg_match('/(?:cURL error 28|timed?\s*out|timeout)/i', $message) === 1;
            throw $this->failure($timedOut ? 'timeout' : 'connection_failed', $timedOut ? 504 : 503, 5, $started);
        }
        $status = wp_remote_retrieve_response_code($response);
        try { $data = json_decode(wp_remote_retrieve_body($response), true, 64, JSON_THROW_ON_ERROR); }
        catch (Throwable) { $data = null; }
        $upstreamId = is_array($data) && RequestContext::validId($data['requestId'] ?? null) ? $data['requestId'] : null;
        if ($status === 429) { throw $this->failure('busy', 429, $this->retryAfter($data, 2), $started, $upstreamId); }
        if ($status === 401 || $status === 403) { throw $this->failure('runner_auth_failed', 502, null, $started, $upstreamId); }
        if ($status === 504 || $status === 408) { throw $this->failure('timeout', 504, $this->retryAfter($data, 5), $started, $upstreamId); }
        if ($status === 503) { throw $this->failure('unavailable', 503, $this->retryAfter($data, 5), $started, $upstreamId); }
        if ($status >= 500 && $status <= 599) { throw $this->failure('unavailable', 503, 5, $started, $upstreamId); }
        if (in_array($status, [400, 413, 415, 422], true)) { throw $this->failure('invalid_input', 400, null, $started, $upstreamId); }
        if ($status !== 200 || !is_array($data)) { throw $this->failure('invalid_response', 502, null, $started, $upstreamId); }
        $resultCode = in_array($data['status'] ?? null, ['graded', 'preview', 'compile_error', 'runtime_error', 'ok'], true) ? $data['status'] : 'response_received';
        RequestContext::log($resultCode, 200, (int) ((hrtime(true) - $started) / 1000000), $upstreamId);
        return $data;
    }
    private function retryAfter(mixed $data, int $fallback): int
    {
        $value = is_array($data) ? ($data['retryAfterSeconds'] ?? null) : null;
        return is_int($value) ? max(1, min(300, $value)) : $fallback;
    }
    private function failure(string $code, int $status, ?int $retry = null, ?int $started = null, ?string $upstreamId = null): OperationError
    {
        $messages = [
            'busy' => '다른 실행을 처리하고 있습니다. 안내된 시간이 지난 뒤 다시 실행해 주세요.',
            'unavailable' => '실행 서비스가 준비되지 않았습니다. 잠시 후 다시 시도해 주세요.',
            'connection_failed' => '실행 서비스에 연결하지 못했습니다. 잠시 후 다시 시도해 주세요.',
            'timeout' => '실행 서비스 응답 시간이 초과되었습니다. 잠시 후 다시 시도해 주세요.',
            'runner_auth_failed' => '실행 서비스 인증 설정을 확인해야 합니다. 관리자에게 요청 ID를 전달해 주세요.',
            'not_configured' => '실행 서비스 주소와 인증키를 설정해 주세요.',
            'version_mismatch' => '플러그인과 실행 서비스의 버전이 맞지 않습니다. 같은 릴리스를 사용해 주세요.',
            'isolation_mismatch' => '실행 서비스의 운영용 격리 설정을 확인해야 합니다.',
            'invalid_response' => '실행 서비스 응답을 확인하지 못했습니다. 관리자에게 요청 ID를 전달해 주세요.',
            'invalid_input' => '프로젝트와 테스트 설정을 확인해 주세요. 입력 형식이 실행기와 맞지 않습니다.',
        ];
        RequestContext::log($code, $status, $started === null ? 0 : (int) ((hrtime(true) - $started) / 1000000), $upstreamId);
        return new OperationError($code, $status, $messages[$code], $retry);
    }
}
