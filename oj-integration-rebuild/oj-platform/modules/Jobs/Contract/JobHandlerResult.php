<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

use InvalidArgumentException;

final class JobHandlerResult
{
    /**
     * @param array<string, mixed> $safeResult
     */
    private function __construct(
        private readonly string $outcome,
        private readonly string $resultCode,
        private readonly string $message,
        private readonly array $safeResult,
        private readonly int $retryDelaySeconds
    ) {
        if (
            ! in_array(
                $this->outcome,
                ['succeeded', 'retry', 'failed', 'needs_attention'],
                true
            )
            || preg_match('/^[a-z][a-z0-9_.-]*$/', $this->resultCode) !== 1
            || trim($this->message) === ''
            || strlen($this->message) > 191
            || $this->retryDelaySeconds < 0
            || $this->retryDelaySeconds > 86400
        ) {
            throw new InvalidArgumentException('Jobs Handler 결과가 올바르지 않습니다.');
        }
    }

    /**
     * @param array<string, mixed> $safeResult
     */
    public static function succeeded(
        string $resultCode,
        string $message,
        array $safeResult = []
    ): self {
        return new self('succeeded', $resultCode, $message, $safeResult, 0);
    }

    public static function retry(
        string $resultCode,
        string $message,
        int $delaySeconds
    ): self {
        return new self('retry', $resultCode, $message, [], $delaySeconds);
    }

    public static function failed(string $resultCode, string $message): self
    {
        return new self('failed', $resultCode, $message, [], 0);
    }

    /**
     * @param array<string, mixed> $safeResult
     */
    public static function needsAttention(
        string $resultCode,
        string $message,
        array $safeResult = []
    ): self {
        return new self('needs_attention', $resultCode, $message, $safeResult, 0);
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        return new self(
            is_string($value['outcome'] ?? null) ? $value['outcome'] : '',
            is_string($value['result_code'] ?? null) ? $value['result_code'] : '',
            is_string($value['message'] ?? null) ? $value['message'] : '',
            is_array($value['safe_result'] ?? null) ? $value['safe_result'] : [],
            is_int($value['retry_delay_seconds'] ?? null)
                ? $value['retry_delay_seconds']
                : 0
        );
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function resultCode(): string
    {
        return $this->resultCode;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function safeResult(): array
    {
        return $this->safeResult;
    }

    public function retryDelaySeconds(): int
    {
        return $this->retryDelaySeconds;
    }

    /**
     * @return array{
     *   outcome: string,
     *   result_code: string,
     *   message: string,
     *   safe_result: array<string, mixed>,
     *   retry_delay_seconds: int
     * }
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'result_code' => $this->resultCode,
            'message' => $this->message,
            'safe_result' => $this->safeResult,
            'retry_delay_seconds' => $this->retryDelaySeconds,
        ];
    }
}
