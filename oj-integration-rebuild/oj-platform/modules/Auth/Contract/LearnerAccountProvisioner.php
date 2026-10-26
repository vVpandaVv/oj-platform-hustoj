<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface LearnerAccountProvisioner
{
    public function provisionLearner(
        string $userLogin,
        string $email,
        string $password,
        string $displayName
    ): UserIdentity;
}
