<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ResponseEmitter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\SafeRedirect;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use Throwable;

final class WordPressLoginGuard
{
    /** @var list<string>|null */
    private ?array $allowedRoutePaths = null;

    public function __construct(
        private readonly ResponseEmitter $emitter,
        private readonly FeatureFlags $features,
        private readonly SafeRedirect $redirects,
        private readonly ErrorReporter $errors
    ) {
    }

    /** @param list<string>|null $allowedRoutePaths */
    public function registerHooks(HookRegistrar $hooks, ?array $allowedRoutePaths = null): void
    {
        if ((defined('OJ_PLATFORM_ALLOW_WORDPRESS_LOGIN') && OJ_PLATFORM_ALLOW_WORDPRESS_LOGIN === true)
            || (function_exists('wp_is_recovery_mode') && wp_is_recovery_mode())) {
            return;
        }

        try {
            if (! $this->features->isEnabled('auth')) {
                return;
            }
        } catch (Throwable $error) {
            $this->errors->report($error);

            return;
        }

        $this->allowedRoutePaths = $allowedRoutePaths;
        $hooks->addAction('login_init', [$this, 'handleNativeLogin'], 0);
        if (function_exists('add_filter')) {
            // Keep core URL builders intact: recovery and privacy mail append
            // non-login actions to wp_login_url().
            // The core reauthentication overlay can reopen a login in a new tab.
            add_filter('wp_auth_check_same_domain', static fn (bool $sameDomain): bool => false);
        }
    }

    public function handleNativeLogin(): void
    {
        // Core normalizes unknown actions to "login" before calling login_init.
        $action = is_string($GLOBALS['action'] ?? null) ? $GLOBALS['action'] : 'login';
        if (in_array($action, ['logout', 'postpass', 'confirmaction'], true)
            || ($action === 'confirm_admin_email'
                && function_exists('is_user_logged_in') && is_user_logged_in())) {
            return;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $target = match ($action) {
            'lostpassword', 'retrievepassword' => '/login?mode=forgot',
            'rp', 'resetpass' => $this->resetPath($method),
            'register' => $this->registrationPath(),
            default => $this->loginPath($this->queryString('redirect_to')),
        };

        $this->emitter->emit(new HttpResponse(
            in_array($method, ['GET', 'HEAD'], true) ? 302 : 303,
            [
                'Location' => $target,
                'Cache-Control' => 'private, no-store',
                'Referrer-Policy' => 'no-referrer',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
            ''
        ), $method === 'HEAD');

        exit;
    }

    private function loginPath(string $redirectTo): string
    {
        $path = $this->redirects->afterLogin($redirectTo);

        return $path === '/' ? '/login' : '/login?redirect_to=' . rawurlencode($path);
    }

    private function registrationPath(): string
    {
        return $this->allowedRoutePaths === null
            || in_array('/register', $this->allowedRoutePaths, true)
                ? '/register' : '/login';
    }

    private function resetPath(string $method): string
    {
        if (! in_array($method, ['GET', 'HEAD'], true)) {
            return '/login?mode=forgot';
        }

        $key = $this->queryString('key');
        $login = $this->queryString('login');
        if ($key === '' || $login === '') {
            return '/login?mode=forgot';
        }

        return '/login?' . http_build_query(
            ['mode' => 'reset', 'key' => $key, 'login' => $login],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    private function queryString(string $key): string
    {
        $value = $_GET[$key] ?? null;
        if (! is_scalar($value)) {
            return '';
        }

        return function_exists('wp_unslash') ? wp_unslash((string) $value) : (string) $value;
    }
}
