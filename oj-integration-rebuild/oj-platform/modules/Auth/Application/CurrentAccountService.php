<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;

final class CurrentAccountService implements CurrentAccountProvider
{
    public function __construct(
        private readonly WordPressUserGateway $users,
        private readonly UserProfileRepository $profiles
    ) {
    }

    public function current(): CurrentAccount
    {
        $identity = $this->users->currentUser();

        return new CurrentAccount(
            $identity,
            $identity === null ? null : $this->profiles->find($identity->userId())
        );
    }
}
