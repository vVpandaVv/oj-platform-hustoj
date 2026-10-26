<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\LegacyJudgeUserReader;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserLoginHistoryRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;

final class LoginService
{
    public function __construct(
        private readonly WordPressUserGateway $users,
        private readonly UserProfileRepository $profiles,
        private readonly LegacyJudgeUserReader $legacyUsers,
        private readonly UserLoginHistoryRepository $loginHistory
    ) {
    }

    public function authenticate(
        string $userLogin,
        string $password,
        bool $remember,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): LoginResult {
        $userLogin = trim($userLogin);
        if ($userLogin === '' || $password === '') {
            return new LoginResult(LoginOutcome::InvalidCredentials);
        }

        $knownUser = $this->users->findByLogin($userLogin);
        if ($knownUser !== null) {
            $identity = $this->users->verifyCredentials($userLogin, $password);
            if ($identity === null) {
                return new LoginResult(LoginOutcome::InvalidCredentials);
            }

            $profile = $this->profiles->find($identity->userId());
            if ($profile === null) {
                $this->users->establishSession($identity->userId(), $remember);

                return new LoginResult(
                    LoginOutcome::ProfileCompletionRequired,
                    $identity->userId()
                );
            }

            if ($profile->accessStatus() === AccessStatus::Pending) {
                return new LoginResult(LoginOutcome::PendingApproval, $identity->userId());
            }

            if ($profile->accessStatus() === AccessStatus::Suspended) {
                return new LoginResult(LoginOutcome::Suspended, $identity->userId());
            }

            $this->users->establishSession($identity->userId(), $remember);
            $this->profiles->recordLogin($identity->userId());
            $this->loginHistory->record($identity->userId(), $ipAddress, $userAgent);

            return new LoginResult(
                $profile->mustChangePassword()
                    ? LoginOutcome::PasswordChangeRequired
                    : LoginOutcome::Success,
                $identity->userId()
            );
        }

        $legacyCandidate = $this->legacyUsers->verify($userLogin, $password);
        if ($legacyCandidate !== null) {
            return new LoginResult(
                LoginOutcome::LegacyTransitionRequired,
                null,
                $legacyCandidate
            );
        }

        return new LoginResult(LoginOutcome::InvalidCredentials);
    }
}
