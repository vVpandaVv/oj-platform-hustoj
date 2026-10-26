<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthTransaction;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LearnerAccountProvisioner;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserPreferences;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use DomainException;
use Throwable;

final class AdminUserCreationService implements LearnerAccountProvisioner
{
    public function __construct(
        private readonly WordPressUserGateway $users,
        private readonly UserProfileRepository $profiles,
        private readonly UserPreferenceRepository $preferences,
        private readonly AccountInputPolicy $inputs,
        private readonly AuthTransaction $transaction
    ) {
    }

    public function provisionLearner(
        string $userLogin,
        string $email,
        string $password,
        string $displayName
    ): UserIdentity {
        return $this->create(
            $userLogin,
            $email,
            $password,
            $displayName,
            null,
            null,
            'subscriber'
        );
    }

    public function create(
        string $userLogin,
        string $email,
        string $password,
        string $displayName,
        ?string $affiliation,
        ?string $birthDate,
        string $role
    ): UserIdentity {
        $input = $this->normalize(
            $userLogin,
            $email,
            $password,
            $displayName,
            $affiliation,
            $birthDate,
            $role
        );

        if (
            $this->users->findByLogin($input['user_login']) !== null
            || $this->users->findByEmail($input['email']) !== null
        ) {
            throw new DomainException('사용할 수 없는 계정 정보입니다.');
        }

        return $this->createNormalized($input);
    }

    public function createIdempotently(
        string $userLogin,
        string $email,
        string $password,
        string $displayName,
        ?string $affiliation,
        ?string $birthDate,
        string $role
    ): UserIdentity {
        $input = $this->normalize(
            $userLogin,
            $email,
            $password,
            $displayName,
            $affiliation,
            $birthDate,
            $role
        );
        $existing = $this->matchingExisting($input);
        if ($existing !== null) {
            return $existing;
        }

        return $this->createNormalized($input);
    }

    /**
     * @param array{
     *   user_login: string,
     *   email: string,
     *   password: string,
     *   display_name: string,
     *   affiliation: ?string,
     *   birth_date: ?string,
     *   role: string
     * } $input
     */
    private function createNormalized(array $input): UserIdentity
    {
        $identity = null;
        $transactionStarted = false;

        try {
            $this->transaction->begin();
            $transactionStarted = true;
            $identity = $this->users->createUser(
                $input['user_login'],
                $input['email'],
                $input['password'],
                $input['display_name'],
                $input['role']
            );
            $this->profiles->create(new UserProfile(
                $identity->userId(),
                $input['affiliation'],
                $input['birth_date'],
                AccessStatus::Active,
                true
            ));
            $this->preferences->save(new UserPreferences($identity->userId()));
            $this->transaction->commit();
            $transactionStarted = false;
        } catch (Throwable $error) {
            if ($transactionStarted) {
                $this->transaction->rollBack();
            }
            if (
                $identity instanceof UserIdentity
                && $this->users->findById($identity->userId()) !== null
            ) {
                $this->users->deleteUser($identity->userId());
            }

            throw $error;
        }

        return $identity;
    }

    /**
     * @return array{
     *   user_login: string,
     *   email: string,
     *   password: string,
     *   display_name: string,
     *   affiliation: ?string,
     *   birth_date: ?string,
     *   role: string
     * }
     */
    private function normalize(
        string $userLogin,
        string $email,
        string $password,
        string $displayName,
        ?string $affiliation,
        ?string $birthDate,
        string $role
    ): array {
        return [
            'user_login' => $this->inputs->userLogin($userLogin),
            'email' => $this->inputs->email($email),
            'password' => $this->inputs->password($password, '초기 비밀번호'),
            'display_name' => $this->inputs->displayName($displayName),
            'affiliation' => $this->inputs->affiliation($affiliation),
            'birth_date' => $this->inputs->birthDate($birthDate),
            'role' => $role,
        ];
    }

    /**
     * @param array{
     *   user_login: string,
     *   email: string,
     *   password: string,
     *   display_name: string,
     *   affiliation: ?string,
     *   birth_date: ?string,
     *   role: string
     * } $input
     */
    private function matchingExisting(array $input): ?UserIdentity
    {
        $byLogin = $this->users->findByLogin($input['user_login']);
        $byEmail = $this->users->findByEmail($input['email']);
        if ($byLogin === null && $byEmail === null) {
            return null;
        }
        if (
            $byLogin === null
            || $byEmail === null
            || $byLogin->userId() !== $byEmail->userId()
        ) {
            throw new DomainException('사용할 수 없는 계정 정보입니다.');
        }

        $verified = $this->users->verifyCredentials(
            $input['user_login'],
            $input['password']
        );
        $profile = $this->profiles->find($byLogin->userId());
        $roles = $byLogin->roles();
        sort($roles, SORT_STRING);
        $expectedRoles = [$input['role']];
        sort($expectedRoles, SORT_STRING);
        if (
            $verified?->userId() !== $byLogin->userId()
            || strcasecmp($byLogin->email(), $input['email']) !== 0
            || $byLogin->displayName() !== $input['display_name']
            || $roles !== $expectedRoles
            || $profile === null
            || $profile->affiliation() !== $input['affiliation']
            || $profile->birthDate() !== $input['birth_date']
            || $profile->accessStatus() !== AccessStatus::Active
            || ! $profile->mustChangePassword()
            || $this->preferences->find($byLogin->userId()) === null
        ) {
            throw new DomainException('기존 계정이 작업 입력과 일치하지 않습니다.');
        }

        return $byLogin;
    }
}
