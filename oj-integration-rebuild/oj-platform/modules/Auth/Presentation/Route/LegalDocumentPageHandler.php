<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementRepository;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Ui\Editor\RichTextContent;

final class LegalDocumentPageHandler implements RouteHandler
{
    public function __construct(
        private readonly string $typeSlug,
        private readonly string $fallbackTitle,
        private readonly PageResponder $pages,
        private readonly AgreementRepository $agreements,
        private readonly ?\CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider $accounts = null
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);

        $document = $this->agreements->activeDocumentByType($this->typeSlug);
        if ($document === null) {
            return $this->pages->guest(
                $this->fallbackTitle,
                '<section class="ojp-policy-document"><h1>'
                . $this->escape($this->fallbackTitle)
                . '</h1><p>현재 게시된 문서가 없습니다.</p></section>',
                $request,
                404
            );
        }

        $content = '<article class="ojp-policy-document"><header><h1>'
            . $this->escape($document->title())
            . '</h1><p>버전 ' . $this->escape($document->version())
            . '</p></header><div class="ojp-policy-content ojp-prose">'
            . (new RichTextContent())->render($document->content())
            . '</div></article>';

        return $this->accounts?->current()->identity() !== null
            ? $this->pages->authenticated($document->title(), $content, $request)
            : $this->pages->guest($document->title(), $content, $request);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
