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
use CodingPanda\OjPlatform\Modules\Settings\Domain\SmtpSettings;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class TestSmtpSettingsActionHandler implements ActionHandler
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
                'oj_send_smtp_test_mail',
                $request,
                'oj_manage_smtp'
            );
            $userId = $actor->userId();
            $this->guard->throttle('smtp_candidate_test', (string) $userId, 3, 900);
            $current = $this->settings->current();
            $password = $request->bodyString('password');
            if ($password === '' && $current !== null) {
                $password = $current->password();
            }
            $candidate = new SmtpSettings(
                $request->bodyBool('enabled'),
                trim($request->bodyString('host')),
                $request->bodyInt('port', 587),
                trim($request->bodyString('encryption', 'starttls')),
                $request->bodyBool('authentication'),
                trim($request->bodyString('username')),
                $password,
                trim($request->bodyString('from_address')),
                trim($request->bodyString('from_name'))
            );
            $this->settings->testCandidate(
                $candidate,
                trim($request->bodyString('test_recipient')),
                $userId
            );

            return $this->responses->redirect(
                '/oj-console/settings/smtp',
                new UiMessage(
                    'smtp.test_succeeded',
                    MessageTone::Success,
                    '시험 메일 발송 완료',
                    '검사한 후보는 15분 안에 운영 설정으로 적용할 수 있습니다.'
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/settings/smtp',
                new UiMessage(
                    'smtp.test_failed',
                    MessageTone::Danger,
                    '시험 메일 발송 실패',
                    $error instanceof \DomainException || $error instanceof \InvalidArgumentException
                        ? $error->getMessage()
                        : '현재 운영 설정은 유지했습니다. 후보 연결을 확인해 주세요.'
                ),
                $userId
            );
        }
    }
}
