<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Ai;

use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderException;
use CodingPanda\OjPlatform\Modules\Settings\Contract\StructuredAiProviderClient;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiTaskAssignment;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiStructuredOutput;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use JsonException;

final class OllamaClient implements StructuredAiProviderClient
{
    public function __construct(private readonly AiHttpJson $http)
    {
    }

    public function adapterKey(): string
    {
        return 'ollama';
    }

    public function models(AiProviderProfile $profile): array
    {
        $response = $this->http->get($profile, '/api/tags');
        $items = $response['models'] ?? null;
        if (! is_array($items)) {
            throw new AiProviderException(
                'invalid_response',
                'Ollama 모델 목록 응답이 올바르지 않습니다.',
                false
            );
        }
        $models = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? $item['model'] ?? ''));
            $digest = trim((string) ($item['digest'] ?? ''));
            if ($name !== '' && $digest !== '') {
                $models[$name] = $digest;
            }
        }
        if ($models === []) {
            throw new AiProviderException(
                'model_not_found',
                'Ollama에서 설치된 모델을 찾지 못했습니다.',
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
        return $this->generateStructured($profile, $messages, $maxOutputTokens, null, []);
    }

    public function generateStructured(
        AiProviderProfile $profile,
        array $messages,
        int $maxOutputTokens,
        ?array $schema,
        array $options
    ): array {
        $validated = AiTaskAssignment::parseOllamaOptions($options);
        if ($schema !== null) {
            $schema = AiStructuredOutput::fromInput(['output_contract' => ['json_schema' => $schema]]);
            // Ground the same native constraint in a trusted message, outside problem/addon text.
            $grounding = 'Return only one JSON object matching this response schema. Include every required field; '
                . 'derive values from the task, never fill missing fields with invented defaults. JSON schema: '
                . json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (($messages[0]['role'] ?? null) === 'system') {
                $messages[0]['content'] .= "\n\n" . $grounding;
            } else {
                array_unshift($messages, ['role' => 'system', 'content' => $grounding]);
            }
        }
        if (isset($validated['num_ctx']) && $maxOutputTokens >= $validated['num_ctx']) {
            throw new AiProviderException('context_too_small', 'Ollama 문맥 토큰은 출력 토큰보다 커야 합니다.', false);
        }
        $body = [
            'model' => $profile->model(),
            'messages' => $messages,
            'stream' => false,
            'think' => false,
            'format' => $schema ?? 'json',
            'options' => array_replace([
                'temperature' => $schema === null ? 1.0 : 0.2,
                'top_p' => 0.95,
                'top_k' => 64,
                'num_predict' => $maxOutputTokens,
            ], $validated),
        ];
        if ($schema !== null) {
            // Structured judgments must not silently lose evidence to context truncation.
            $body['truncate'] = false;
            $body['shift'] = false;
        }
        $response = $this->http->post($profile, '/api/chat', $body);
        if (($response['done_reason'] ?? null) === 'length') {
            throw new AiProviderException('output_truncated', 'Ollama 출력 토큰 한도로 응답이 잘렸습니다. 작업별 출력 토큰을 확인해 주세요.', false);
        }
        if (($response['done'] ?? null) === false) {
            throw new AiProviderException('invalid_response', 'Ollama가 완료되지 않은 응답을 반환했습니다. 서버·프록시 설정을 확인해 주세요.', false);
        }
        $content = $response['message']['content'] ?? null;
        if (! is_string($content)) {
            throw new AiProviderException(
                'invalid_response',
                'Ollama 응답의 message.content가 문자열이 아니거나 누락되었습니다.',
                false
            );
        }
        if (strlen($content) > 2097152) {
            throw new AiProviderException('invalid_response', 'Ollama 응답 본문이 2MB 제한을 초과했습니다.', false);
        }
        $content = AiHttpJson::stripJsonFence($content);
        if ($content === '') {
            $thinking = $response['message']['thinking'] ?? null;
            throw new AiProviderException(
                'invalid_response',
                is_string($thinking) && trim($thinking) !== ''
                    ? 'Ollama가 최종 답변 없이 생각 과정만 반환했습니다. 모델·출력 토큰을 확인해 주세요.'
                    : 'Ollama가 빈 답변을 반환했습니다. 모델·출력 토큰 설정을 확인해 주세요.',
                false
            );
        }
        try {
            $value = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            $reason = match ($error->getCode()) {
                JSON_ERROR_UTF8 => 'UTF-8 형식 오류',
                JSON_ERROR_DEPTH => 'JSON 중첩 깊이 초과',
                default => 'JSON 문법 오류',
            };
            throw new AiProviderException(
                'invalid_response',
                'Ollama 응답: ' . $reason . ' (본문 ' . strlen($content) . '바이트). 구조화 출력 지원을 확인해 주세요.',
                false
            );
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new AiProviderException(
                'invalid_response',
                'Ollama가 JSON 객체 대신 ' . (is_array($value) ? '배열 또는 빈 객체' : get_debug_type($value)) . '을 반환했습니다.',
                false
            );
        }
        // Domain validators still own field types/ranges. Detect ignored native required keys here.
        foreach ($schema['required'] ?? [] as $key) {
            if (is_string($key) && ! array_key_exists($key, $value)) {
                $label = preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/D', $key) === 1 ? $key : 'required';
                throw new AiProviderException(
                    'schema_required_missing',
                    'Ollama 스키마 불일치: ' . $label . ' 누락. 서버·모델의 구조화 출력 및 프록시의 format 전달을 확인하세요.',
                    false
                );
            }
        }

        return $value;
    }
}
