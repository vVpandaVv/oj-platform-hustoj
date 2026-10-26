<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Ai;

use CodingPanda\OjPlatform\Modules\Settings\Contract\AiHttpTransport;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderClient;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderException;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use JsonException;

final class GeminiClient implements AiProviderClient
{
    public function __construct(
        private readonly AiHttpJson $http,
        private readonly AiHttpTransport $transport
    ) {
    }

    public function adapterKey(): string
    {
        return 'gemini';
    }

    public function models(AiProviderProfile $profile): array
    {
        $response = $this->transport->request(
            'GET',
            $this->http->url($profile->baseUrl(), '/models'),
            $this->http->geminiHeaders($profile),
            null,
            3,
            min(30, $profile->timeoutSeconds()),
            $profile->resolvedIps()
        );
        if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
            throw new AiProviderException(
                in_array($response->statusCode(), [401, 403], true)
                    ? 'authentication_failed'
                    : 'provider_unavailable',
                'Gemini 모델 목록을 확인하지 못했습니다.',
                $response->statusCode() >= 500
            );
        }
        try {
            $decoded = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiProviderException(
                'invalid_response',
                'Gemini 모델 목록 응답이 올바르지 않습니다.',
                false
            );
        }
        $items = is_array($decoded) ? ($decoded['models'] ?? null) : null;
        if (! is_array($items)) {
            throw new AiProviderException(
                'invalid_response',
                'Gemini 모델 목록 응답이 올바르지 않습니다.',
                false
            );
        }
        $models = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $methods = is_array($item['supportedGenerationMethods'] ?? null)
                ? $item['supportedGenerationMethods']
                : [];
            $name = trim((string) ($item['name'] ?? ''));
            if ($name !== '' && in_array('generateContent', $methods, true)) {
                $models[$name] = hash(
                    'sha256',
                    $name . ':' . (string) ($item['version'] ?? '')
                );
            }
        }
        if ($models === []) {
            throw new AiProviderException(
                'model_not_found',
                'Gemini generateContent 모델을 찾지 못했습니다.',
                false
            );
        }
        ksort($models);

        return $models;
    }

    public function generate(
        AiProviderProfile $profile,
        array $messages,
        int $maxOutputTokens
    ): array {
        $system = '';
        $contents = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $system .= ($system === '' ? '' : "\n") . $message['content'];
                continue;
            }
            $contents[] = [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $message['content']]],
            ];
        }
        $model = str_starts_with($profile->model(), 'models/')
            ? substr($profile->model(), strlen('models/'))
            : $profile->model();
        $response = $this->http->post(
            $profile,
            '/models/' . rawurlencode($model) . ':generateContent',
            [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => $maxOutputTokens,
                    'responseMimeType' => 'application/json',
                ],
            ],
            true
        );
        $candidate = $response['candidates'][0] ?? null;
        $parts = is_array($candidate) ? ($candidate['content']['parts'] ?? null) : null;
        if (! is_array($parts)) {
            throw new AiProviderException(
                'invalid_response',
                'Gemini 생성 응답 본문이 올바르지 않습니다.',
                false
            );
        }
        $content = '';
        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $content .= $part['text'];
            }
        }
        $content = AiHttpJson::stripJsonFence($content);
        if ($content === '') {
            $finishReason = is_string($candidate['finishReason'] ?? null)
                ? $candidate['finishReason']
                : '';
            throw new AiProviderException(
                'invalid_response',
                $finishReason !== ''
                    ? 'Gemini가 빈 응답을 반환했습니다 (finishReason: ' . $finishReason . ').'
                    : 'Gemini가 빈 응답을 반환했습니다.',
                false
            );
        }
        $finishReason = is_string($candidate['finishReason'] ?? null)
            ? $candidate['finishReason']
            : '';
        try {
            $value = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiProviderException(
                'invalid_response',
                $this->notJsonObjectMessage($finishReason),
                false
            );
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new AiProviderException(
                'invalid_response',
                $this->notJsonObjectMessage($finishReason),
                false
            );
        }

        return $value;
    }

    private function notJsonObjectMessage(string $finishReason): string
    {
        if ($finishReason === 'MAX_TOKENS') {
            return 'Gemini 생성 응답이 JSON 객체가 아닙니다 (출력 토큰 한도를 초과해 응답이 중간에 잘렸습니다.'
                . ' 전체 제한 시간·출력 길이를 늘리거나 더 짧은 작업으로 다시 검사해 주세요).';
        }

        return $finishReason !== ''
            ? 'Gemini 생성 응답이 JSON 객체가 아닙니다 (finishReason: ' . $finishReason . ').'
            : 'Gemini 생성 응답이 JSON 객체가 아닙니다.';
    }
}
