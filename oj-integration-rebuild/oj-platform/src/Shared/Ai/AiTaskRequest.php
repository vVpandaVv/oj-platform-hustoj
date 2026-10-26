<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Shared\Ai;

use InvalidArgumentException;
use JsonException;

final class AiTaskRequest
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        private readonly string $taskType,
        private readonly string $contractVersion,
        private readonly array $input
    ) {
        if (
            preg_match(
                '/^(?:[a-z][a-z0-9_]*|addon\.[a-z][a-z0-9_]{2,63}\.[a-z][a-z0-9_.-]*)$/D',
                $this->taskType
            ) !== 1
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,49}$/', $this->contractVersion) !== 1
        ) {
            throw new InvalidArgumentException('AI 작업 요청 계약이 올바르지 않습니다.');
        }
        try {
            $encoded = json_encode(
                $this->input,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $error) {
            throw new InvalidArgumentException('AI 작업 입력을 직렬화하지 못했습니다.', 0, $error);
        }
        if (strlen($encoded) > 1048576) {
            throw new InvalidArgumentException('AI 작업 입력은 1MB를 초과할 수 없습니다.');
        }
    }

    public function taskType(): string
    {
        return $this->taskType;
    }

    public function contractVersion(): string
    {
        return $this->contractVersion;
    }

    /**
     * @return array<string, mixed>
     */
    public function input(): array
    {
        return $this->input;
    }
}
