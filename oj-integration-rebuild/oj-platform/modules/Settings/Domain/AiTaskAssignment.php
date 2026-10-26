<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Domain;

use InvalidArgumentException;

final class AiTaskAssignment
{
    public function __construct(
        private readonly string $taskType,
        private readonly string $primaryProfileKey,
        private readonly ?string $fallbackProfileKey,
        private readonly int $maxOutputTokens,
        private readonly string $fallbackPolicy,
        private readonly array $ollamaOptions = []
    ) {
        if (
            preg_match(
                '/^(?:[a-z][a-z0-9_]*|addon\.[a-z][a-z0-9_]{2,63}\.[a-z][a-z0-9_.-]*)$/D',
                $this->taskType
            ) !== 1
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $this->primaryProfileKey) !== 1
            || (
                $this->fallbackProfileKey !== null
                && (
                    preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $this->fallbackProfileKey) !== 1
                    || $this->fallbackProfileKey === $this->primaryProfileKey
                )
            )
            || $this->maxOutputTokens < 128
            || $this->maxOutputTokens > 32768
            || ! in_array($this->fallbackPolicy, ['none', 'transient_only'], true)
            || ($this->fallbackPolicy === 'none' && $this->fallbackProfileKey !== null)
            || ($this->fallbackPolicy === 'transient_only' && $this->fallbackProfileKey === null)
        ) {
            throw new InvalidArgumentException('AI 작업 배정이 올바르지 않습니다.');
        }
        $options = self::parseOllamaOptions($this->ollamaOptions);
        if (isset($options['num_ctx']) && $this->maxOutputTokens >= $options['num_ctx']) {
            throw new InvalidArgumentException('Ollama 문맥 토큰은 출력 토큰보다 커야 합니다.');
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $fallback = trim((string) ($value['fallback_profile_key'] ?? ''));

        return new self(
            trim((string) ($value['task_type'] ?? '')),
            trim((string) ($value['primary_profile_key'] ?? '')),
            $fallback !== '' ? $fallback : null,
            (int) ($value['max_output_tokens'] ?? 1200),
            trim((string) ($value['fallback_policy'] ?? 'none')),
            self::parseOllamaOptions($value['ollama_options'] ?? [])
        );
    }

    public function taskType(): string
    {
        return $this->taskType;
    }

    public function primaryProfileKey(): string
    {
        return $this->primaryProfileKey;
    }

    public function fallbackProfileKey(): ?string
    {
        return $this->fallbackProfileKey;
    }

    public function maxOutputTokens(): int
    {
        return $this->maxOutputTokens;
    }

    public function fallbackPolicy(): string
    {
        return $this->fallbackPolicy;
    }

    /** @return array<string,int|float> */
    public function ollamaOptions(): array
    {
        return self::parseOllamaOptions($this->ollamaOptions);
    }

    /** @return array<string,int|float> */
    public static function parseOllamaOptions(mixed $raw): array
    {
        if (! is_array($raw) || array_diff(array_keys($raw), ['temperature', 'num_ctx']) !== []) {
            throw new InvalidArgumentException('Ollama 생성 설정 형식이 올바르지 않습니다.');
        }
        $options = [];
        foreach ($raw as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (
                ! is_scalar($value) || is_bool($value) || ! is_numeric($value) || ! is_finite((float) $value)
                || ($key === 'temperature' && ($value < 0 || $value > 2))
                || ($key === 'num_ctx'
                    && ((float) $value !== floor((float) $value) || $value < 2048 || $value > 1048576))
            ) {
                throw new InvalidArgumentException('Ollama 생성 설정은 유효한 숫자여야 합니다.');
            }
            $options[$key] = $key === 'num_ctx' ? (int) $value : (float) $value;
        }
        ksort($options);
        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $value = [
            'task_type' => $this->taskType,
            'primary_profile_key' => $this->primaryProfileKey,
            'fallback_profile_key' => $this->fallbackProfileKey,
            'max_output_tokens' => $this->maxOutputTokens,
            'fallback_policy' => $this->fallbackPolicy,
        ];
        if ($this->ollamaOptions !== []) {
            $value['ollama_options'] = $this->ollamaOptions();
        }
        return $value;
    }
}
