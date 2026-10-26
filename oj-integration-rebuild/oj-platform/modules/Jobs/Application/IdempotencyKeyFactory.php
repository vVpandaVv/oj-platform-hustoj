<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDefinition;
use InvalidArgumentException;

final class IdempotencyKeyFactory
{
    /**
     * @param list<string> $targetKeys
     * @param array<string, mixed> $input
     */
    public function create(
        JobDefinition $definition,
        string $executionMode,
        array $targetKeys,
        string $inputVersion,
        array $input
    ): string {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $executionMode) !== 1) {
            throw new InvalidArgumentException('작업 실행 모드 형식이 올바르지 않습니다.');
        }

        if (preg_match('/^[1-9][0-9]*$/', $inputVersion) !== 1) {
            throw new InvalidArgumentException('작업 입력 버전 형식이 올바르지 않습니다.');
        }

        $normalizedTargets = array_values(array_unique(array_map('strval', $targetKeys)));
        sort($normalizedTargets, SORT_STRING);

        if ($normalizedTargets === []) {
            throw new InvalidArgumentException('작업 대상은 하나 이상이어야 합니다.');
        }

        $payload = [
            'job_type' => $definition->key(),
            'definition_version' => $definition->definitionVersion(),
            'execution_mode' => $executionMode,
            'target_keys' => $normalizedTargets,
            'input_version' => $inputVersion,
            'input' => $this->canonicalize($input),
        ];

        return hash(
            'sha256',
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
