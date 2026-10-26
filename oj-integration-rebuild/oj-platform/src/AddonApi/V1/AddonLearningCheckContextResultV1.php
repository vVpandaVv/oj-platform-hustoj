<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonLearningCheckContextResultV1
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly bool $successful,
        private readonly array $payload,
        private readonly ?AddonApiProblemV1 $problem
    ) {
        if ($this->successful === ($this->problem instanceof AddonApiProblemV1)) {
            throw new InvalidArgumentException('Addon learning check result invariant failed.');
        }
        if (! $this->successful) {
            if ($this->payload !== []) {
                throw new InvalidArgumentException('Failed learning checks cannot contain context.');
            }
            return;
        }
        ValueValidator::jsonMap($this->payload, 'payload', 1048576);
        $keys = ['problem', 'submission'];
        if (array_key_exists('latest_own_submission_id', $this->payload)) { $keys[] = 'latest_own_submission_id'; }
        self::keys($this->payload, $keys);
        $latestOwnSubmissionId = $this->payload['latest_own_submission_id'] ?? null;
        if ($latestOwnSubmissionId !== null && (! is_int($latestOwnSubmissionId) || $latestOwnSubmissionId < 0)) {
            throw new InvalidArgumentException('Invalid latest own submission identifier.');
        }
        $problem = $this->payload['problem'];
        if (! is_array($problem)) {
            throw new InvalidArgumentException('Invalid learning check problem.');
        }
        self::keys($problem, ['id', 'title', 'description', 'input', 'output', 'sample_input', 'sample_output', 'content_hash']);
        self::positiveId($problem['id']);
        if (! is_string($problem['content_hash']) || preg_match('/^[a-f0-9]{64}$/D', $problem['content_hash']) !== 1) {
            throw new InvalidArgumentException('Invalid learning check content hash.');
        }
        foreach (['title', 'description', 'input', 'output', 'sample_input', 'sample_output'] as $key) {
            if (! is_string($problem[$key])) {
                throw new InvalidArgumentException('Invalid learning check problem text.');
            }
            ValueValidator::text($problem[$key], $key, 200000, true, true);
        }
        $submission = $this->payload['submission'];
        if ($submission === null) {
            return;
        }
        if (! is_array($submission)) {
            throw new InvalidArgumentException('Invalid learning check submission.');
        }
        self::keys($submission, ['id', 'problem_id', 'source', 'language_id', 'language', 'result_code', 'submitted_at', 'processing']);
        self::positiveId($submission['id']);
        if ($latestOwnSubmissionId !== null && $submission['id'] > $latestOwnSubmissionId) {
            throw new InvalidArgumentException('Latest own submission identifier precedes the projected submission.');
        }
        if ($submission['problem_id'] !== $problem['id']
            || ! is_int($submission['language_id']) || $submission['language_id'] < 0
            || ! is_int($submission['result_code'])
            || ! is_bool($submission['processing'])
            || ! is_string($submission['source'])
            || ! is_string($submission['submitted_at'])
            || ! is_string($submission['language'])
        ) {
            throw new InvalidArgumentException('Invalid learning check submission fields.');
        }
        ValueValidator::text($submission['source'], 'source', 65536, true, true);
        ValueValidator::text($submission['submitted_at'], 'submitted_at', 80, true);
        ValueValidator::oneOf($submission['language'], ['python', 'c', 'cpp', 'java', 'unknown'], 'language');
    }

    public function isSuccessful(): bool { return $this->successful; }
    /** @return array<string, mixed> */
    public function payload(): array { return $this->payload; }
    public function problem(): ?AddonApiProblemV1 { return $this->problem; }

    /** @param array<mixed> $value @param list<string> $keys */
    private static function keys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new InvalidArgumentException('Invalid learning check context fields.');
        }
    }

    private static function positiveId(mixed $value): void
    {
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException('Invalid learning check identifier.');
        }
    }
}
