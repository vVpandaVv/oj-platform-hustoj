<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Support;

/**
 * Sends the two response headers a browser requires before it will hand
 * out a `SharedArrayBuffer` — `Cross-Origin-Opener-Policy: same-origin` and
 * `Cross-Origin-Embedder-Policy: require-corp` (together, "cross-origin
 * isolation"). resources/js/lab.js needs a SharedArrayBuffer to run Pyodide
 * in a Worker with genuine Atomics.wait()-blocking `input()` support — see
 * docs/README §input() 입력 상자.
 *
 * Scoped to only the notebook page's own path — never sent site-wide —
 * because COEP:require-corp makes the browser drop any subresource on the
 * page that doesn't send a compatible Cross-Origin-Resource-Policy/CORS
 * header, and this addon has no way to audit what oj-platform's shared
 * page shell (nav, theme assets, admin bar, etc.) actually loads on a given
 * install. Outside this one page, nothing here changes site behavior.
 *
 * This can't go through the Addon API's AddonPageResponseV1 (it only offers
 * html()/redirect()/unavailable() — no header control), so it uses a plain
 * WordPress `send_headers` hook instead, which is ordinary plugin-level
 * WordPress usage, not a platform-core modification.
 */
final class Cross_Origin_Isolation {
    private const PATH = '/oj-addons/oj-code-lab/lab';

    public static function register(): void {
        add_action('send_headers', array(__CLASS__, 'maybe_send_headers'));
    }

    public static function maybe_send_headers(): void {
        if (is_admin() || headers_sent()) {
            return;
        }

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (! is_string($path) || rtrim($path, '/') !== rtrim((string) parse_url(home_url(self::PATH), PHP_URL_PATH), '/')) {
            return;
        }

        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Embedder-Policy: require-corp');
    }
}
