<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionSetup;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class SyncRolePresetsActionHandler implements ActionHandler
{
    private const ACTION = 'oj_console_sync_role_presets';

    public function __construct(
        private readonly PermissionSetup $installer,
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
                'oj_manage_roles'
            );
            $actorUserId = $actor->userId();
            $report = $this->installer->synchronizeRoles();

            return $this->responses->redirect(
                '/oj-console/roles',
                new UiMessage(
                    'auth.roles_synchronized',
                    MessageTone::Success,
                    '역할 동기화 완료',
                    sprintf(
                        '새 역할 %d개를 만들고 기존 역할 %d개를 현재 권한 계약에 맞췄습니다.',
                        count($report->createdRoles()),
                        count($report->updatedRoles())
                    )
                ),
                $actorUserId
            );
        } catch (DomainException $error) {
            return $this->failure($error->getMessage(), $actorUserId);
        } catch (Throwable) {
            return $this->failure(
                'WordPress 역할을 현재 권한 계약에 맞추지 못했습니다.',
                $actorUserId
            );
        }
    }

    private function failure(string $message, int $actorUserId): HttpResponse
    {
        return $this->responses->redirect(
            '/oj-console/roles',
            new UiMessage(
                'auth.roles_sync_failed',
                MessageTone::Danger,
                '역할 동기화 실패',
                $message
            ),
            $actorUserId
        );
    }
}
