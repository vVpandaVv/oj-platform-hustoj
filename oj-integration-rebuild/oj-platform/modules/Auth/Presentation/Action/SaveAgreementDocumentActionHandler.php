<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementDocumentType;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use CodingPanda\OjPlatform\Ui\Editor\RichTextContent;
use Throwable;
use InvalidArgumentException;

final class SaveAgreementDocumentActionHandler implements ActionHandler
{
    private const ACTION = 'oj_save_agreement_document';

    public function __construct(
        private readonly AgreementRepository $agreements,
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
                'oj_manage_agreements'
            );
            $actorUserId = $actor->userId();
            $type = AgreementDocumentType::tryFrom(trim($request->bodyString('type_slug')));
            if ($type === null) {
                throw new InvalidArgumentException('게시할 문서 종류를 선택해 주세요.');
            }
            $title = trim($request->bodyString('title'));
            $content = (new RichTextContent())->forStorage($request->bodyString('content'));
            $bodyText = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (trim(str_replace("\u{00A0}", ' ', $bodyText)) === '') {
                throw new InvalidArgumentException('문서 본문을 입력해 주세요.');
            }
            $this->agreements->publish(
                $type->value,
                $type->label(),
                trim($request->bodyString('version')),
                $title !== '' ? $title : $type->label(),
                $content,
                ! $type->isGuardianOnly() && $request->bodyBool('required'),
                $actorUserId
            );

            return $this->responses->redirect(
                '/oj-console/agreements',
                new UiMessage(
                    'auth.agreement_published',
                    MessageTone::Success,
                    '문서 게시 완료',
                    '새 문서 버전을 게시했습니다.'
                ),
                $actorUserId
            );
        } catch (Throwable) {
            return $this->responses->redirect(
                '/oj-console/agreements',
                new UiMessage(
                    'auth.agreement_publish_failed',
                    MessageTone::Danger,
                    '문서 게시 실패',
                    '문서 종류, 버전과 본문을 다시 확인해 주세요.'
                ),
                $actorUserId
            );
        }
    }
}
