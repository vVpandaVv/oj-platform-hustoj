<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteMatcher;
use DOMDocument;
use DOMElement;

final class WordPressRouteTransport
{
    public const QUERY_VAR = 'oj_route';

    private ?string $legacyQueryPath = null;

    /** @var array<string, mixed> */
    private array $legacyQueryParameters = [];

    public function __construct(private readonly RouteMatcher $routes)
    {
    }

    /** @param array<string, mixed> $query */
    public function requestPath(string $requestUri, array $query): string
    {
        $this->legacyQueryPath = null;
        $this->legacyQueryParameters = [];
        $fallbackPath = $query[self::QUERY_VAR] ?? null;
        $isLegacyQueryRequest = is_scalar($fallbackPath)
            && (string) $fallbackPath !== '';
        if (! is_scalar($fallbackPath) && function_exists('get_query_var')) {
            $fallbackPath = get_query_var(self::QUERY_VAR, null);
        }

        if (is_scalar($fallbackPath) && (string) $fallbackPath !== '') {
            $path = $this->normalizeRoutePath((string) $fallbackPath);
            if ($isLegacyQueryRequest && $path !== '/_oj_invalid_route_') {
                unset($query[self::QUERY_VAR]);
                $this->legacyQueryPath = $path;
                $this->legacyQueryParameters = $query;
            }

            return $path;
        }

        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $homePath = rtrim($this->homePath(), '/');
        if (
            $homePath !== ''
            && ($path === $homePath || str_starts_with($path, $homePath . '/'))
        ) {
            $path = substr($path, strlen($homePath)) ?: '/';
        }

        return $this->normalizeRoutePath($path);
    }

    public function routeUrl(string $url): string
    {
        if ($url === '' || ! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return $url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
            return $url;
        }

        $path = $parts['path'] ?? null;
        if (! is_string($path) || $this->routes->match('GET', $path) === null) {
            return $url;
        }

        $query = isset($parts['query']) && is_string($parts['query'])
            ? $parts['query']
            : '';
        if ($this->containsRouteQuery($query)) {
            return $url;
        }

        $fragment = isset($parts['fragment']) && is_string($parts['fragment'])
            ? '#' . $parts['fragment']
            : '';

        $homePath = rtrim($this->homePath(), '/');
        $directPath = $path === '/'
            ? ($homePath === '' ? '/' : $homePath . '/')
            : $homePath . $path;

        return $directPath
            . ($query === '' ? '' : '?' . $query)
            . $fragment;
    }

    public function canonicalRedirectUrl(): ?string
    {
        if ($this->legacyQueryPath === null) {
            return null;
        }

        $query = http_build_query(
            $this->legacyQueryParameters,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        return $this->routeUrl(
            $this->legacyQueryPath . ($query === '' ? '' : '?' . $query)
        );
    }

    public function rewriteHtml(string $html): string
    {
        if (
            trim($html) === ''
            || $this->homePath() === '/'
        ) {
            return $html;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
            if (! $loaded) {
                return $html;
            }

            foreach ($document->childNodes as $child) {
                if ($child->nodeType === XML_PI_NODE) {
                    $document->removeChild($child);
                    break;
                }
            }

            foreach ($document->getElementsByTagName('*') as $element) {
                if (! $element instanceof DOMElement || ! $element->hasAttributes()) {
                    continue;
                }

                $attributeNames = [];
                foreach ($element->attributes as $attribute) {
                    $attributeNames[] = $attribute->name;
                }

                foreach ($attributeNames as $attributeName) {
                    $value = $element->getAttribute($attributeName);
                    $routeUrl = $this->routeUrl($value);
                    if ($routeUrl !== $value) {
                        $element->setAttribute($attributeName, $routeUrl);
                    }
                }
            }

            $rewritten = $document->saveHTML();

            return is_string($rewritten) && $rewritten !== '' ? $rewritten : $html;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function normalizeRoutePath(string $path): string
    {
        if (
            strlen($path) > 2048
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_contains($path, '?')
            || str_contains($path, '#')
        ) {
            return '/_oj_invalid_route_';
        }

        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return str_contains($path, '//') ? '/_oj_invalid_route_' : $path;
    }

    private function homePath(): string
    {
        $home = function_exists('home_url') ? home_url('/') : '/';
        $path = parse_url((string) $home, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        return '/' . trim($path, '/') . ($path === '/' ? '' : '/');
    }

    private function containsRouteQuery(string $query): bool
    {
        if ($query === '') {
            return false;
        }

        parse_str($query, $values);

        return array_key_exists(self::QUERY_VAR, $values);
    }
}
