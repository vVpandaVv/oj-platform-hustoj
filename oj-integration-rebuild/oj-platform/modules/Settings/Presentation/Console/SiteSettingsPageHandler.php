<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Settings\Application\SiteSettingsService;

final class SiteSettingsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly SiteSettingsService $settings,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $snapshot = $this->settings->snapshot();
        $site = $this->settings->current();
        $footerLinks = $site->footerLinks();
        $content = OperationsHtml::header(
            '운영',
            '사이트',
            '사용자 화면과 관리자 화면에서 함께 사용하는 표시 문구입니다.'
        )
            . '<section class="ojp-section"><form class="ojp-form-stack" method="post" action="'
            . OperationsHtml::escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_save_site_settings')
            . '<input type="hidden" name="expected_revision" value="'
            . $snapshot->revision() . '"><div class="ojp-form-grid">'
            . '<label class="ojp-field"><span class="ojp-label">서비스 이름</span>'
            . '<input class="ojp-input" name="service_name" maxlength="40" required value="'
            . OperationsHtml::escape($site->serviceName()) . '"></label>'
            . '<label class="ojp-field"><span class="ojp-label">짧은 설명</span>'
            . '<input class="ojp-input" name="short_description" maxlength="100" value="'
            . OperationsHtml::escape($site->shortDescription()) . '"></label>'
            . '<label class="ojp-field"><span class="ojp-label">운영 연락 표시</span>'
            . '<input class="ojp-input" name="support_line" maxlength="60" value="'
            . OperationsHtml::escape($site->supportLine()) . '"></label></div>'
            . '<div class="ojp-form-section"><h2>사이트 푸터</h2>'
            . '<div class="ojp-form-grid">'
            . '<label class="ojp-field"><span class="ojp-label">저작권 표시</span>'
            . '<input class="ojp-input" name="copyright_notice" maxlength="160" value="'
            . OperationsHtml::escape($site->copyrightNotice())
            . '" placeholder="예: © 2026 학교명. All rights reserved."></label>'
            . '<label class="ojp-field"><span class="ojp-label">관리자 이메일</span>'
            . '<input class="ojp-input" type="email" name="administrator_email" maxlength="254" value="'
            . OperationsHtml::escape($site->administratorEmail())
            . '" autocomplete="email" placeholder="admin@example.com"></label></div>'
            . '<p class="ojp-field-help">운영 연락 표시는 일반 안내 문구로, 관리자 이메일은 문의 링크로 표시됩니다.</p>'
            . $this->footerVisibilityFields($site)
            . '<h2>추가 링크</h2>'
            . '<p class="ojp-field-help">추가 링크는 최대 6개까지 입력할 수 있습니다.</p>'
            . '<div class="ojp-footer-link-list">'
            . $this->footerLinkFields($footerLinks)
            . '</div></div>'
            . '<div class="ojp-command-group"><button class="ojp-button ojp-button--primary"'
            . ' type="submit">사이트 설정 저장</button></div></form></section>';

        return $this->pages->respond(
            '사이트',
            $content,
            'operations.site',
            $request
        );
    }

    private function footerVisibilityFields(\CodingPanda\OjPlatform\Modules\Settings\Domain\SiteSettings $site): string
    {
        $html = '<fieldset class="ojp-form-section"><legend>하단 기본 링크 표시</legend>'
            . '<input type="hidden" name="footer_visibility_present" value="1"><div class="ojp-form-grid">';
        foreach ($site::FOOTER_LINKS as $path => $label) {
            $html .= '<label class="ojp-check"><input type="checkbox" name="footer_visible[]" value="'
                . OperationsHtml::escape($path) . '"' . ($site->footerLinkVisible($path) ? ' checked' : '') . '>'
                . '<span>' . OperationsHtml::escape($label) . '</span></label>';
        }
        return $html . '</div><p class="ojp-field-help">하단 링크만 숨깁니다. 문서 주소와 동의 절차는 유지됩니다. 공개가 필요한 정책과 라이선스 고지는 다른 접근 경로도 확인하세요.</p></fieldset>';
    }

    /**
     * @param list<array{label: string, url: string}> $links
     */
    private function footerLinkFields(array $links): string
    {
        $html = '';
        for ($index = 0; $index < 6; ++$index) {
            $link = $links[$index] ?? ['label' => '', 'url' => ''];
            $number = $index + 1;
            $html .= '<div class="ojp-footer-link-row">'
                . '<label class="ojp-field"><span class="ojp-label">추가 링크 '
                . $number . ' 이름</span><input class="ojp-input" name="footer_link_label[]"'
                . ' maxlength="50" value="' . OperationsHtml::escape($link['label'])
                . '" placeholder="예: 학교 홈페이지"></label>'
                . '<label class="ojp-field"><span class="ojp-label">추가 링크 '
                . $number . ' 주소</span><input class="ojp-input" name="footer_link_url[]"'
                . ' maxlength="2048" value="' . OperationsHtml::escape($link['url'])
                . '" placeholder="/help 또는 https://example.com"></label></div>';
        }

        return $html;
    }
}
