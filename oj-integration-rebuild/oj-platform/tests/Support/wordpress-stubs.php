<?php

declare(strict_types=1);

$GLOBALS['oj_platform_test_actions'] = [];
$GLOBALS['oj_platform_test_filters'] = [];
$GLOBALS['oj_platform_test_menu_pages'] = [];
$GLOBALS['oj_platform_test_options'] = [];
$GLOBALS['oj_platform_test_option_writes'] = [];
$GLOBALS['oj_platform_test_query_vars'] = [];
$GLOBALS['oj_platform_test_rewrite_rules'] = [];
$GLOBALS['oj_platform_test_rewrite_flushes'] = 0;

if (! function_exists('add_action')) {
    function add_action(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $acceptedArguments = 1
    ): bool {
        $GLOBALS['oj_platform_test_actions'][$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_arguments' => $acceptedArguments,
        ];

        return true;
    }
}

if (! function_exists('add_filter')) {
    function add_filter(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $acceptedArguments = 1
    ): bool {
        $GLOBALS['oj_platform_test_filters'][$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_arguments' => $acceptedArguments,
        ];

        return true;
    }
}

if (! function_exists('add_submenu_page')) {
    function add_submenu_page(string $parent, string $pageTitle, string $menuTitle, string $capability, string $slug, mixed $callback = ''): string
    {
        $GLOBALS['oj_platform_test_submenu_pages'][] = compact('parent', 'pageTitle', 'menuTitle', 'capability', 'slug', 'callback');
        return $parent . '_page_' . $slug;
    }
}

if (! function_exists('add_menu_page')) {
    function add_menu_page(
        string $pageTitle,
        string $menuTitle,
        string $capability,
        string $menuSlug,
        mixed $callback = '',
        string $iconUrl = '',
        int|float|null $position = null
    ): string {
        $GLOBALS['oj_platform_test_menu_pages'][] = [
            'page_title' => $pageTitle,
            'menu_title' => $menuTitle,
            'capability' => $capability,
            'menu_slug' => $menuSlug,
            'callback' => $callback,
            'icon_url' => $iconUrl,
            'position' => $position,
        ];

        return 'toplevel_page_' . $menuSlug;
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return $capability === 'manage_options';
    }
}

if (! function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return (bool) ($GLOBALS['oj_platform_test_multisite'] ?? false);
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('wp_kses_post')) {
    function wp_kses_post(string $value): string
    {
        return ojPlatformTestKsesPost($value);
    }
}

if (! function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['oj_platform_test_options'][$name] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $name, mixed $value, mixed $autoload = null): bool
    {
        $GLOBALS['oj_platform_test_options'][$name] = $value;
        $GLOBALS['oj_platform_test_option_writes'][] = ['update', $name];

        return true;
    }
}

if (! function_exists('add_option')) {
    function add_option(
        string $name,
        mixed $value = '',
        string $deprecated = '',
        mixed $autoload = 'yes'
    ): bool {
        if (array_key_exists($name, $GLOBALS['oj_platform_test_options'])) {
            return false;
        }

        $GLOBALS['oj_platform_test_options'][$name] = $value;
        $GLOBALS['oj_platform_test_option_writes'][] = ['add', $name];

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        if (! array_key_exists($name, $GLOBALS['oj_platform_test_options'])) {
            return false;
        }

        unset($GLOBALS['oj_platform_test_options'][$name]);
        $GLOBALS['oj_platform_test_option_writes'][] = ['delete', $name];

        return true;
    }
}

if (! function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://example.test/' . ltrim($path, '/');
    }
}

if (! function_exists('get_query_var')) {
    function get_query_var(string $name, mixed $default = ''): mixed
    {
        return $GLOBALS['oj_platform_test_query_vars'][$name] ?? $default;
    }
}

if (! function_exists('add_rewrite_rule')) {
    function add_rewrite_rule(
        string $regex,
        string|array $query,
        string $after = 'bottom'
    ): void {
        $rule = ['pattern' => $regex, 'query' => $query, 'after' => $after];
        if ($after === 'top') {
            array_unshift($GLOBALS['oj_platform_test_rewrite_rules'], $rule);

            return;
        }

        $GLOBALS['oj_platform_test_rewrite_rules'][] = $rule;
    }
}

if (! function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules(bool $hard = true): void
    {
        unset($hard);
        ++$GLOBALS['oj_platform_test_rewrite_flushes'];
    }
}

/**
 * @return list<array{callback: callable, priority: int, accepted_arguments: int}>
 */
function ojPlatformTestActions(string $hook): array
{
    $actions = $GLOBALS['oj_platform_test_actions'][$hook] ?? [];
    usort(
        $actions,
        static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']
    );

    return $actions;
}

function ojPlatformTestRunActions(string $hook): void
{
    foreach (ojPlatformTestActions($hook) as $action) {
        ($action['callback'])();
    }
}

/** @return list<array{callback: callable, priority: int, accepted_arguments: int}> */
function ojPlatformTestFilters(string $hook): array
{
    $filters = $GLOBALS['oj_platform_test_filters'][$hook] ?? [];
    usort(
        $filters,
        static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']
    );

    return $filters;
}

function ojPlatformTestApplyFilters(string $hook, mixed $value): mixed
{
    foreach (ojPlatformTestFilters($hook) as $filter) {
        $value = ($filter['callback'])($value);
    }

    return $value;
}

/**
 * Minimal stand-in for WordPress's wp_kses_post(): strips script/style
 * blocks and comments outright, drops any tag not on the allow-list
 * (keeping its text content), and keeps only a small allow-listed set of
 * attributes on the tags that support them, dropping javascript: URIs.
 * Real wp_kses_post() is far more permissive; this only needs to be
 * faithful enough to exercise the platform's own sanitize-then-render path
 * in tests instead of silently skipping it.
 */
function ojPlatformTestKsesPost(string $value): string
{
    static $allowedTags = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'span', 'div',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'a', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'font',
    ];
    static $allowedAttributes = [
        'a' => ['href', 'title', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'font' => ['size'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
    ];

    $value = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $value) ?? $value;
    $value = preg_replace('/<!--.*?-->/s', '', $value) ?? $value;

    return preg_replace_callback(
        '/<(\/?)([a-zA-Z0-9]+)([^>]*)>/',
        static function (array $matches) use ($allowedTags, $allowedAttributes): string {
            $closing = $matches[1] === '/';
            $tag = strtolower($matches[2]);
            if (! in_array($tag, $allowedTags, true)) {
                return '';
            }
            if ($closing) {
                return '</' . $tag . '>';
            }

            $attributes = '';
            $allowed = $allowedAttributes[$tag] ?? [];
            if ($allowed !== [] && preg_match_all(
                '/([a-zA-Z-]+)\s*=\s*"([^"]*)"/',
                $matches[3],
                $attributeMatches,
                PREG_SET_ORDER
            ) > 0) {
                foreach ($attributeMatches as $attributeMatch) {
                    $name = strtolower($attributeMatch[1]);
                    if (! in_array($name, $allowed, true)) {
                        continue;
                    }
                    $attributeValue = $attributeMatch[2];
                    if (
                        in_array($name, ['href', 'src'], true)
                        && preg_match('#^\s*javascript:#i', $attributeValue) === 1
                    ) {
                        continue;
                    }
                    $attributes .= ' ' . $name . '="'
                        . htmlspecialchars($attributeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                        . '"';
                }
            }

            $selfClosing = $tag === 'br' || $tag === 'img';

            return '<' . $tag . $attributes . ($selfClosing ? ' /' : '') . '>';
        },
        $value
    ) ?? $value;
}
