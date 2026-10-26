<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationPolicy;

interface RegistrationPolicyProvider
{
    public function current(): RegistrationPolicy;
}
