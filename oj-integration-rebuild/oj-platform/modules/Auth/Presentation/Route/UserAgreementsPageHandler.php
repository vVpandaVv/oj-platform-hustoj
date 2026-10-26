<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementRepository;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;

final class UserAgreementsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly AgreementRepository $agreements,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);

        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $agreed = array_fill_keys($this->agreements->agreedDocumentIds($userId), true);
        $rows = '';
        $bodies = '';
        $documents = $this->agreements->activeDocuments();
        $needsAgreement = [];
        foreach ($documents as $document) {
            if (! $document->isForUserConsent()) {
                continue;
            }
            $rows .= '<tr><td><a href="#agreement-document-' . $document->documentId() . '">' . $this->escape($document->title()) . '</a>'
                . '</td><td>' . $this->escape($document->version())
                . '</td><td><span class="ojp-status-badge '
                . (isset($agreed[$document->documentId()])
                    ? 'ojp-status-badge--success">동의'
                    : ($document->isRequired() ? 'ojp-status-badge--warning">확인 필요' : '">선택 동의'))
                . '</span></td></tr>';
            $type = \CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementDocumentType::tryFrom($document->typeSlug());
            $bodies .= '<section class="ojp-section" id="agreement-document-' . $document->documentId() . '">'
                . '<details' . (! isset($agreed[$document->documentId()]) ? ' open' : '') . '><summary>'
                . $this->escape($document->title()) . ' · 버전 ' . $this->escape($document->version()) . '</summary>'
                . '<div class="ojp-policy-content ojp-prose">'
                . (new \CodingPanda\OjPlatform\Ui\Editor\RichTextContent())->render($document->content()) . '</div>'
                . ($type === null ? '' : '<a href="' . $this->escape($type->publicPath()) . '">공개 문서 열기</a>')
                . '</details></section>';
            if (! isset($agreed[$document->documentId()])) {
                $needsAgreement[] = $document;
            }
        }

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">내 정보</p>'
            . '<h1>동의 현황</h1></div></header>'
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>문서</th><th>버전</th><th>상태</th></tr></thead>'
            . '<tbody>' . ($rows !== '' ? $rows : '<tr><td colspan="3">게시된 문서가 없습니다.</td></tr>')
            . '</tbody></table></div>'
            . $bodies
            . $this->agreementForm($needsAgreement);

        return $this->pages->authenticated('동의 현황', $content, $request);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param list<\CodingPanda\OjPlatform\Modules\Auth\Domain\AgreementDocument> $documents
     */
    private function agreementForm(array $documents): string
    {
        if ($documents === []) {
            return '';
        }

        $checks = '';
        foreach ($documents as $document) {
            $checks .= '<label class="ojp-check"><input type="checkbox"'
                . ' name="agreement_document_ids[]" value="' . $document->documentId() . '"'
                . ($document->isRequired() ? ' required' : '') . '><span>'
                . $this->escape($document->title()) . ($document->isRequired() ? ' (필수)' : ' (선택)')
                . '</span></label><a href="#agreement-document-' . $document->documentId() . '">본문 확인</a>';
        }

        return '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>문서 확인</h2></div></div><form class="ojp-form-stack" method="post" action="'
            . $this->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_accept_user_agreements')
            . $checks
            . '<button class="ojp-button ojp-button--primary" type="submit">동의 저장</button>'
            . '</form></section>';
    }
}
