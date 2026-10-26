<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusItem;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusReader;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use Throwable;

final class SystemStatusPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly SystemStatusReader $status,
        private readonly SettingsManager $settings,
        private readonly CurrentAccountProvider $accounts,
        private readonly Authorization $permissions,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $technical = $this->canViewTechnicalDetails();
        $rows = '';
        $hasFatal = false;
        foreach ($this->statusItems() as $item) {
            $hasFatal = $hasFatal || ($item->key() === 'last_fatal' && $item->tone() === 'danger');
            $details = '';
            if ($technical && $item->technicalDetails() !== []) {
                $detailRows = '';
                foreach ($item->technicalDetails() as $label => $value) {
                    $detailRows .= '<div><dt>' . $this->escape($label)
                        . '</dt><dd>' . $this->escape($value) . '</dd></div>';
                }
                $details = '<details' . ($item->key() === 'last_fatal' ? ' open' : '') . '><summary>기술 정보</summary><dl class="ojp-description-grid">'
                    . $detailRows . '</dl></details>';
            }
            $rows .= '<article class="ojp-status-card"><div><span class="ojp-badge ojp-badge--'
                . $this->escape($item->tone()) . '">' . $this->toneLabel($item->tone())
                . '</span><h2>' . $this->escape($item->label()) . '</h2></div><p>'
                . $this->escape($item->summary()) . '</p><p><strong>다음 조치:</strong> '
                . $this->escape($item->nextAction()) . '</p>' . $details . '</article>';
        }
        $clear = $this->clearAction($hasFatal, $technical);
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">운영</p>'
            . '<h1>시스템 상태</h1><p>등록된 Provider가 안전한 요약과 필요한 다음 조치를 제공합니다.</p>'
            . '</div>' . $clear . '</header><div class="ojp-status-grid">'
            . $rows . '</div>';

        return $this->pages->respond(
            '시스템 상태',
            $content,
            'operations.status',
            $request
        );
    }

    private function canViewTechnicalDetails(): bool
    {
        try {
            $userId = $this->accounts->current()->identity()?->userId() ?? 0;

            return $userId > 0
                && $this->permissions->can($userId, 'oj_manage_system');
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<SystemStatusItem> */
    private function statusItems(): array
    {
        try {
            $items = array_values(array_filter(
                $this->status->current(),
                static fn (mixed $item): bool => $item instanceof SystemStatusItem
            ));
            if ($items !== []) {
                return $items;
            }
        } catch (Throwable) {
        }

        return [new SystemStatusItem(
            'status_collection',
            '시스템 상태 수집',
            'danger',
            '시스템 상태 일부를 수집하지 못했습니다.',
            '서버 오류 기록을 확인한 뒤 다시 시도하세요.'
        )];
    }

    private function clearAction(bool $hasFatal, bool $technical): string
    {
        if (! $hasFatal || ! $technical) {
            return '';
        }

        try {
            $snapshot = $this->settings->read('last_fatal');

            return '<form method="post" action="'
                . $this->escape($this->actionUrls->actionUrl())
                . '">' . $this->security->hiddenFields(
                    'oj_platform_clear_last_fatal'
                )
                . '<input type="hidden" name="expected_revision" value="'
                . $snapshot->revision()
                . '"><button class="ojp-button" type="submit">'
                . '마지막 오류 표시 지우기</button></form>';
        } catch (Throwable) {
            return '';
        }
    }

    private function toneLabel(string $tone): string
    {
        return match ($tone) {
            'success' => '정상',
            'info' => '안내',
            'warning' => '확인 필요',
            default => '조치 필요',
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
