<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Application\GuardianConsentRequestService;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianRelationship;
use CodingPanda\OjPlatform\Ui\Editor\RichTextContent;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;

final class GuardianConsentPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly GuardianConsentRequestService $requests
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $token = $route->parameters()['token'] ?? '';
        $review = preg_match('/^[a-f0-9]{64}$/', $token) === 1
            ? $this->requests->pendingReview($token) : null;
        if ($review === null) {
            return $this->privateResponse($this->pages->guest(
                '법정대리인 동의',
                '<div class="ojp-empty-state"><p>확인 주소가 만료되었거나 동의 문서가 변경되었습니다. 가입 화면에서 새 동의 요청을 보내 주세요.</p></div>',
                $request,
                404
            ));
        }

        $document = $review->document;
        $relationships = '<option value="" selected disabled>관계를 선택하세요</option>';
        foreach (GuardianRelationship::cases() as $relationship) {
            $relationships .= '<option value="' . $relationship->value . '">'
                . $this->escape($relationship->label()) . '</option>';
        }
        $content = '<section class="ojp-auth-panel ojp-auth-panel--wide"><header><h1>법정대리인 동의</h1>'
            . '<p>가입 신청자: ' . $this->escape($review->childEmail) . '</p>'
            . '<p>법정대리인: ' . $this->escape($review->guardianName)
            . ' (' . $this->escape($review->guardianEmail) . ')</p></header>'
            . '<article class="ojp-section"><h2>' . $this->escape($document->title())
            . '</h2><p>버전 ' . $this->escape($document->version()) . '</p>'
            . '<div class="ojp-policy-content ojp-prose">'
            . (new RichTextContent())->render($document->content()) . '</div></article>'
            . '<form class="ojp-form-stack ojp-section" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_guardian_consent')
            . '<input type="hidden" name="token" value="' . $this->escape($token) . '">'
            . '<input type="hidden" name="document_id" value="' . $document->documentId() . '">'
            . '<label class="ojp-field"><span class="ojp-label">가입 신청자와의 관계</span>'
            . '<select class="ojp-select" name="guardian_relationship" required>'
            . $relationships . '</select></label>'
            . '<label class="ojp-check"><input type="checkbox" name="consent" value="1" required>'
            . '<span>본인은 위 가입 신청자의 법정대리인이며, 위 문서의 내용을 확인하고 동의합니다.</span></label>'
            . '<button class="ojp-button ojp-button--primary" type="submit">동의 확인</button>'
            . '</form></section>';

        return $this->privateResponse($this->pages->guest('법정대리인 동의', $content, $request));
    }

    private function privateResponse(HttpResponse $response): HttpResponse
    {
        return new HttpResponse($response->status(), array_replace($response->headers(), [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]), $response->body());
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
