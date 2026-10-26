<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use InvalidArgumentException;

final class AddonCodeExecutionResultV1
{
    /** @param array<string,mixed> $payload */
    public function __construct(private readonly bool $successful, private readonly array $payload,
        private readonly ?AddonApiProblemV1 $problem)
    {
        if ($successful === ($problem !== null) || (! $successful && $payload !== [])) {
            throw new InvalidArgumentException('Invalid execution result.');
        }
        if (! $successful) { return; }
        $keys = array_keys($payload); sort($keys);
        if ($keys !== ['actual_output', 'diagnostics', 'result_code', 'status', 'ticket', 'verdict']
            || ! is_string($payload['ticket']) || ! is_int($payload['result_code'])
            || ! is_string($payload['actual_output']) || ! is_string($payload['diagnostics'])) {
            throw new InvalidArgumentException('Invalid execution payload.');
        }
        new AddonCodeExecutionQueryV1(1, $payload['ticket']);
        Internal\ValueValidator::oneOf($payload['status'], ['pending', 'completed'], 'status');
        Internal\ValueValidator::oneOf($payload['verdict'], ['pending', 'executed', 'compile_error', 'runtime_error',
            'output_incomplete', 'time_limit', 'memory_limit', 'output_limit'], 'verdict');
        if (($payload['status'] === 'pending') !== ($payload['verdict'] === 'pending')
            || strlen($payload['actual_output']) > 17000 || strlen($payload['diagnostics']) > 8000
            || preg_match('//u', $payload['actual_output'] . $payload['diagnostics']) !== 1) {
            throw new InvalidArgumentException('Invalid bounded execution output.');
        }
    }
    public function isSuccessful(): bool { return $this->successful; }
    /** @return array<string,mixed> */
    public function payload(): array { return $this->payload; }
    public function problem(): ?AddonApiProblemV1 { return $this->problem; }
}
