<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ResponseEmitter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;

final class WordPressResponseEmitter implements ResponseEmitter
{
    public function __construct(private readonly WordPressRouteTransport $transport)
    {
    }

    public function emit(HttpResponse $response, bool $headRequest = false): void
    {
        foreach ($response->headers() as $name => $value) {
            if (strtolower($name) !== 'location') {
                header($name . ': ' . $value, true);
            }
        }

        $location = $response->headers()['Location'] ?? null;
        if ($location !== null) {
            wp_safe_redirect($this->transport->routeUrl($location), $response->status());
            exit;
        }

        status_header($response->status());

        if (! $headRequest) {
            $body = $this->isHtml($response)
                ? $this->transport->rewriteHtml($response->body())
                : $response->body();
            echo $body;
        }

        exit;
    }

    private function isHtml(HttpResponse $response): bool
    {
        foreach ($response->headers() as $name => $value) {
            if (
                strtolower($name) === 'content-type'
                && str_starts_with(strtolower($value), 'text/html')
            ) {
                return true;
            }
        }

        return false;
    }
}
