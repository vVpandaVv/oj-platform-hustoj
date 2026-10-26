<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessRule;
use LogicException;

final class JobAccessRuleRegistry
{
    /**
     * @var array<string, JobAccessRule>
     */
    private array $rules = [];

    /**
     * @param iterable<JobAccessRule> $rules
     */
    public function __construct(iterable $rules)
    {
        foreach ($rules as $rule) {
            $policyKeys = $rule->policyKeys();
            if ($policyKeys === []) {
                throw new LogicException('Jobs 접근 정책 키가 비어 있습니다.');
            }
            foreach ($policyKeys as $policyKey) {
                if (! $this->isValidKey(
                    $policyKey,
                    $rule->policyVersion()
                )) {
                    throw new LogicException(
                        'Jobs 접근 정책 식별자가 올바르지 않습니다.'
                    );
                }
                $key = $this->registryKey($policyKey, $rule->policyVersion());
                if (isset($this->rules[$key])) {
                    throw new LogicException(
                        'Jobs 접근 정책이 중복 등록되었습니다: ' . $key
                    );
                }
                $this->rules[$key] = $rule;
            }
        }
    }

    public function find(
        string $policyKey,
        string $policyVersion
    ): ?JobAccessRule {
        if (! $this->isValidKey($policyKey, $policyVersion)) {
            return null;
        }

        return $this->rules[
            $this->registryKey($policyKey, $policyVersion)
        ] ?? null;
    }

    private function registryKey(
        string $policyKey,
        string $policyVersion
    ): string {
        return $policyKey . '@' . $policyVersion;
    }

    private function isValidKey(
        string $policyKey,
        string $policyVersion
    ): bool {
        return preg_match(
            '/^[a-z][a-z0-9_.-]{2,63}$/',
            $policyKey
        ) === 1
            && preg_match('/^[1-9][0-9]*$/', $policyVersion) === 1;
    }
}
