<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Domain;

use InvalidArgumentException;

final class SiteSettings
{
    public const FOOTER_LINKS = \CodingPanda\OjPlatform\Modules\Settings\Contract\SiteFooterVisibilityProvider::LINKS;

    /** @var list<array{label: string, url: string}> */
    private readonly array $footerLinks;

    /**
     * @param list<array{label: string, url: string}> $footerLinks
     */
    public function __construct(
        private readonly string $serviceName,
        private readonly string $shortDescription,
        private readonly string $supportLine,
        private readonly string $copyrightNotice = '',
        private readonly string $administratorEmail = '',
        array $footerLinks = [],
        private readonly array $footerVisibility = []
    ) {
        $this->assertText($this->serviceName, 40, '서비스 이름');
        $this->assertText($this->shortDescription, 100, '짧은 설명', true);
        $this->assertText($this->supportLine, 60, '운영 연락 표시', true);
        $this->assertText($this->copyrightNotice, 160, '저작권 표시', true);
        $this->assertEmail($this->administratorEmail);
        $this->footerLinks = $this->normalizeFooterLinks($footerLinks);
        foreach ($this->footerVisibility as $path => $visible) {
            if (! isset(self::FOOTER_LINKS[$path]) || ! is_bool($visible)) {
                throw new InvalidArgumentException('하단 링크 표시 설정이 올바르지 않습니다.');
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        return new self(
            trim((string) ($value['service_name'] ?? 'OJ Platform')),
            trim((string) ($value['short_description'] ?? '학습용 온라인 채점 시스템')),
            trim((string) ($value['support_line'] ?? '')),
            trim((string) ($value['copyright_notice'] ?? '')),
            trim((string) ($value['administrator_email'] ?? '')),
            is_array($value['footer_links'] ?? null)
                ? array_values($value['footer_links'])
                : [],
            is_array($value['footer_visibility'] ?? null) ? $value['footer_visibility'] : []
        );
    }

    public function serviceName(): string
    {
        return $this->serviceName;
    }

    public function shortDescription(): string
    {
        return $this->shortDescription;
    }

    public function supportLine(): string
    {
        return $this->supportLine;
    }

    public function copyrightNotice(): string
    {
        return $this->copyrightNotice;
    }

    public function administratorEmail(): string
    {
        return $this->administratorEmail;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    public function footerLinks(): array
    {
        return $this->footerLinks;
    }

    public function footerLinkVisible(string $path): bool
    {
        return $this->footerVisibility[$path] ?? true;
    }

    /**
     * @return array{
     *   service_name: string,
     *   short_description: string,
     *   support_line: string,
     *   copyright_notice: string,
     *   administrator_email: string,
     *   footer_links: list<array{label: string, url: string}>,
     *   footer_visibility: array<string, bool>
     * }
     */
    public function toArray(): array
    {
        return [
            'service_name' => $this->serviceName,
            'short_description' => $this->shortDescription,
            'support_line' => $this->supportLine,
            'copyright_notice' => $this->copyrightNotice,
            'administrator_email' => $this->administratorEmail,
            'footer_links' => $this->footerLinks,
            'footer_visibility' => $this->footerVisibility,
        ];
    }

    private function assertEmail(string $email): void
    {
        if ($email === '') {
            return;
        }
        $this->assertText($email, 254, '관리자 이메일');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('관리자 이메일 형식이 올바르지 않습니다.');
        }
    }

    /**
     * @param list<mixed> $links
     * @return list<array{label: string, url: string}>
     */
    private function normalizeFooterLinks(array $links): array
    {
        if (count($links) > 6) {
            throw new InvalidArgumentException('추가 푸터 링크는 최대 6개까지 저장할 수 있습니다.');
        }

        $normalized = [];
        $seen = [];
        foreach ($links as $link) {
            if (! is_array($link)) {
                throw new InvalidArgumentException('푸터 링크 형식이 올바르지 않습니다.');
            }
            $label = trim(is_scalar($link['label'] ?? null) ? (string) $link['label'] : '');
            $url = trim(is_scalar($link['url'] ?? null) ? (string) $link['url'] : '');
            if ($label === '' && $url === '') {
                continue;
            }
            $this->assertText($label, 50, '푸터 링크 이름');
            $this->assertFooterUrl($url);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $normalized[] = ['label' => $label, 'url' => $url];
        }

        return $normalized;
    }

    private function assertFooterUrl(string $url): void
    {
        $this->assertText($url, 2048, '푸터 링크 주소');
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $url) === 1) {
            throw new InvalidArgumentException('푸터 링크 주소 형식이 올바르지 않습니다.');
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            throw new InvalidArgumentException('푸터 링크는 /로 시작하는 내부 주소 또는 HTTP(S) 주소여야 합니다.');
        }
    }

    private function assertText(
        string $value,
        int $maximum,
        string $label,
        bool $allowEmpty = false
    ): void {
        $length = function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
        if (
            (! $allowEmpty && $value === '')
            || $length > $maximum
            || preg_match('//u', $value) !== 1
        ) {
            throw new InvalidArgumentException($label . ' 형식이 올바르지 않습니다.');
        }
    }
}
