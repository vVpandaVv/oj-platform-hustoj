<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Ai;

use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderClient;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderException;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use JsonException;

final class OpenAiCompatibleClient implements AiProviderClient
{
    public function __construct(private readonly AiHttpJson $http)
    {
    }

    public function adapterKey(): string
    {
        return 'openai_compatible';
    }

    public function models(AiProviderProfile $profile): array
    {
        $response = $this->http->get($profile, '/v1/models');
        $items = $response['data'] ?? null;
        if (! is_array($items)) {
            throw new AiProviderException(
                'invalid_response',
                'AI 모델 목록 응답이 올바르지 않습니다.',
                false
            );
        }
        $models = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                continue;
            }
            $id = trim($item['id']);
            if ($id === '') {
                continue;
            }
            $revision = is_scalar($item['revision'] ?? null)
                ? trim((string) $item['revision'])
                : '';
            if ($revision === '') {
                $revision = is_scalar($item['created'] ?? null)
                    ? (string) $item['created']
                    : hash('sha256', $id . ':' . (string) ($item['owned_by'] ?? ''));
            }
            $models[$id] = $revision;
        }
        if ($models === []) {
            throw new AiProviderException(
                'model_not_found',
                '사용 가능한 AI 모델을 찾지 못했습니다.',
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
        $response = $this->http->post($profile, '/v1/chat/completions', [
            'model' => $profile->model(),
            'messages' => $messages,
            'temperature' => 0.2,
            'max_tokens' => $maxOutputTokens,
            'stream' => false,
            'response_format' => ['type' => 'json_object'],
        ]);
        $content = $response['choices'][0]['message']['content'] ?? null;

        return $this->content($content);
    }

    /**
     * @return array<string, mixed>
     */
    private function content(mixed $content): array
    {
        if (! is_string($content) || strlen($content) > 2097152) {
            throw new AiProviderException(
                'invalid_response',
                'AI 생성 응답 본문이 올바르지 않습니다.',
                false
            );
        }
        $content = AiHttpJson::stripJsonFence($content);
        try {
            $value = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiProviderException(
                'invalid_response',
                'AI 생성 응답이 JSON 객체가 아닙니다.',
                false
            );
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new AiProviderException(
                'invalid_response',
                'AI 생성 응답이 JSON 객체가 아닙니다.',
                false
            );
        }

        return $value;
    }
}
