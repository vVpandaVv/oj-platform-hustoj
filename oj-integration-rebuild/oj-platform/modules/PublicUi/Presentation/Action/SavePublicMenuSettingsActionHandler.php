<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\PublicMenuSettingsService;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\PublicMenuEntry;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class SavePublicMenuSettingsActionHandler implements ActionHandler
{
    public function __construct(
        private readonly PublicMenuSettingsService $settings,
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
                'oj_save_public_menu_settings',
                $request,
                'oj_manage_settings'
            );
            $userId = $actor->userId();
            $entries = [];
            $body = $request->body();
            $items = is_array($body['items'] ?? null) ? $body['items'] : [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $key = trim((string) ($item['key'] ?? ''));
                $label = trim((string) ($item['label'] ?? ''));
                if ($key === '' && $label === '') {
                    continue;
                }
                $type = (string) ($item['destination_type'] ?? 'internal');
                $item['route_key'] = $type === 'external'
                    ? null
                    : trim((string) ($item['route_key'] ?? ''));
                $item['external_url'] = $type === 'external'
                    ? trim((string) ($item['external_url'] ?? ''))
                    : null;
                $item['enabled'] = isset($item['enabled']);
                $entries[] = PublicMenuEntry::fromArray($item);
            }
            $this->settings->save(
                $entries,
                $request->bodyInt('expected_revision')
            );

            return $this->responses->redirect(
                '/oj-console/settings/menu',
                new UiMessage(
                    'public_menu.saved',
                    MessageTone::Success,
                    '사용자 메뉴 저장 완료',
                    '다음 사용자 화면 요청부터 새 메뉴 구성을 적용합니다.'
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/settings/menu',
                new UiMessage(
                    'public_menu.failed',
                    MessageTone::Danger,
                    '사용자 메뉴 저장 실패',
                    $error instanceof DomainException || $error instanceof InvalidArgumentException
                        ? $error->getMessage()
                        : '화면을 새로 고친 뒤 다시 저장해 주세요.'
                ),
                $userId
            );
        }
    }
}
