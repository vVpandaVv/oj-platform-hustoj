<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Messaging\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingSettingsService;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessagingScope;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessagingSettings;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;

final class MessagingSettingsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly MessagingSettingsService $settings,
        private readonly FeatureFlags $features,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $snapshot = $this->settings->snapshot();
        $settings = $this->settings->current();
        $groupsEnabled = $this->features->isEnabled('groups');

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">쪽지 운영</p>'
            . '<h1>쪽지 설정</h1><p>발송 범위와 오남용 방지를 위한 제한값을 관리합니다.</p>'
            . '</div></header><form class="ojp-form" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_messaging_settings')
            . '<input type="hidden" name="expected_revision" value="' . $snapshot->revision() . '">'
            . $this->scopeSettings($settings, $groupsEnabled)
            . $this->limitSettings($settings)
            . '<div class="ojp-command-group"><button class="ojp-button ojp-button--primary" type="submit">'
            . '설정 저장</button></div></form>';

        return $this->pages->respond('쪽지 설정', $content, 'messaging.settings', $request);
    }

    private function scopeSettings(MessagingSettings $settings, bool $groupsEnabled): string
    {
        $groupOnlyDisabled = $groupsEnabled ? '' : ' disabled';
        $groupOnlyNote = $groupsEnabled
            ? ''
            : '<p class="ojp-field-help">그룹 기능이 꺼져 있어 이 옵션을 선택할 수 없습니다.</p>';

        return '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>발송 범위</h2><p>전체 사용자 간 자유 발송 또는 같은 그룹 소속으로 제한할 수 있습니다.</p>'
            . '</div></div><div class="ojp-field ojp-segmented-field" role="radiogroup">'
            . '<span class="ojp-label">발송 범위</span>'
            . '<label><input type="radio" name="scope" value="open"'
            . ($settings->scope() === MessagingScope::Open ? ' checked' : '') . '> 전체 사용자</label>'
            . '<label><input type="radio" name="scope" value="group_only"'
            . ($settings->scope() === MessagingScope::GroupOnly ? ' checked' : '') . $groupOnlyDisabled
            . '> 같은 그룹만</label>' . $groupOnlyNote . '</div></section>';
    }

    private function limitSettings(MessagingSettings $settings): string
    {
        return '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>오남용 방지 제한</h2><p>관리자 설정으로도 시스템 상한 이상으로 완화할 수 없습니다.</p>'
            . '</div></div><div class="ojp-form-grid">'
            . '<div class="ojp-field"><label for="send-rate-limit">10분당 발송 건수</label>'
            . '<input class="ojp-input" id="send-rate-limit" type="number" name="send_rate_limit"'
            . ' min="1" max="30" value="' . $settings->sendRateLimit() . '"></div>'
            . '<div class="ojp-field"><label for="max-message-length">최대 글자 수</label>'
            . '<input class="ojp-input" id="max-message-length" type="number" name="max_message_length"'
            . ' min="100" max="5000" value="' . $settings->maxMessageLength() . '"></div>'
            . '</div></section>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
