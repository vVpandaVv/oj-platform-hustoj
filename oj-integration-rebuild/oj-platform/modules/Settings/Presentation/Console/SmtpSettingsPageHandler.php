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
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Settings\Application\SmtpSettingsService;

final class SmtpSettingsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly SmtpSettingsService $settings,
        private readonly CurrentAccountProvider $accounts,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $snapshot = $this->settings->snapshot();
        $current = $this->settings->current();
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $proof = $userId > 0 ? $this->settings->proofFor($userId) : null;
        $host = $current?->host() ?? '';
        $port = $current?->port() ?? 587;
        $encryption = $current?->encryption() ?? 'starttls';
        $username = $current?->username() ?? '';
        $fromAddress = $current?->fromAddress() ?? '';
        $fromName = $current?->fromName() ?? '';
        $enabled = $current?->enabled() ?? false;
        $authentication = $current?->authentication() ?? true;
        $proofPanel = $proof === null
            ? '<div class="ojp-notice ojp-notice--info"><strong>후보 검사 필요</strong>'
                . '<p>후보 설정으로 시험 메일을 보낸 뒤 같은 후보를 적용할 수 있습니다.</p></div>'
            : '<div class="ojp-notice ojp-notice--success"><strong>시험 메일 성공</strong>'
                . '<p>검사 결과는 15분 동안 유효합니다.</p></div>'
                . '<form method="post" action="'
                . OperationsHtml::escape($this->actionUrls->actionUrl()) . '">'
                . $this->security->hiddenFields('oj_save_smtp_settings')
                . '<input type="hidden" name="expected_revision" value="'
                . $snapshot->revision() . '">'
                . '<button class="ojp-button ojp-button--primary" type="submit">검사한 후보 적용</button>'
                . '</form>';
        $content = OperationsHtml::header(
            '후보 시험 메일 후 적용',
            '메일 발송',
            '시험 실패 시 현재 운영 설정은 바뀌지 않습니다.'
        )
            . '<div class="ojp-two-column"><section class="ojp-section">'
            . '<form class="ojp-form-stack" method="post" action="'
            . OperationsHtml::escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_send_smtp_test_mail')
            . '<label class="ojp-check"><input type="checkbox" name="enabled" value="1"'
            . OperationsHtml::checked($enabled) . '><span>SMTP 발송 사용</span></label>'
            . '<div class="ojp-form-grid">'
            . $this->field('host', 'SMTP 호스트', $host, 'text', true)
            . $this->field('port', '포트', (string) $port, 'number', true)
            . '<label class="ojp-field"><span class="ojp-label">암호화</span>'
            . '<select class="ojp-select" name="encryption">'
            . '<option value="starttls"' . OperationsHtml::selected($encryption, 'starttls')
            . '>STARTTLS</option><option value="smtps"'
            . OperationsHtml::selected($encryption, 'smtps') . '>SMTPS</option></select></label>'
            . '<label class="ojp-check"><input type="checkbox" name="authentication" value="1"'
            . OperationsHtml::checked($authentication) . '><span>SMTP 인증 사용</span></label>'
            . $this->field('username', '계정', $username)
            . $this->field('password', '비밀번호', '', 'password')
            . $this->field('from_address', '발신 주소', $fromAddress, 'email', true)
            . $this->field('from_name', '발신 이름', $fromName, 'text', true)
            . $this->field('test_recipient', '시험 수신 주소', '', 'email', true)
            . '</div><p class="ojp-field-help">비밀번호를 비우면 현재 저장된 값을 사용합니다.</p>'
            . '<button class="ojp-button" type="submit">시험 메일 보내기</button></form>'
            . '</section><aside class="ojp-section">' . $proofPanel
            . '</aside></div>';

        return $this->pages->respond(
            '메일 발송',
            $content,
            'operations.smtp',
            $request
        );
    }

    private function field(
        string $name,
        string $label,
        string $value,
        string $type = 'text',
        bool $required = false
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">'
            . OperationsHtml::escape($label) . '</span><input class="ojp-input" type="'
            . OperationsHtml::escape($type) . '" name="' . OperationsHtml::escape($name)
            . '" value="' . OperationsHtml::escape($value) . '"'
            . ($required ? ' required' : '') . '></label>';
    }
}
