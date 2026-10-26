<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Settings\Contract\CandidateProof;
use CodingPanda\OjPlatform\Modules\Settings\Contract\CandidateProofStore;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretReference;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretVault;
use RuntimeException;
use Throwable;

final class WordPressCandidateProofStore implements CandidateProofStore
{
    public const CLEANUP_HOOK = 'oj_platform_candidate_proof_cleanup';

    private const LEDGER_OPTION = 'oj_platform_candidate_proof_ledger';
    private const LOCK_OPTION = 'oj_platform_candidate_proof_ledger_lock';

    public function __construct(private readonly SecretVault $vault)
    {
    }

    public function remember(string $scope, CandidateProof $proof): void
    {
        $this->assertScope($scope);
        $this->cleanupExpired();
        $this->forget($scope, $proof->actorUserId());

        $key = $this->key($scope, $proof->actorUserId());
        $value = [
            'fingerprint' => $proof->fingerprint(),
            'payload_reference' => $proof->payloadReference()->value(),
            'actor_user_id' => $proof->actorUserId(),
            'expires_at' => $proof->expiresAt(),
            'safe_result' => $proof->safeResult(),
        ];
        if (! function_exists('set_transient') || ! set_transient(
            $key,
            $value,
            max(1, $proof->expiresAt() - time())
        )) {
            throw new RuntimeException('후보 검사 증명을 저장하지 못했습니다.');
        }

        try {
            $this->mutateLedger(static function (array &$ledger) use (
                $key,
                $proof
            ): void {
                $ledger[$key] = [
                    'payload_reference' => $proof->payloadReference()->value(),
                    'expires_at' => $proof->expiresAt(),
                ];
            });
        } catch (Throwable $error) {
            if (function_exists('delete_transient')) {
                delete_transient($key);
            }
            throw $error;
        }

        $this->scheduleCleanup($proof->expiresAt());
    }

    public function find(string $scope, int $actorUserId): ?CandidateProof
    {
        $this->assertScope($scope);
        $this->cleanupExpired();
        if (! function_exists('get_transient')) {
            return null;
        }

        $key = $this->key($scope, $actorUserId);
        $value = get_transient($key);
        if (! is_array($value)) {
            $this->forget($scope, $actorUserId);

            return null;
        }
        try {
            $proof = new CandidateProof(
                (string) ($value['fingerprint'] ?? ''),
                new SecretReference((string) ($value['payload_reference'] ?? '')),
                (int) ($value['actor_user_id'] ?? 0),
                (int) ($value['expires_at'] ?? 0),
                is_array($value['safe_result'] ?? null)
                    ? $value['safe_result']
                    : []
            );
        } catch (Throwable) {
            $this->forget($scope, $actorUserId);

            return null;
        }
        if (
            $proof->actorUserId() !== $actorUserId
            || $proof->isExpired(time())
        ) {
            $this->forget($scope, $actorUserId);

            return null;
        }

        return $proof;
    }

    public function consume(
        string $scope,
        int $actorUserId
    ): ?CandidateProof {
        $this->assertScope($scope);
        $proof = $this->find($scope, $actorUserId);
        if ($proof === null) {
            return null;
        }

        $key = $this->key($scope, $actorUserId);
        $claimed = false;
        $this->mutateLedger(static function (array &$ledger) use (
            $key,
            $proof,
            &$claimed
        ): void {
            $value = $ledger[$key] ?? null;
            if (
                is_array($value)
                && hash_equals(
                    $proof->payloadReference()->value(),
                    (string) ($value['payload_reference'] ?? '')
                )
            ) {
                unset($ledger[$key]);
                $claimed = true;
            }
        });
        if (! $claimed) {
            return null;
        }
        if (function_exists('delete_transient')) {
            delete_transient($key);
        }

        return $proof;
    }

    public function forget(string $scope, int $actorUserId): void
    {
        $this->assertScope($scope);
        $key = $this->key($scope, $actorUserId);
        if (function_exists('delete_transient')) {
            delete_transient($key);
        }

        $reference = null;
        $this->mutateLedger(static function (array &$ledger) use (
            $key,
            &$reference
        ): void {
            $value = $ledger[$key] ?? null;
            if (is_array($value)) {
                try {
                    $reference = new SecretReference(
                        (string) ($value['payload_reference'] ?? '')
                    );
                } catch (Throwable) {
                    $reference = null;
                }
            }
            unset($ledger[$key]);
        });
        if ($reference instanceof SecretReference) {
            $this->vault->discard($reference);
        }
    }

