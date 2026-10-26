<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RoutePatternCompiler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use RuntimeException;

final class WordPressRewriteManager
{
    private const SIGNATURE_OPTION = 'oj_platform_rewrite_signature';
    private const LOCK_OPTION = 'oj_platform_rewrite_lock';
    private const LOCK_TTL_SECONDS = 120;
    private const DEFAULT_PERMALINK_STRUCTURE = '/%postname%/';

    /** @param DefinitionRegistry<RouteDefinition> $routes */
    public function __construct(
        private readonly DefinitionRegistry $routes,
        private readonly RoutePatternCompiler $patterns
    ) {
    }

    public function registerHooks(HookRegistrar $hooks): void
    {
        if (! function_exists('add_filter')) {
            throw new RuntimeException('WordPress query var API를 사용할 수 없습니다.');
        }

        add_filter('query_vars', [$this, 'registerQueryVar']);
        $hooks->addAction('init', [$this, 'registerAndSynchronize'], 1);
    }

    /** @param list<string> $queryVars @return list<string> */
    public function registerQueryVar(array $queryVars): array
    {
        if (! in_array(WordPressRouteTransport::QUERY_VAR, $queryVars, true)) {
            $queryVars[] = WordPressRouteTransport::QUERY_VAR;
        }

        return $queryVars;
    }

    public function registerAndSynchronize(): void
    {
        $this->ensurePrettyPermalinkStructure();
        $this->registerRules();
        $this->synchronizeSignature();
    }

    public function synchronizeNow(): void
    {
        $this->registerAndSynchronize();
    }

    private function ensurePrettyPermalinkStructure(): void
    {
        if (! function_exists('get_option') || ! function_exists('update_option')) {
            throw new RuntimeException('WordPress 고유주소 설정 API를 사용할 수 없습니다.');
        }

        $current = get_option('permalink_structure', '');
        if (is_string($current) && trim($current) !== '') {
            return;
        }

        $wordpressRewrite = $GLOBALS['wp_rewrite'] ?? null;
        if (
            is_object($wordpressRewrite)
            && is_callable([$wordpressRewrite, 'set_permalink_structure'])
        ) {
            $wordpressRewrite->set_permalink_structure(
                self::DEFAULT_PERMALINK_STRUCTURE
            );
        } else {
            update_option(
                'permalink_structure',
                self::DEFAULT_PERMALINK_STRUCTURE
            );
        }

        $updated = get_option('permalink_structure', '');
        if (! is_string($updated) || trim($updated) === '') {
            throw new RuntimeException('WordPress 고유주소 설정을 준비하지 못했습니다.');
        }
    }

    private function registerRules(): void
    {
        if (! function_exists('add_rewrite_rule')) {
            throw new RuntimeException('WordPress rewrite API를 사용할 수 없습니다.');
        }

        $rules = [];
        foreach ($this->patterns->ordered(array_values($this->routes->all())) as $route) {
            $rule = $this->patterns->rewriteRule($route);
            $rules[$rule['pattern']] = $rule['query'];
        }

        foreach (array_reverse($rules, true) as $pattern => $query) {
            add_rewrite_rule($pattern, $query, 'top');
        }
    }

    private function synchronizeSignature(): void
    {
        if (
            ! function_exists('get_option')
            || ! function_exists('update_option')
            || ! function_exists('add_option')
            || ! function_exists('delete_option')
            || ! function_exists('flush_rewrite_rules')
        ) {
            throw new RuntimeException('WordPress rewrite 동기화 API를 사용할 수 없습니다.');
        }

        $signature = $this->signature();
        if (hash_equals((string) get_option(self::SIGNATURE_OPTION, ''), $signature)) {
            return;
        }

        if (! $this->acquireLock()) {
            return;
        }

        try {
            if (hash_equals((string) get_option(self::SIGNATURE_OPTION, ''), $signature)) {
                return;
            }

            flush_rewrite_rules(true);
            update_option(self::SIGNATURE_OPTION, $signature, false);
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }

    private function acquireLock(): bool
    {
        $now = time();
        if (add_option(self::LOCK_OPTION, $now, '', false)) {
            return true;
        }

        $lockedAt = get_option(self::LOCK_OPTION, 0);
        if (is_numeric($lockedAt) && $now - (int) $lockedAt > self::LOCK_TTL_SECONDS) {
            delete_option(self::LOCK_OPTION);

            return add_option(self::LOCK_OPTION, $now, '', false);
        }

        return false;
    }

    private function signature(): string
    {
        $contracts = [];
        foreach ($this->patterns->ordered(array_values($this->routes->all())) as $route) {
            $contracts[] = [
                'key' => $route->key(),
                'path' => $route->path(),
                'methods' => $route->methods(),
            ];
        }

        $encoded = json_encode(
            ['permalink_structure' => get_option('permalink_structure', ''), 'routes' => $contracts],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return hash('sha256', $encoded);
    }
}
