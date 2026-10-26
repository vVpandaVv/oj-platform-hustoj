<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthTransaction;
use CodingPanda\OjPlatform\Modules\Auth\Contract\RegistrationPolicyProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Domain\ApprovalMode;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationRequirements;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserPreferences;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use DomainException;
use Throwable;

final class RegistrationService
{
    public function __construct(
        private readonly WordPressUserGateway $users,
        private readonly UserProfileRepository $profiles,
        private readonly UserPreferenceRepository $preferences,
        private readonly AgreementRepository $agreements,
        private readonly GuardianConsentVerifier $guardianConsent,
        private readonly RegistrationPolicyProvider $policies,
        private readonly AccountInputPolicy $inputs,
        private readonly AuthTransaction $transaction
    ) {
    }

    public function register(RegistrationInput $input): RegistrationResult
    {
        $policy = $this->policies->current();
        if (! $policy->isEnabled()) {
            throw new DomainException('현재 회원가입을 받지 않습니다.');
        }

        $userLogin = $this->inputs->userLogin($input->userLogin);
        $email = $this->inputs->email($input->email);
        $displayName = $this->inputs->displayName($input->displayName);
        $password = $this->inputs->password($input->password);
        $affiliation = $this->inputs->affiliation($input->affiliation);
        $birthDate = $this->inputs->birthDate($input->birthDate);

        if (
            $this->users->findByLogin($userLogin) !== null
            || $this->users->findByEmail($email) !== null
        ) {
            throw new DomainException('사용할 수 없는 가입 정보입니다.');
        }

        $requiredDocuments = [];
        foreach ($policy->requiredAgreementTypes() as $typeSlug) {
            $document = $this->agreements->activeDocumentByType($typeSlug);
            if ($document === null) {
                throw new DomainException('필수 동의 문서가 준비되지 않았습니다.');
            }
            $requiredDocuments[] = $document->documentId();
        }

        $selectedDocuments = array_values(array_unique(array_map(
            'intval',
            $input->agreementDocumentIds
        )));
        if (array_diff($requiredDocuments, $selectedDocuments) !== []) {
            throw new DomainException('필수 문서에 동의해야 합니다.');
        }

        $guardianClaim = $this->guardianConsent->claim(
            $policy,
            $email,
            $birthDate,
            $input->guardianCode
        );
        try {
            $identity = $this->users->createUser(
                $userLogin,
                $email,
                $password,
                $displayName,
                $policy->defaultRole()
            );
        } catch (Throwable $error) {
            $this->guardianConsent->release($guardianClaim);

            throw $error;
        }
        $status = $policy->approvalMode() === ApprovalMode::Automatic
            ? AccessStatus::Active
            : AccessStatus::Pending;

        try {
            $this->transaction->begin();
            $this->profiles->create(new UserProfile(
                $identity->userId(),
                $affiliation,
                $birthDate,
                $status,
                false
            ));
            $this->profiles->saveRegistrationRequirements(
                $identity->userId(),
                new RegistrationRequirements(
                    $requiredDocuments,
                    $guardianClaim->isRequired()
                )
            );
            $this->preferences->save(new UserPreferences($identity->userId()));
            $this->agreements->recordAgreement(
                $identity->userId(),
                $selectedDocuments,
                $input->ipAddress,
                $input->userAgent
            );
            $this->guardianConsent->bind($guardianClaim, $identity->userId());
            $this->transaction->commit();
        } catch (Throwable $error) {
            $this->transaction->rollBack();
            $this->guardianConsent->release($guardianClaim);
            $this->users->deleteUser($identity->userId());

            throw $error;
        }

        if ($status === AccessStatus::Active) {
            $this->users->establishSession($identity->userId(), false);
        }

        return new RegistrationResult($identity->userId(), $status);
    }
}
