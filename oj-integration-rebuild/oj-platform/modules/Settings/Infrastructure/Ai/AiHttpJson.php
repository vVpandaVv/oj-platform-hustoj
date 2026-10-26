<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Ai;

use CodingPanda\OjPlatform\Modules\Settings\Application\AiUsageRecorder;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiHttpResponse;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiHttpTransport;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderException;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use JsonException;

final class AiHttpJson
{
    public function __construct(private readonly AiHttpTransport $transport, private readonly ?AiUsageRecorder $usage = null)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(AiProviderProfile $profile, string $path): array
    {
        return $this->decode($this->transport->request(
            'GET',
            $this->url($profile->baseUrl(), $path),
            $this->headers($profile),
            null,
            3,
            min(30, $profile->timeoutSeconds()),
            $profile->resolvedIps()
        ));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(
        AiProviderProfile $profile,
        string $path,
        array $body,
        bool $geminiAuthentication = false
    ): array {
        try {
            $encoded = json_encode(
                $body,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $error) {
            throw new AiProviderException(
                'invalid_request',
                'AI 요청을 만들 수 없습니다.',
                false
            );
        }
        $headers = $geminiAuthentication
            ? $this->geminiHeaders($profile)
            : $this->headers($profile);

        $this->usage?->sending($headers['X-OJ-Request-Id']);
        return $this->decode($this->transport->request(
            'POST',
            $this->url($profile->baseUrl(), $path),
            $headers,
            $encoded,
            3,
            $profile->timeoutSeconds(),
            $profile->resolvedIps()
        ), true, $path === '/api/chat' && ($body['truncate'] ?? null) === false);
    }

    /**
     * @return array<string, string>
     */
    public function geminiHeaders(AiProviderProfile $profile): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-OJ-Request-Id' => $this->requestId(),
            'x-goog-api-key' => $profile->secret(),
        ];
    }

    public function url(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Providers don't always honor a "JSON only" response format request
     * verbatim — some models still wrap the payload in a markdown code
     * fence regardless. Strip a leading/trailing fence before decoding.
     */
    public static function stripJsonFence(string $content): string
    {
        $trimmed = self::trimJsonPadding($content);
        if (! str_starts_with($trimmed, '```')) {
            return $trimmed;
        }
        $withoutOpening = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $trimmed, 1);
        $trimmed = $withoutOpening ?? $trimmed;
        $withoutClosing = preg_replace('/\s*```\s*$/', '', $trimmed, 1);
        $trimmed = $withoutClosing ?? $trimmed;

        return self::trimJsonPadding($trimmed);
    }

    private static function trimJsonPadding(string $content): string
    {
        $content = trim($content);

        return str_starts_with($content, "\xEF\xBB\xBF") ? trim(substr($content, 3)) : $content;
    }

    /**
     * @return array<string, string>
     */
    private function headers(AiProviderProfile $profile): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-OJ-Request-Id' => $this->requestId(),
        ];
        if ($profile->authenticationMethod() === 'bearer') {
            $headers['Authorization'] = 'Bearer ' . $profile->secret();
        } else {
            $headers['X-API-Key'] = $profile->secret();
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(AiHttpResponse $response, bool $recordUsage = false, bool $wholeContextRequired = false): array
    {
        $status = $response->statusCode();
        $decoded = null;
        $jsonError = false;
        $isUtf8 = preg_match('//u', $response->body()) === 1;
        if ($isUtf8) {
            try { $decoded = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR); }
            catch (JsonException) { $jsonError = true; }
        }
        if ($recordUsage) { $this->usage?->received($status, is_array($decoded) ? $decoded : []); }
        if ($status < 200 || $status >= 300) {
            $providerError = is_array($decoded) ? ($decoded['error'] ?? null) : null;
            if ($wholeContextRequired && in_array($status, [400, 413, 500], true)
                && is_string($providerError)
                && preg_match('/(?:input|prompt).{0,120}(?:exceed|too long).{0,120}context|context.{0,120}(?:length|size).{0,120}exceed/is', $providerError) === 1) {
                throw new AiProviderException(
                    'context_too_small',
                    'Ollama 문맥 한도 초과. 본문을 자르지 않고 중단했습니다. AI 작업 배정의 문맥 토큰(num_ctx)을 확인하세요.',
                    false
                );
            }
            throw $this->statusError($status);
        }
        if (! $isUtf8) {
            throw new AiProviderException(
                'invalid_response',
                'AI Provider 응답이 UTF-8 형식이 아닙니다.',
                false
            );
        }
        if ($jsonError) {
            throw new AiProviderException(
                'invalid_response',
                'AI Provider가 올바른 JSON을 반환하지 않았습니다.',
                false
            );
        }
        if (! is_array($decoded)) {
            throw new AiProviderException(
                'invalid_response',
                'AI Provider 응답 객체가 올바르지 않습니다.',
                false
            );
        }

        return $decoded;
    }

    private function statusError(int $status): AiProviderException
    {
        return match (true) {
            $status === 400 => new AiProviderException(
                'invalid_request',
                'AI Provider가 요청을 거부했습니다.',
                false
            ),
            in_array($status, [401, 403], true) => new AiProviderException(
                'authentication_failed',
                'AI Provider 인증에 실패했습니다.',
                false
            ),
            $status === 404 => new AiProviderException(
                'model_not_found',
                'AI 모델 또는 고정 API 경로를 찾을 수 없습니다.',
                false
            ),
            in_array($status, [408, 504], true) => new AiProviderException(
                'timeout',
                'AI Provider 응답 시간이 초과되었습니다.',
                true
            ),
            $status === 429 => new AiProviderException(
                'rate_limited',
                'AI Provider 요청 한도에 도달했습니다.',
                true
            ),
            $status >= 500 => new AiProviderException(
                'provider_unavailable',
                'AI Provider를 일시적으로 사용할 수 없습니다.',
                true
            ),
            default => new AiProviderException(
                'provider_rejected',
                'AI Provider 요청을 완료하지 못했습니다.',
                false
            ),
        };
    }

    private function requestId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
