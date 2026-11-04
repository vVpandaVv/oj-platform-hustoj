<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface PasswordResetTargetDiagnostics
{
    /** Read-only inspection; it does not authorize a password reset. */
    public function inspectTarget(UserIdentity $target): AccountProtectionDiagnostic;
}
