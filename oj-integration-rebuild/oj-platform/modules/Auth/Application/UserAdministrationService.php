<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\GuardianVerificationRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserManagementTargetPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use DomainException;

final class UserAdministrationService
{
    public function __construct(
        private readonly UserProfileRepository $profiles,
        private readonly AgreementRepository $agreements,
        private readonly GuardianVerificationRepository $guardians,
        private readonly WordPressUserGateway $users,
        private readonly UserManagementTargetPolicy $targets
    ) {
    }

    public function approve(int $targetUserId, int $actorUserId): void
    {
        $profile = $this->profiles->find($targetUserId);
        if ($profile === null || $profile->accessStatus() !== AccessStatus::Pending) {
            throw new DomainException('현재 승인할 수 있는 사용자가 아닙니다.');
        }
        $this->assertTargetAllowed($actorUserId, $targetUserId, 'oj_approve_user_registrations');

        $requirements = $this->profiles->registrationRequirements($targetUserId);
        if ($requirements === null) {
            throw new DomainException('가입 승인 요구사항을 확인할 수 없습니다.');
        }

        if (
            array_diff(
                $requirements->agreementDocumentIds(),
                $this->agreements->agreedDocumentIds($targetUserId)
            ) !== []
        ) {
            throw new DomainException('필수 문서 동의가 완료되지 않았습니다.');
        }

        if (
            $requirements->guardianConsentRequired()
            && ! $this->guardians->hasConsumedVerification($targetUserId)
        ) {
            throw new DomainException('법정대리인 동의가 완료되지 않았습니다.');
        }

        $this->profiles->save(
            $profile->withAccessStatus(AccessStatus::Active),
            $actorUserId
        );
    }

    public function changeAccessStatus(
        int $targetUserId,
        AccessStatus $status,
        int $actorUserId
    ): void {
        if ($status === AccessStatus::Pending) {
            throw new DomainException('이용 상태를 승인 대기로 되돌릴 수 없습니다.');
        }

        $profile = $this->profiles->find($targetUserId);
        if ($profile === null) {
            throw new DomainException('대상 사용자를 찾을 수 없습니다.');
        }
        if ($actorUserId === $targetUserId) {
            throw new DomainException('본인 이용 상태는 이 화면에서 바꿀 수 없습니다.');
        }
        $this->assertTargetAllowed($actorUserId, $targetUserId, 'oj_deactivate_users');
        if ($profile->accessStatus() === AccessStatus::Pending) {
            throw new DomainException('승인 대기 계정은 가입 승인 절차를 완료해야 합니다.');
        }

        $this->profiles->save(
            $profile->withAccessStatus($status),
            $actorUserId
        );
    }

    private function assertTargetAllowed(int $actorUserId, int $targetUserId, string $capability): void
    {
        $identity = $this->users->findById($targetUserId);
        if ($identity === null || ! $this->targets->allows($actorUserId, $identity, $capability)) {
            throw new DomainException('이 사용자의 이용 상태를 변경할 수 없습니다.');
        }
    }
}
