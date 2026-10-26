<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonPageResponseV1
{
    /** @param array<string, string> $parameters */
    private function __construct(
        private readonly string $kind,
        private readonly ?string $title,
        private readonly ?string $body,
        private readonly int $status,
        private readonly ?string $route,
        private readonly array $parameters,
        private readonly ?string $message,
        private readonly ?string $code,
        private readonly string $layout = 'standard',
        private readonly bool $supportsDarkMode = false
    ) {
    }

    public static function html(string $title, string $body, int $status = 200): self
    {
        ValueValidator::text($title, 'title', 200);
        ValueValidator::text($body, 'body', 2097152, true, true);
        if (strlen($body) > 2097152 || ! in_array($status, [200, 403, 404, 409, 422, 503], true)) {
            throw new InvalidArgumentException('Addon API 페이지 응답이 올바르지 않습니다.');
        }

        return new self('html', $title, $body, $status, null, [], null, null);
    }

    /**
     * Addon API 1.3. layout: standard (shell width), wide (full content
     * width) or immersive (sidebar folded away: editors, notebooks, games).
     * supportsDarkMode: true only when the page's own CSS follows the
     * --ojp-* tokens; otherwise the platform keeps the page light.
     */
    public static function page(
        string $title,
        string $body,
        string $layout = 'standard',
        bool $supportsDarkMode = false,
        int $status = 200
    ): self {
        $page = self::html($title, $body, $status);
        if (! in_array($layout, ['standard', 'wide', 'immersive'], true)) {
            throw new InvalidArgumentException('Addon API 페이지 레이아웃이 올바르지 않습니다.');
        }

        return new self('html', $page->title, $page->body, $page->status, null, [], null, null, $layout, $supportsDarkMode);
    }

    /** @param array<string, string> $parameters */
    public static function redirect(string $route, array $parameters = []): self
    {
        ValueValidator::simpleKey($route, 'route');
        ValueValidator::routeParameterMap($parameters, 'parameters');

        return new self('redirect', null, null, 302, $route, $parameters, null, null);
    }

    public static function unavailable(string $message, string $code): self
    {
        ValueValidator::text($message, 'message', 500);
        ValueValidator::errorCode($code);

        return new self('unavailable', null, null, 503, null, [], $message, $code);
    }

    public function kind(): string { return $this->kind; }
    public function title(): ?string { return $this->title; }
    public function body(): ?string { return $this->body; }
    public function status(): int { return $this->status; }
    public function route(): ?string { return $this->route; }
    /** @return array<string, string> */
    public function parameters(): array { return $this->parameters; }
    public function message(): ?string { return $this->message; }
    public function code(): ?string { return $this->code; }
    public function layout(): string { return $this->layout; }
    public function supportsDarkMode(): bool { return $this->supportsDarkMode; }
}
