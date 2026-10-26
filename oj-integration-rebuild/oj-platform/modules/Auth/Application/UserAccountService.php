<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthTransaction;
use CodingPanda\OjPlatform\Modules\Auth\Contract\RegistrationPolicyProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserManagementTargetPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserProfileWriter;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianConsentClaim;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationRequirements;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserPreferences;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use DomainException;
use Throwable;

final class UserAccountService
{
    public function __construct(
        private readonly WordPressUserGateway $users,
        private readonly WordPressUserProfileWriter $identityWriter,
        private readonly UserProfileRepository $profiles,
        private readonly UserPreferenceRepository $preferences,
        private readonly RegistrationPolicyProvider $policies,
        private readonly GuardianConsentVerifier $guardianConsent,
        private readonly AccountInputPolicy $inputs,
        private readonly AuthTransaction $transaction,
        private readonly UserManagementTargetPolicy $profileEditPolicy
    ) {
    }

    public function completeProfile(int $userId, UserSettingsInput $input): UserProfile
    {
        if ($this->users->findById($userId) === null) {
            throw new DomainException('사용자 계정을 찾을 수 없습니다.');
        }
        $existing = $this->profiles->find($userId);
        if ($existing !== null) {
            return $existing;
        }

        $value = $this->normalize($input);
        $this->assertEmailAvailable($userId, $value['email']);
        $claim = $this->guardianConsent->claim(
            $this->policies->current(),
            $value['email'],
            $value['birth_date'],
            $input->guardianCode
        );
        $profile = new UserProfile(
            $userId,
            $value['affiliation'],
            $value['birth_date'],
            AccessStatus::Active,
            false,
            $value['bio']
        );
        $preferences = $this->updatedPreferences($userId, $input, $value['code_template']);

        $this->writeProfile(
            $userId,
            $value['email'],
            $value['display_name'],
            null,
            $profile,
            $preferences,
            $claim,
            true,
            new RegistrationRequirements([], $claim->isRequired())
        );

        return $profile;
    }

    public function updateProfile(int $userId, UserSettingsInput $input): UserProfile
    {
        $profile = $this->profiles->find($userId);
        if ($profile === null) {
            throw new DomainException('사용자 프로필을 찾을 수 없습니다.');
        }

        $value = $this->normalize($input);
        $this->assertEmailAvailable($userId, $value['email']);
        $birthDateChanged = $value['birth_date'] !== $profile->birthDate();
        $requirements = $this->profiles->registrationRequirements($userId);
        $claim = $birthDateChanged
            ? $this->guardianConsent->claim(
                $this->policies->current(),
                $value['email'],
                $value['birth_date'],
                $input->guardianCode,
                $this->guardianConsent->hasVerifiedUser($userId)
            )
            : new GuardianConsentClaim(
                $requirements?->guardianConsentRequired() ?? false
            );
        $updated = new UserProfile(
            $userId,
            $value['affiliation'],
            $value['birth_date'],
            $profile->accessStatus(),
            $profile->mustChangePassword(),
            $value['bio']
        );
        $preferences = $this->updatedPreferences($userId, $input, $value['code_template']);
        $updatedRequirements = $birthDateChanged
            ? new RegistrationRequirements(
                $requirements?->agreementDocumentIds() ?? [],
                $claim->isRequired()
            )
            : null;

        $this->writeProfile(
            $userId,
            $value['email'],
            $value['display_name'],
            null,
            $updated,
            $preferences,
            $claim,
            false,
            $updatedRequirements
        );

        return $updated;
    }

