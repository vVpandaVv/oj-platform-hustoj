<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LoginThrottleControl;
use CodingPanda\OjPlatform\Modules\Settings\Application\SiteSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\SystemSettingsService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class SaveSystemSettingsActionHandler implements ActionHandler
{
    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly SiteSettingsService $site,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors,
        private readonly LoginThrottleControl $loginThrottle
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $userId = 0;
        try {
            $actor = $this->guard->actor(
                'oj_save_system_settings',
                $request,
                'oj_manage_system'
            );
            $userId = $actor->userId();
            $operation = trim($request->bodyString('operation', 'save'));
            if ($operation === 'save') {
                $this->settings->save(
                    $request->bodyBool('maintenance_enabled'),
                    $request->bodyString('maintenance_message'),
                    $request->bodyString('maintenance_ends_at'),
                    $this->settings->current()->frozenModules(),
                    $request->bodyInt('expected_revision')
                );
                $message = new UiMessage(
                    'system.saved',
                    MessageTone::Success,
                    '운영 설정 저장 완료',
                    '점검 모드 변경을 다음 요청부터 적용합니다.'
                );
            } elseif ($operation === 'issue_uninstall_approval') {
                $this->settings->issueUninstallApproval(
                    $request->bodyString('confirmed_service_name'),
                    $this->site->serviceName(),
                    $request->bodyString('backup_proof'),
                    $request->bodyString('restore_proof'),
                    $userId,
                    $request->bodyInt('expected_revision')
                );
                $message = new UiMessage(
                    'system.uninstall_approval_issued',
                    MessageTone::Warning,
                    '일회성 승인 발급 완료',
                    '승인은 30분 동안 현재 사용자에게만 유효합니다.'
                );
            } elseif ($operation === 'save_login_throttle') {
                $enabled = $request->bodyBool('login_throttle_enabled');
                $this->loginThrottle->save($enabled, $request->bodyInt('expected_revision'));
                $message = new UiMessage(
                    'system.login_throttle_saved',
                    $enabled ? MessageTone::Success : MessageTone::Warning,
                    $enabled ? '로그인 횟수 제한 켜짐' : '로그인 횟수 제한 꺼짐',
                    $enabled
                        ? '같은 IP와 로그인 아이디의 요청 횟수를 제한합니다.'
                        : '로그인 횟수 제한을 해제했습니다. 비밀번호 찾기와 회원가입 등의 제한은 유지합니다.'
                );
            } elseif ($operation === 'reset_login_throttle') {
                $this->loginThrottle->reset($request->bodyInt('expected_revision'));
                $message = new UiMessage(
                    'system.login_throttle_reset',
                    MessageTone::Success,
                    '로그인 횟수 제한 초기화 완료',
                    '모든 계정의 로그인 제한 기록을 초기화했습니다. 로그인은 다시 시도할 수 있으며 다른 요청 제한은 유지합니다.'
                );
            } else {
                throw new DomainException('운영 설정 처리 방식이 올바르지 않습니다.');
            }

            return $this->responses->redirect(
                '/oj-console/settings/system',
                $message,
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/settings/system',
                new UiMessage(
                    'system.failed',
                    MessageTone::Danger,
                    '운영 설정 처리 실패',
                    $error instanceof DomainException || $error instanceof \InvalidArgumentException
                        ? $error->getMessage()
                        : '화면을 새로 고친 뒤 다시 시도해 주세요.'
                ),
                $userId
            );
        }
    }
}
