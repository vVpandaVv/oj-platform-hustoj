<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\LegacyJudgeUserReader;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LegacyJudgeUserRecordSource;
use CodingPanda\OjPlatform\Modules\Auth\Domain\LegacyJudgeCandidate;

final class VerifiedLegacyJudgeUserReader implements LegacyJudgeUserReader
{
    public function __construct(
        private readonly LegacyJudgeUserRecordSource $records,
        private readonly LegacyJudgePasswordVerifier $passwords
    ) {
    }

    public function verify(
        string $userLogin,
        string $password
    ): ?LegacyJudgeCandidate {
        $userLogin = trim($userLogin);
        if ($userLogin === '' || $password === '') {
            return null;
        }

        $record = $this->records->findByLogin($userLogin);
        if (
            $record === null
            || ! $record->isActive()
            || ! hash_equals($record->userLogin(), $userLogin)
            || ! $this->passwords->verify($password, $record->passwordHash())
        ) {
            return null;
        }

        return new LegacyJudgeCandidate(
            $record->userLogin(),
            $record->email(),
            $record->displayName(),
            $record->affiliation(),
            null
        );
    }
}
