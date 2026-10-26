<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface UserLoginBatchProvider
{
    /** @param list<string> $logins @return array<string, UserIdentity> */
    public function findManyByLogin(array $logins): array;
}
