<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureBlocker;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureParticipant;
use DomainException;

final class AccountErasureParticipantRegistry
{
    private const REQUIRED_MODULES = [
        'problems',
        'submissions',
        'classes',
        'contests',
        'groups',
        'ai_feedback',
    ];

    /** @var array<string, AccountErasureParticipant> */
    private array $participants = [];

    public function register(AccountErasureParticipant $participant): void
    {
        $key = $participant->moduleKey();
        if (isset($this->participants[$key])) {
            throw new DomainException('계정 익명화 참여 모듈이 중복되었습니다: ' . $key);
        }

        $this->participants[$key] = $participant;
    }

    /** @return list<AccountErasureParticipant> */
    public function all(): array
    {
        ksort($this->participants, SORT_STRING);

        return array_values($this->participants);
    }

    /** @return list<string> */
    public function missingModuleKeys(): array
    {
        $missing = array_values(array_diff(
            self::REQUIRED_MODULES,
            array_keys($this->participants)
        ));
        sort($missing, SORT_STRING);

        return $missing;
    }

    /** @return list<AccountErasureBlocker> */
    public function blockers(int $userId): array
    {
        $missing = $this->missingModuleKeys();
        $blockers = $missing === []
            ? []
            : [new AccountErasureBlocker(
                'auth',
                'participant_missing',
                '계정 정리 기능 준비 필요',
                count($missing)
            )];
        foreach ($this->all() as $participant) {
            foreach ($participant->blockers($userId) as $blocker) {
                if (! $blocker instanceof AccountErasureBlocker) {
                    throw new DomainException('계정 익명화 차단 결과가 올바르지 않습니다.');
                }
                $blockers[] = $blocker;
            }
        }

        return $blockers;
    }

    public function assertComplete(): void
    {
        $missing = $this->missingModuleKeys();
        if ($missing !== []) {
            throw new DomainException(
                '계정 정리 기능이 준비되지 않았습니다: ' . implode(', ', $missing)
            );
        }
    }
}
