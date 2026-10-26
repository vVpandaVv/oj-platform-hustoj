<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Domain\ApprovalMode;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class SaveRegistrationSettingsActionHandler implements ActionHandler
{
    private const ACTION = 'oj_save_registration_settings';

    public function __construct(
        private readonly SettingsManager $settings,
        private readonly ActionGuard $guard,
        private readonly ActionResponder $responses
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        try {
            $actor = $this->guard->actor(
                self::ACTION,
                $request,
                'oj_manage_registration_settings'
            );
            $actorUserId = $actor->userId();
            $requiredTypes = $request->body()['required_agreement_types'] ?? [];
            $requiredTypes = is_array($requiredTypes)
                ? array_values(array_unique(array_map('strval', $requiredTypes)))
                : [];
            $mode = ApprovalMode::tryFrom($request->bodyString('approval_mode'))
                ?? ApprovalMode::Manual;
            $policy = new RegistrationPolicy(
                $request->bodyBool('enabled'),
                $mode,
                'subscriber',
                $requiredTypes,
                $request->bodyBool('guardian_consent_enabled'),
                $request->bodyInt('guardian_consent_age', 14),
                $request->bodyInt('guardian_consent_expires_hours', 24)
            );

            $snapshot = $this->settings->read('registration', []);
            $this->settings->save(
                'registration',
                [
                    'enabled' => $policy->isEnabled(),
                    'approval_mode' => $policy->approvalMode()->value,
                    'default_role' => $policy->defaultRole(),
                    'required_agreement_types' => $policy->requiredAgreementTypes(),
                    'guardian_consent_enabled' => $policy->guardianConsentEnabled(),
                    'guardian_consent_age' => $policy->guardianConsentAge(),
                    'guardian_consent_expires_hours' => $policy->guardianConsentExpiresHours(),
                ],
                $snapshot->revision()
            );

            return $this->responses->redirect(
                '/oj-console/users/registration',
                new UiMessage(
                    'auth.registration_settings_saved',
                    MessageTone::Success,
                    '가입 설정 저장 완료',
                    '변경한 설정은 이후 가입부터 적용됩니다.'
                ),
                $actorUserId
            );
        } catch (Throwable) {
            return $this->responses->redirect(
                '/oj-console/users/registration',
                new UiMessage(
                    'auth.registration_settings_failed',
                    MessageTone::Danger,
                    '가입 설정 저장 실패',
                    '입력값과 현재 설정 revision을 다시 확인해 주세요.'
                ),
                $actorUserId
            );
        }
    }
}
