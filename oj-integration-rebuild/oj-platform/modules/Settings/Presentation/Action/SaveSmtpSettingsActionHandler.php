<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Settings\Application\SmtpSettingsService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class SaveSmtpSettingsActionHandler implements ActionHandler
{
    public function __construct(
        private readonly SmtpSettingsService $settings,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $userId = 0;
        try {
            $actor = $this->guard->actor(
                'oj_save_smtp_settings',
                $request,
                'oj_manage_smtp'
            );
            $userId = $actor->userId();
            $this->settings->applyTestedCandidate(
                $userId,
                $request->bodyInt('expected_revision')
            );

            return $this->responses->redirect(
                '/oj-console/settings/smtp',
                new UiMessage(
                    'smtp.saved',
                    MessageTone::Success,
                    '메일 발송 설정 적용 완료',
                    '다음 메일부터 검사한 SMTP 연결을 사용합니다.'
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/settings/smtp',
                new UiMessage(
                    'smtp.save_failed',
                    MessageTone::Danger,
                    '메일 발송 설정 적용 실패',
                    $error instanceof \DomainException
                        ? $error->getMessage()
                        : '현재 운영 설정을 유지했습니다.'
                ),
                $userId
            );
        }
    }
}
