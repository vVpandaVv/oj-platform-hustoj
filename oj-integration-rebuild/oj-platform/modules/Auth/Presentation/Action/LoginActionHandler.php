<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\SafeRedirect;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Application\LegacyAccountTransitionService;
use CodingPanda\OjPlatform\Modules\Auth\Application\LoginOutcome;
use CodingPanda\OjPlatform\Modules\Auth\Application\LoginService;
use CodingPanda\OjPlatform\Modules\Auth\Domain\LegacyJudgeCandidate;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class LoginActionHandler implements ActionHandler
{
    private const ACTION = 'oj_login';

    public function __construct(
        private readonly LoginService $login,
        private readonly LegacyAccountTransitionService $transition,
        private readonly SafeRedirect $redirects,
        private readonly ActionGuard $guard,
        private readonly ActionResponder $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        try {
            $this->guard->verifyNonce(self::ACTION, $request);
            $this->guard->throttle(
                'login',
                ($request->header('x-oj-client-ip') ?? 'unknown')
                    . '|' . strtolower(trim($request->bodyString('user_login'))),
                10,
                600
            );
            if ($request->bodyString('operation') === 'transition') {
                $userId = $this->transition->complete(
                    $request->bodyString('transition_token'),
                    $request->bodyString('user_login'),
                    $request->bodyString('password'),
                    $this->transitionContext($request),
                    $request->bodyString('email'),
                    $request->bodyString('display_name'),
                    $request->bodyString('affiliation'),
                    $request->bodyBool('confirm_transition')
                );

                return $this->responses->redirect(
                    '/user-settings?mode=password',
                    $this->message(
                        'auth.transition_complete',
                        MessageTone::Success,
                        '계정 가져오기 완료',
                        '새 WordPress 비밀번호를 설정하면 이용을 시작할 수 있습니다.'
                    ),
                    $userId
                );
            }

            $result = $this->login->authenticate(
                $request->bodyString('user_login'),
                $request->bodyString('password'),
                $request->bodyBool('remember'),
                $request->header('x-oj-client-ip'),
                $request->header('user-agent')
            );

            return match ($result->outcome()) {
                LoginOutcome::Success => HttpResponse::redirect(
                    $this->redirects->afterLogin($request->bodyString('redirect_to'))
                ),
                LoginOutcome::ProfileCompletionRequired => HttpResponse::redirect(
                    '/user-settings?mode=profile-completion'
                ),
                LoginOutcome::PasswordChangeRequired => HttpResponse::redirect(
                    '/user-settings?mode=password'
                ),
                LoginOutcome::PendingApproval => $this->responses->redirect(
                    '/login',
                    $this->message(
                        'auth.approval_pending',
                        MessageTone::Info,
                        '가입 승인 대기',
                        '관리자의 승인이 완료된 뒤 로그인할 수 있습니다.'
                    )
                ),
                LoginOutcome::Suspended => $this->responses->redirect(
                    '/login',
                    $this->message(
                        'auth.suspended',
                        MessageTone::Warning,
                        '이용이 중지된 계정',
                        '현재 계정으로 로그인할 수 없습니다.'
                    )
                ),
                LoginOutcome::LegacyTransitionRequired => $this->legacyTransition(
                    $result->legacyCandidate(),
                    $this->transitionContext($request)
                ),
                default => $this->invalidCredentials(),
            };
        } catch (DomainException $error) {
            return $this->responses->redirect(
                $this->failureRedirect($request),
                $this->message(
                    'auth.transition_validation_failed',
                    MessageTone::Danger,
                    $request->bodyString('operation') === 'transition'
                        ? '계정 가져오기 확인 필요'
                        : '로그인 확인 필요',
                    $error->getMessage()
                )
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->invalidCredentials();
        }
    }

    private function legacyTransition(
        ?LegacyJudgeCandidate $candidate,
        string $context
    ): HttpResponse {
        if ($candidate === null) {
            return $this->invalidCredentials();
        }

        try {
            $token = $this->transition->begin($candidate, $context);

            return $this->responses->redirect(
                '/login?mode=transition&transition=' . rawurlencode($token),
                $this->message(
                    'auth.transition_confirm',
                    MessageTone::Info,
                    '기존 계정 확인',
                    '계정 정보를 다시 입력하면 가져오기를 진행합니다.'
                )
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->invalidCredentials();
        }
    }

    private function failureRedirect(HttpRequest $request): string
    {
        $token = trim($request->bodyString('transition_token'));
        if (
            $request->bodyString('operation') !== 'transition'
            || $token === ''
            || strlen($token) > 256
        ) {
            return '/login';
        }

        return '/login?mode=transition&transition=' . rawurlencode($token);
    }

    private function invalidCredentials(): HttpResponse
    {
        return $this->responses->redirect(
            '/login',
            $this->message(
                'auth.invalid_credentials',
                MessageTone::Danger,
                '로그인 실패',
                '로그인 아이디와 비밀번호를 다시 확인해 주세요.'
            )
        );
    }

    private function message(
        string $code,
        MessageTone $tone,
        string $title,
        string $body
    ): UiMessage {
        return new UiMessage($code, $tone, $title, $body);
    }

    private function transitionContext(HttpRequest $request): string
    {
        return ($request->header('x-oj-client-ip') ?? 'unknown')
            . "\0"
            . ($request->header('user-agent') ?? 'unknown');
    }
}
