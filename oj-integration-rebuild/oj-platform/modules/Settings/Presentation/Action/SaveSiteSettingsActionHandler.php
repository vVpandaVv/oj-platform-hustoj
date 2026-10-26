<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Settings\Application\SiteSettingsService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class SaveSiteSettingsActionHandler implements ActionHandler
{
    public function __construct(
        private readonly SiteSettingsService $settings,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $userId = 0;
        try {
            $actor = $this->guard->actor(
                'oj_save_site_settings',
                $request,
                'oj_manage_settings'
            );
            $userId = $actor->userId();
            $this->settings->save(
                $request->bodyString('service_name'),
                $request->bodyString('short_description'),
                $request->bodyString('support_line'),
                $request->bodyString('copyright_notice'),
                $request->bodyString('administrator_email'),
                $this->footerLinks($request),
                $request->bodyInt('expected_revision'),
                $this->footerVisibility($request)
            );

            return $this->responses->redirect(
                '/oj-console/settings/site',
                new UiMessage(
                    'site.saved',
                    MessageTone::Success,
                    '사이트 설정 저장 완료',
                    '사용자 화면과 관리자 화면에 새 표시 문구를 적용합니다.'
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/settings/site',
                new UiMessage(
                    'site.failed',
                    MessageTone::Danger,
                    '사이트 설정 저장 실패',
                    $error instanceof \InvalidArgumentException
                        ? $error->getMessage()
                        : '화면을 새로 고친 뒤 다시 저장해 주세요.'
                ),
                $userId
            );
        }
    }

    private function footerVisibility(HttpRequest $request): ?array
    {
        if ($request->bodyString('footer_visibility_present') !== '1') {
            return null;
        }
        $body = $request->body();
        $selected = $body['footer_visible'] ?? [];
        if (! is_array($selected)) {
            throw new \InvalidArgumentException('하단 링크 표시 설정이 올바르지 않습니다.');
        }
        foreach ($selected as $path) {
            if (! is_string($path) || ! isset(\CodingPanda\OjPlatform\Modules\Settings\Domain\SiteSettings::FOOTER_LINKS[$path])) {
                throw new \InvalidArgumentException('하단 링크 표시 설정이 올바르지 않습니다.');
            }
        }
        $result = [];
        foreach (\CodingPanda\OjPlatform\Modules\Settings\Domain\SiteSettings::FOOTER_LINKS as $path => $label) {
            $result[$path] = in_array($path, $selected, true);
        }
        return $result;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function footerLinks(HttpRequest $request): array
    {
        $body = $request->body();
        $labels = is_array($body['footer_link_label'] ?? null)
            ? array_values($body['footer_link_label'])
            : [];
        $urls = is_array($body['footer_link_url'] ?? null)
            ? array_values($body['footer_link_url'])
            : [];
        $links = [];
        $count = min(6, max(count($labels), count($urls)));
        for ($index = 0; $index < $count; ++$index) {
            $label = $labels[$index] ?? '';
            $url = $urls[$index] ?? '';
            $links[] = [
                'label' => is_scalar($label) ? (string) $label : '',
                'url' => is_scalar($url) ? (string) $url : '',
            ];
        }

        return $links;
    }
}
