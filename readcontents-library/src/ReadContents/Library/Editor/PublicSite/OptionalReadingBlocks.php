<?php

declare(strict_types=1);

namespace ReadContents\Library\Editor\PublicSite;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use ReadContents\Library\Addon\AddonRoutes;
use ReadContents\Library\Editor\Content\ContentAccessPolicy;
use ReadContents\Library\Editor\Storage\ContentRepository;

final class OptionalReadingBlocks
{
    public static function forShortcode(array $blocks): array
    {
        // Shortcodes run outside the authenticated add-on page dispatcher.
        $can_view = class_exists(AddonCapabilityKeyV1::class)
            && did_action('oj_platform_register_addons')
            && is_user_logged_in()
            && current_user_can(AddonCapabilityKeyV1::wordpress(AddonRoutes::CAPABILITY_VIEW));

        // The link points into the add-on's tree, not the legacy shortcode's tree.
        return self::forReader($blocks, new ContentRepository(), $can_view);
    }

    public static function forReader(array $blocks, ContentRepository $repository, bool $can_view): array
    {
        $policy = new ContentAccessPolicy($repository->getNode(...));
        $readable = [];
        $can_read = static function (string $slug) use (&$readable, $can_view, $policy, $repository): bool {
            return $readable[$slug] ??= $can_view && $policy->canReadLesson($repository->getNodeBySlug($slug));
        };
        $result = [];
        for ($i = 0, $count = count($blocks); $i < $count; ++$i) {
            $slug = self::destination($blocks[$i], $blocks[$i + 1] ?? null, $blocks[$i + 2] ?? null);
            if ($slug !== null) {
                if (! $can_read($slug)) {
                    $i += 2;
                    continue;
                }
            }
            $result[] = $blocks[$i];
        }
        return self::filterLinks($result, $can_read);
    }

    private static function filterLinks(array $nodes, callable $can_read): array
    {
        $result = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) { $result[] = $node; continue; }
            $children = $node['children'] ?? null;
            // Remove standalone recommendations; keep surrounding prose intact.
            if (($node['type'] ?? null) === 'p' && is_array($children)) {
                $nonempty = array_values(array_filter($children, static fn ($child) => $child !== ['text' => '']));
                $slug = count($nonempty) === 1 ? self::linkSlug($nonempty[0]) : null;
                if ($slug !== null && ! $can_read($slug)) continue;
            }
            $slug = self::linkSlug($node);
            if ($slug !== null && ! $can_read($slug)) {
                array_push($result, ...self::filterLinks(is_array($children) ? $children : [], $can_read));
                continue;
            }
            if (is_array($children)) $node['children'] = self::filterLinks($children, $can_read);
            $result[] = $node;
        }
        return $result;
    }

    private static function linkSlug(mixed $node): ?string
    {
        if (! is_array($node) || ! in_array($node['type'] ?? null, ['a', 'link'], true)) return null;
        $url = parse_url(trim((string) ($node['url'] ?? $node['href'] ?? '')));
        $home = parse_url(home_url('/'));
        if (! is_array($url) || ! is_array($home) || isset($url['user']) || isset($url['pass'])) return null;
        if (isset($url['host']) || isset($url['scheme'])) {
            $scheme = strtolower($url['scheme'] ?? $home['scheme'] ?? 'https');
            $home_scheme = strtolower($home['scheme'] ?? 'https');
            if (! in_array($scheme, ['http', 'https'], true) || $scheme !== $home_scheme
                || strtolower($url['host'] ?? '') !== strtolower($home['host'] ?? '')
                || ($url['port'] ?? ($scheme === 'https' ? 443 : 80)) !== ($home['port'] ?? ($home_scheme === 'https' ? 443 : 80))) return null;
            $prefixes = [rtrim($home['path'] ?? '', '/') . AddonRoutes::BASE_PATH . '/'];
        } else {
            $prefixes = [AddonRoutes::BASE_PATH . '/', rtrim($home['path'] ?? '', '/') . AddonRoutes::BASE_PATH . '/'];
        }
        $path = rtrim($url['path'] ?? '', '/');
        foreach ($prefixes as $prefix) {
            if (! str_starts_with($path, $prefix)) continue;
            $slug = substr($path, strlen($prefix));
            if ($slug !== '' && ! str_contains(rawurldecode($slug), '/')) return $slug;
        }
        return null;
    }

    // Signature of the two released network linked editions. Neither a matching
    // heading alone nor an arbitrary link to a lesson identifies this group.
    private static function destination(mixed $heading, mixed $question, mixed $caption): ?string
    {
        if (($heading['type'] ?? null) !== 'h2' || self::plainText($heading) !== '선택 심화 읽기'
            || ($question['type'] ?? null) !== 'p' || ($caption['type'] ?? null) !== 'p'
            || ! preg_match('/^([DT][0-9]{2}) .+ · 새 탭에서 열립니다\.$/uD', self::plainText($caption), $match)
            || ! is_array($question['children'] ?? null)) {
            return null;
        }

        // Slate can insert empty text leaves on either side of an inline link.
        $links = array_values(array_filter($question['children'], static fn ($child) => $child !== ['text' => '']));
        $link = $links[0] ?? null;
        if (count($links) !== 1 || ($link['type'] ?? null) !== 'a' || ($link['target'] ?? null) !== '_blank'
            || trim(self::plainText($link)) === '') {
            return null;
        }

        $code = $match[1];
        $book = $code[0] === 'D' ? 'network-data-communications-advanced' : 'network-tcp-ip-analysis';
        $slug = $book . '-' . strtolower($code);
        return ($link['url'] ?? null) === AddonRoutes::topicPath($slug) ? $slug : null;
    }

    private static function plainText(array $node): string
    {
        $text = '';
        foreach ((array) ($node['children'] ?? []) as $leaf) {
            if (! is_string($leaf['text'] ?? null) || isset($leaf['type']) || isset($leaf['children'])) {
                return '';
            }
            $text .= $leaf['text'];
        }
        return $text;
    }
}
