<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Addon\AddonCapabilityKeys;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Modules\Permission\Contract\AddonCapabilityAssignmentGateway;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class SaveAddonCapabilityAssignmentsActionHandler implements ActionHandler
{
    private const ACTION = 'oj_save_addon_capability_assignments';

    /**
     * @param DefinitionRegistry<CapabilityDefinition> $capabilities
     * @param DefinitionRegistry<RoleDefinition> $roles
     */
    public function __construct(
        private readonly DefinitionRegistry $capabilities,
        private readonly DefinitionRegistry $roles,
        private readonly AddonCapabilityAssignmentGateway $assignments,
        private readonly ActionGuard $guard,
        private readonly ActionResponder $responses
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        try {
            $actor = $this->guard->actor(self::ACTION, $request, 'oj_manage_roles');
            $actorUserId = $actor->userId();

            $roleKeys = ['administrator', 'subscriber'];
            foreach ($this->roles->all() as $role) {
                if ($role instanceof RoleDefinition) {
                    $roleKeys[] = $role->key();
                }
            }

            $submitted = $request->body()['assignments'] ?? [];
            if (! is_array($submitted)) {
                $submitted = [];
            }

            foreach ($this->capabilities->all() as $capability) {
                if (
                    ! $capability instanceof CapabilityDefinition
                    || $capability->owner()->value() !== AddonCapabilityKeys::MODULE_OWNER
                ) {
                    continue;
                }
                $roleGrants = $submitted[$capability->key()] ?? [];
                if (! is_array($roleGrants)) {
                    $roleGrants = [];
                }
                foreach ($roleKeys as $roleKey) {
                    if (isset($roleGrants[$roleKey])) {
                        $this->assignments->grantToRole($roleKey, $capability->key());
                    } else {
                        $this->assignments->revokeFromRole($roleKey, $capability->key());
                    }
                }
            }

            return $this->responses->redirect(
                '/oj-console/roles',
                new UiMessage(
                    'auth.addon_capabilities_saved',
                    MessageTone::Success,
                    '애드온 권한 저장 완료',
                    '역할별 애드온 권한 배정을 저장했습니다.'
                ),
                $actorUserId
            );
        } catch (DomainException $error) {
            return $this->failure($error->getMessage(), $actorUserId);
        } catch (Throwable) {
            return $this->failure('애드온 권한 배정을 저장하지 못했습니다.', $actorUserId);
        }
    }

    private function failure(string $message, int $actorUserId): HttpResponse
    {
        return $this->responses->redirect(
            '/oj-console/roles',
            new UiMessage(
                'auth.addon_capabilities_failed',
                MessageTone::Danger,
                '애드온 권한 저장 실패',
                $message
            ),
            $actorUserId
        );
    }
}
