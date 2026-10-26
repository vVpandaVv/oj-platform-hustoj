<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

enum LoginOutcome: string
{
    case Success = 'success';
    case InvalidCredentials = 'invalid_credentials';
    case PendingApproval = 'pending_approval';
    case Suspended = 'suspended';
    case ProfileCompletionRequired = 'profile_completion_required';
    case PasswordChangeRequired = 'password_change_required';
    case LegacyTransitionRequired = 'legacy_transition_required';
}