    public function cleanupExpired(): void
    {
        $now = time();
        $expired = [];
        $nextExpiresAt = null;
        $this->mutateLedger(static function (array &$ledger) use (
            $now,
            &$expired,
            &$nextExpiresAt
        ): void {
            foreach ($ledger as $key => $value) {
                if (
                    ! is_array($value)
                    || (int) ($value['expires_at'] ?? 0) <= $now
                ) {
                    $expired[$key] = is_array($value)
                        ? (string) ($value['payload_reference'] ?? '')
                        : '';
                    unset($ledger[$key]);
                    continue;
                }
                $expiresAt = (int) $value['expires_at'];
                $nextExpiresAt = $nextExpiresAt === null
                    ? $expiresAt
                    : min($nextExpiresAt, $expiresAt);
            }
        });

        foreach ($expired as $key => $referenceValue) {
            if (function_exists('delete_transient')) {
                delete_transient($key);
            }
            try {
                $this->vault->discard(new SecretReference($referenceValue));
            } catch (Throwable) {
            }
        }
        if ($nextExpiresAt !== null) {
            $this->scheduleCleanup($nextExpiresAt);
        }
    }

    private function scheduleCleanup(int $expiresAt): void
    {
        if (! function_exists('wp_schedule_single_event')) {
            return;
        }
        $scheduledAt = function_exists('wp_next_scheduled')
            ? wp_next_scheduled(self::CLEANUP_HOOK)
            : false;
        if (
            $scheduledAt === false
            || (is_int($scheduledAt) && $scheduledAt > $expiresAt + 1)
        ) {
            wp_schedule_single_event($expiresAt + 1, self::CLEANUP_HOOK);
        }
    }

    private function key(string $scope, int $actorUserId): string
    {
        if ($actorUserId < 1) {
            throw new RuntimeException('후보 검사 사용자 번호가 올바르지 않습니다.');
        }

        return 'ojp_candidate_' . hash('sha256', $scope . ':' . $actorUserId);
    }

    private function assertScope(string $scope): void
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,99}$/', $scope) !== 1) {
            throw new RuntimeException('후보 검사 범위가 올바르지 않습니다.');
        }
    }

    /**
     * @param callable(array<string, array<string, mixed>>&): void $mutator
     */
    private function mutateLedger(callable $mutator): void
    {
        $this->withLock(function () use ($mutator): void {
            $ledger = $this->readLedger();
            $mutator($ledger);
            $this->writeLedger($ledger);
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readLedger(): array
    {
        if (! function_exists('get_option')) {
            throw new RuntimeException('WordPress 후보 검사 저장소를 사용할 수 없습니다.');
        }
        $value = get_option(self::LEDGER_OPTION, []);

        return is_array($value)
            ? array_filter($value, 'is_array')
            : [];
    }

    /**
     * @param array<string, array<string, mixed>> $ledger
     */
    private function writeLedger(array $ledger): void
    {
        if (! function_exists('update_option')) {
            throw new RuntimeException('WordPress 후보 검사 저장소를 사용할 수 없습니다.');
        }
        if (! update_option(self::LEDGER_OPTION, $ledger, false)) {
            $current = get_option(self::LEDGER_OPTION, null);
            if ($current !== $ledger) {
                throw new RuntimeException('후보 검사 만료 목록을 갱신하지 못했습니다.');
            }
        }
    }

    private function withLock(callable $callback): void
    {
        if (
            ! function_exists('add_option')
            || ! function_exists('delete_option')
            || ! function_exists('get_option')
        ) {
            throw new RuntimeException('WordPress 후보 검사 잠금을 사용할 수 없습니다.');
        }

        $token = bin2hex(random_bytes(16));
        $acquired = false;
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $acquired = add_option(
                self::LOCK_OPTION,
                ['token' => $token, 'expires_at' => time() + 15],
                '',
                'no'
            );
            if ($acquired) {
                break;
            }
            $lock = get_option(self::LOCK_OPTION, null);
            if (is_array($lock) && (int) ($lock['expires_at'] ?? 0) < time()) {
                delete_option(self::LOCK_OPTION);
            }
            usleep(20000);
        }
        if (! $acquired) {
            throw new RuntimeException('다른 후보 검사 저장 작업이 진행 중입니다.');
        }

        try {
            $callback();
        } finally {
            $lock = get_option(self::LOCK_OPTION, null);
            if (
                is_array($lock)
                && hash_equals((string) ($lock['token'] ?? ''), $token)
            ) {
                delete_option(self::LOCK_OPTION);
            }
        }
    }
}
