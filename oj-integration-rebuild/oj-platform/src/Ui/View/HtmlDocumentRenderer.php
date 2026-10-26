<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\View;

use Closure;
use CodingPanda\OjPlatform\Ui\Asset\UiAssetLocator;
use CodingPanda\OjPlatform\Kernel\Addon\AddonDocumentExtensions;

final class HtmlDocumentRenderer
{
    /** @var string|Closure(): string */
    private readonly string|Closure $siteName;

    /** @param string|Closure(): string $siteName */
    public function __construct(
        private readonly UiAssetLocator $assets,
        string|Closure $siteName = 'OJ Platform',
        private readonly ?AddonDocumentExtensions $addonExtensions = null
    ) {
        $this->siteName = $siteName;
    }

    public function render(
        string $title,
        string $body,
        string $bodyClass = '',
        int $actorUserId = 0
    ): string {
        $siteName = $this->resolvedSiteName();
        $pageTitle = $title === ''
            ? $siteName
            : $title . ' | ' . $siteName;

        // data-ojp-scheme: auto follows the OS; a saved light/dark choice is
        // applied before first paint so the page never flashes the other scheme.
        return '<!doctype html>'
            . '<html lang="ko" data-ojp-scheme="auto"><head>'
            . '<meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<meta name="color-scheme" content="light dark">'
            . '<script>(function(){try{var s=window.localStorage.getItem("ojp-color-scheme");'
            . 'if(s==="light"||s==="dark"){document.documentElement.setAttribute("data-ojp-scheme",s);}'
            . '}catch(e){}})();</script>'
            . '<title>' . $this->escape($pageTitle) . '</title>'
            . '<link rel="icon" type="image/svg+xml" href="'
            . $this->escape($this->assets->faviconUrl()) . '">'
            . '<link rel="stylesheet" href="' . $this->escape($this->assets->stylesheetUrl()) . '">'
            . ($this->addonExtensions?->head($actorUserId) ?? '')
            . '</head><body class="' . $this->escape($bodyClass) . '">'
            . $body
            . ($this->addonExtensions?->footer($actorUserId) ?? '')
            . '<script src="' . $this->escape($this->assets->scriptUrl()) . '" defer></script>'
            . '<script src="' . $this->escape($this->assets->mathJaxLoaderUrl())
            . '" defer></script>'
            . '</body></html>';
    }

    public function icon(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('아이콘 이름이 올바르지 않습니다.');
        }

        return '<svg class="ojp-icon" aria-hidden="true"><use href="'
            . $this->escape($this->assets->iconSpriteUrl() . '#' . $name)
            . '"></use></svg>';
    }

    private function resolvedSiteName(): string
    {
        $siteName = $this->siteName instanceof Closure
            ? ($this->siteName)()
            : $this->siteName;
        $siteName = trim($siteName);

        return $siteName !== '' ? $siteName : 'OJ Platform';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