    public function updateManagedProfile(
        int $userId,
        string $email,
        string $displayName,
        string $nickname,
        ?string $affiliation,
        ?string $birthDate,
        ?string $bio,
        int $actorUserId
    ): UserProfile {
        $profile = $this->profiles->find($userId);
        $identity = $this->users->findById($userId);
        if ($identity === null || $profile === null) {
            throw new DomainException('사용자 계정을 찾을 수 없습니다.');
        }
        if (! $this->profileEditPolicy->allows($actorUserId, $identity)) {
            throw new DomainException('이 사용자의 계정 정보를 변경할 수 없습니다.');
        }

        $email = $this->inputs->email($email);
        $displayName = $this->inputs->displayName($displayName);
        $nickname = $this->inputs->nickname($nickname);
        $affiliation = $this->inputs->affiliation($affiliation);
        $birthDate = $this->inputs->birthDate($birthDate);
        $bio = $this->inputs->bio($bio);
        $this->assertEmailAvailable($userId, $email);

        $birthDateChanged = $birthDate !== $profile->birthDate();
        $requirements = $this->profiles->registrationRequirements($userId);
        $claim = $birthDateChanged
            ? $this->guardianConsent->claim(
                $this->policies->current(),
                $email,
                $birthDate,
                null,
                $this->guardianConsent->hasVerifiedUser($userId)
            )
            : new GuardianConsentClaim(
                $requirements?->guardianConsentRequired() ?? false
            );
        $updated = new UserProfile(
            $userId,
            $affiliation,
            $birthDate,
            $profile->accessStatus(),
            $profile->mustChangePassword(),
            $bio
        );
        $updatedRequirements = $birthDateChanged
            ? new RegistrationRequirements(
                $requirements?->agreementDocumentIds() ?? [],
                $claim->isRequired()
            )
            : null;

        $this->writeProfile(
            $userId,
            $email,
            $displayName,
            $nickname,
            $updated,
            null,
            $claim,
            false,
            $updatedRequirements
        );

        return $updated;
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $identity = $this->users->findById($userId);
        $profile = $this->profiles->find($userId);
        if ($identity === null || $profile === null) {
            throw new DomainException('사용자 계정을 찾을 수 없습니다.');
        }
        if ($this->users->verifyCredentials($identity->userLogin(), $currentPassword) === null) {
            throw new DomainException('현재 비밀번호가 올바르지 않습니다.');
        }

        $this->users->setPassword(
            $userId,
            $this->inputs->password($newPassword, '새 비밀번호')
        );
        $this->users->invalidateSessions($userId);
        $this->profiles->save($profile->withPasswordChangeRequired(false));
        $this->users->establishSession($userId, false);
    }

    public function updateRankingVisibility(int $userId, string $visibility): UserPreferences
    {
        if ($this->users->findById($userId) === null) {
            throw new DomainException('사용자 계정을 찾을 수 없습니다.');
        }
        $preferences = $this->preferences->find($userId) ?? new UserPreferences($userId);
        $updated = $preferences->withRankingVisibility($visibility);
        $this->preferences->save($updated);

        return $updated;
    }

    /** @return array{email:string,display_name:string,affiliation:?string,birth_date:?string,bio:?string,code_template:?string} */
    private function normalize(UserSettingsInput $input): array
    {
        return [
            'email' => $this->inputs->email($input->email),
            'display_name' => $this->inputs->displayName($input->displayName),
            'affiliation' => $this->inputs->affiliation($input->affiliation),
            'birth_date' => $this->inputs->birthDate($input->birthDate),
            'bio' => $this->inputs->bio($input->bio),
            'code_template' => $this->inputs->codeTemplate($input->codeTemplate),
        ];
    }

    private function updatedPreferences(
        int $userId,
        UserSettingsInput $input,
        ?string $codeTemplate
    ): UserPreferences {
        $preferences = $this->preferences->find($userId) ?? new UserPreferences($userId);

        return $preferences->withSelfSettings(
            $input->profileVisibility,
            $input->activityVisibility,
            $input->defaultLanguage,
            $input->editorTheme,
            $codeTemplate,
            $input->defaultEditor,
            $input->editorFontSize,
            $input->inlineSubmissionPanel
        );
    }

    private function writeProfile(
        int $userId,
        string $email,
        string $displayName,
        ?string $nickname,
        UserProfile $profile,
        ?UserPreferences $preferences,
        GuardianConsentClaim $claim,
        bool $create,
        ?RegistrationRequirements $requirements
    ): void {
        $begun = false;
        try {
            $this->transaction->begin();
            $begun = true;
            if ($nickname === null) {
                $this->users->updateUser($userId, $email, $displayName);
            } else {
                $this->identityWriter->updateProfileIdentity(
                    $userId,
                    $email,
                    $displayName,
                    $nickname
                );
            }
            if ($create) {
                $this->profiles->create($profile);
            } else {
                $this->profiles->save($profile);
            }
            if ($requirements !== null) {
                $this->profiles->saveRegistrationRequirements($userId, $requirements);
            }
            if ($preferences !== null) {
                $this->preferences->save($preferences);
            }
            $this->guardianConsent->bind($claim, $userId);
            $this->transaction->commit();
        } catch (Throwable $error) {
            if ($begun) {
                $this->transaction->rollBack();
            }
            $this->guardianConsent->release($claim);

            throw $error;
        }
    }

    private function assertEmailAvailable(int $userId, string $email): void
    {
        $existing = $this->users->findByEmail($email);
        if ($existing !== null && $existing->userId() !== $userId) {
            throw new DomainException('이미 사용 중인 이메일 주소입니다.');
        }
    }
}
