<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthTransaction;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LegacyJudgeUserReader;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LegacyTransitionChallengeStore;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Domain\LegacyJudgeCandidate;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserPreferences;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use DomainException;
use Throwable;

final class LegacyAccountTransitionService
{
    public function __construct(
        private readonly LegacyJudgeUserReader $legacyUsers,
        private readonly LegacyTransitionChallengeStore $challenges,
        private readonly WordPressUserGateway $users,
        private readonly UserProfileRepository $profiles,
        private readonly UserPreferenceRepository $preferences,
        private readonly AccountInputPolicy $inputs,
        private readonly AuthTransaction $transaction
    ) {
    }

    public function begin(
        LegacyJudgeCandidate $candidate,
        string $context
    ): string {
        $token = $this->challenges->issue(
            $candidate->fingerprint(),
            $this->contextFingerprint($context)
        );
        if ($token === '') {
            throw new DomainException('계정 전환 확인을 시작하지 못했습니다.');
        }

        return $token;
    }

    public function complete(
        string $token,
        string $userLogin,
        string $password,
        string $context,
        string $email = '',
        string $displayName = '',
        ?string $affiliation = null,
        bool $confirmed = false
    ): int {
        if (! $confirmed) {
            throw new DomainException('계정 전환 내용을 확인해야 합니다.');
        }

        $userLogin = $this->inputs->userLogin($userLogin);
        if ($userLogin === '' || $password === '') {
            throw new DomainException('로그인 아이디와 비밀번호를 다시 입력해야 합니다.');
        }

        if ($this->users->findByLogin($userLogin) !== null) {
            throw new DomainException('이미 WordPress 계정이 만들어진 로그인 아이디입니다.');
        }

        $candidate = $this->legacyUsers->verify($userLogin, $password);
        if ($candidate === null || $candidate->userLogin() !== $userLogin) {
            throw new DomainException('기존 계정 정보를 다시 확인해 주세요.');
        }

        $resolvedEmail = $this->inputs->email(
            trim($email) !== '' ? $email : $candidate->email()
        );
        $resolvedDisplayName = $this->inputs->displayName(
            trim($displayName) !== ''
                ? $displayName
                : ($candidate->displayName() !== ''
                    ? $candidate->displayName()
                    : $candidate->userLogin())
        );
        $resolvedAffiliation = $this->inputs->affiliation(
            $affiliation !== null && trim($affiliation) !== ''
                ? $affiliation
                : $candidate->affiliation()
        );
        $resolvedBirthDate = $this->inputs->birthDate($candidate->birthDate());
        if ($this->users->findByEmail($resolvedEmail) !== null) {
            throw new DomainException('이미 사용 중인 이메일입니다. 다른 이메일을 입력해 주세요.');
        }
        if (! $this->challenges->consume(
            $token,
            $candidate->fingerprint(),
            $this->contextFingerprint($context)
        )) {
            throw new DomainException('계정 전환 확인 시간이 만료되었습니다.');
        }

        $identity = $this->users->createUser(
            $candidate->userLogin(),
            $resolvedEmail,
            $password,
            $resolvedDisplayName,
            'subscriber'
        );

        try {
            $this->transaction->begin();
            $this->profiles->create(new UserProfile(
                $identity->userId(),
                $resolvedAffiliation,
                $resolvedBirthDate,
                AccessStatus::Active,
                true
            ));
            $this->preferences->save(new UserPreferences($identity->userId()));
            $this->transaction->commit();
        } catch (Throwable $error) {
            $this->transaction->rollBack();
            $this->users->deleteUser($identity->userId());

            throw $error;
        }

        $this->users->establishSession($identity->userId(), false);

        return $identity->userId();
    }

    private function contextFingerprint(string $context): string
    {
        return hash('sha256', trim($context));
    }
}
