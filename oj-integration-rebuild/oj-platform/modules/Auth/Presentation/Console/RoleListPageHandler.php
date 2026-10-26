<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Console;

use CodingPanda\OjPlatform\Kernel\Addon\AddonCapabilityKeys;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Permission\Contract\AddonCapabilityAssignmentGateway;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;

final class RoleListPageHandler implements RouteHandler
{
    /**
     * @param DefinitionRegistry<RoleDefinition> $roles
     * @param DefinitionRegistry<CapabilityDefinition> $capabilities
     */
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly DefinitionRegistry $roles,
        private readonly DefinitionRegistry $capabilities,
        private readonly AddonCapabilityAssignmentGateway $addonAssignments,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);

        $rows = '';
        foreach ($this->roles->all() as $role) {
            if (! $role instanceof RoleDefinition) {
                continue;
            }

            $rows .= '<tr><td>' . $this->escape($role->label())
                . '</td><td><code>' . $this->escape($role->key())
                . '</code></td><td>' . count($role->capabilities())
                . '개</td></tr>';
        }

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">사용자</p>'
            . '<h1>역할과 권한</h1><p>현재 권한 계약과 WordPress 역할을 같은 상태로 유지합니다.</p></div>'
            . '<form method="post" action="' . $this->escape($this->actionUrls->actionUrl())
            . '">' . $this->security->hiddenFields('oj_console_sync_role_presets')
            . '<button class="ojp-button ojp-button--primary" type="submit">역할 동기화</button>'
            . '</form></header>'
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>역할</th><th>키</th><th>권한</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . $this->renderAddonCapabilitySection();

        return $this->pages->respond(
            '역할과 권한',
            $content,
            'users.roles',
            $request
        );
    }

    private function renderAddonCapabilitySection(): string
    {
        $addonCapabilities = array_values(array_filter(
            $this->capabilities->all(),
            static fn (CapabilityDefinition $capability): bool =>
                $capability->owner()->value() === AddonCapabilityKeys::MODULE_OWNER
        ));
        if ($addonCapabilities === []) {
            return '';
        }

        $roleColumns = [
            'administrator' => 'administrator(관리자)',
            'subscriber' => 'subscriber(학습자)',
        ];
        foreach ($this->roles->all() as $role) {
            if ($role instanceof RoleDefinition) {
                $roleColumns[$role->key()] = $role->label();
            }
        }

        $head = '<th>애드온 권한</th>';
        foreach ($roleColumns as $label) {
            $head .= '<th>' . $this->escape($label) . '</th>';
        }

        $rows = '';
        foreach ($addonCapabilities as $capability) {
            $granted = $this->addonAssignments->rolesGrantedTo($capability->key());
            $rows .= '<tr><td>' . $this->escape($capability->label())
                . '<br><code>' . $this->escape($capability->key()) . '</code></td>';
            foreach (array_keys($roleColumns) as $roleKey) {
                $inputName = 'assignments[' . $capability->key() . '][' . $roleKey . ']';
                $rows .= '<td><input type="checkbox" name="' . $this->escape($inputName) . '"'
                    . ($this->checked(in_array($roleKey, $granted, true)))
                    . '></td>';
            }
            $rows .= '</tr>';
        }

        return '<section class="ojp-section" aria-labelledby="addon-capabilities">'
            . '<div class="ojp-section-heading"><div><h2 id="addon-capabilities">애드온 권한</h2>'
            . '<p>애드온이 선언한 권한을 역할별로 명시적으로 배정합니다. 자동으로 역할을 만들거나'
            . ' 권한을 부여하지 않습니다.</p></div></div>'
            . '<form method="post" action="' . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_addon_capability_assignments')
            . '<div class="ojp-table-wrap"><table class="ojp-table ojp-table--matrix"><thead><tr>' . $head . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . '<div class="ojp-command-group">'
            . '<button class="ojp-button ojp-button--primary" type="submit">애드온 권한 저장</button>'
            . '</div></form></section>';
    }

    private function checked(bool $condition): string
    {
        return $condition ? ' checked' : '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
