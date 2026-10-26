<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Application\RegistrationInput;
use CodingPanda\OjPlatform\Modules\Auth\Application\RegistrationService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class RegisterUserActionHandler implements ActionHandler
{
    private const ACTION = 'oj_register_user';

    public function __construct(
        private readonly RegistrationService $registration,
        private readonly ActionGuard $guard,
        private readonly ActionResponder $responses
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        try {
            $this->guard->throttle(
                'registration',
                $request->header('x-oj-client-ip') ?? 'unknown',
                5,
                3600
            );
            $this->guard->verifyNonce(self::ACTION, $request);
            $password = $request->bodyString('password');
            if ($password !== $request->bodyString('password_confirm')) {
                throw new \DomainException('비밀번호 확인이 일치하지 않습니다.');
            }

            $result = $this->registration->register(new RegistrationInput(
                $request->bodyString('user_login'),
                $request->bodyString('email'),
                $password,
                $request->bodyString('display_name'),
                $request->bodyString('affiliation'),
                $request->bodyString('birth_date'),
                $request->bodyString('guardian_code'),
                $request->bodyIntList('agreement_document_ids'),
                $request->header('x-oj-client-ip'),
                $request->header('user-agent')
            ));

            if ($result->accessStatus() === AccessStatus::Active) {
                return $this->responses->redirect(
                    '/',
                    new UiMessage(
                        'auth.registration_complete',
                        MessageTone::Success,
                        '가입 완료',
                        '계정을 만들고 로그인했습니다.'
                    ),
                    $result->userId()
                );
            }

            return $this->responses->redirect(
                '/login',
                new UiMessage(
                    'auth.registration_pending',
                    MessageTone::Info,
                    '가입 신청 완료',
                    '관리자의 승인이 완료된 뒤 로그인할 수 있습니다.'
                )
            );
        } catch (Throwable) {
            return $this->responses->redirect(
                '/register',
                new UiMessage(
                    'auth.registration_failed',
                    MessageTone::Danger,
                    '가입 처리 실패',
                    '입력한 정보와 필수 동의를 다시 확인해 주세요.'
                )
            );
        }
    }
}
