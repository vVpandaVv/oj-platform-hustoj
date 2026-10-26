<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\LegacyTransitionChallengeStore;

final class WordPressLegacyTransitionChallengeStore implements LegacyTransitionChallengeStore
{
    private const TTL_SECONDS = 600;

    public function issue(
        string $candidateFingerprint,
        string $contextFingerprint
    ): string {
        if (
            preg_match('/^[a-f0-9]{64}$/', $candidateFingerprint) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $contextFingerprint) !== 1
        ) {
            return '';
        }

        $token = bin2hex(random_bytes(32));
        $stored = add_option(
            $this->key($token),
            [
                'jti_hash' => hash('sha256', $token),
                'candidate_hash' => $candidateFingerprint,
                'context_hash' => $contextFingerprint,
                'expires_at' => time() + self::TTL_SECONDS,
            ],
            '',
            'no'
        );

        return $stored ? $token : '';
    }

    public function consume(
        string $token,
        string $candidateFingerprint,
        string $contextFingerprint
    ): bool {
        if (
            preg_match('/^[a-f0-9]{64}$/', $token) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $candidateFingerprint) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $contextFingerprint) !== 1
        ) {
            return false;
        }

        $key = $this->key($token);
        $value = get_option($key, null);
        if (! delete_option($key) || ! is_array($value)) {
            return false;
        }

        return hash_equals(
            (string) ($value['jti_hash'] ?? ''),
            hash('sha256', $token)
        ) && hash_equals(
            (string) ($value['candidate_hash'] ?? ''),
            $candidateFingerprint
        ) && hash_equals(
            (string) ($value['context_hash'] ?? ''),
            $contextFingerprint
        ) && (int) ($value['expires_at'] ?? 0) >= time();
    }

    private function key(string $token): string
    {
        return 'oj_platform_transition_' . hash('sha256', $token);
    }
}
