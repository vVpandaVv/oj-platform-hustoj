<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Support;

/**
 * Serves resources/js/pyodide-worker.js through WordPress instead of
 * letting the web server hand it out as a plain static file.
 *
 * This matters because of a real bug found on a live install: the notebook
 * page sends Cross-Origin-Embedder-Policy: require-corp (Cross_Origin_Isolation),
 * which makes the browser require a Cross-Origin-Resource-Policy header on
 * every subresource it loads — including this addon's own same-origin
 * Worker script. A static file under wp-content/plugins/ is served directly
 * by nginx/Apache, bypassing PHP entirely, so Cross_Origin_Isolation's
 * send_headers hook never runs for it and the header is simply absent
 * ("NOT-SET" in DevTools). Chrome then refuses to let `new Worker(url)`
 * use it, and — before the onerror/timeout fixes added alongside this —
 * the "실행" button did nothing with no visible error at all.
 *
 * Hooked on plain `init` at the earliest priority rather than routed
 * through admin-ajax.php (an earlier version tried that first, then
 * dropped it in favor of this — admin-ajax's full wp-admin bootstrap fires
 * many other plugins' hooks first, any of which could flush a response
 * before our headers are set).
 *
 * The actual root cause, confirmed via Chrome's own DevTools Issues panel
 * on a live install ("Specify a Cross-Origin Embedder Policy to stop this
 * frame from being blocked", naming this route's request): a Worker's own
 * script response needs its own `Cross-Origin-Embedder-Policy` header once
 * its parent document is cross-origin-isolated — plain
 * `Cross-Origin-Resource-Policy` (what a same-origin subresource like a
 * script/image/stylesheet needs) is NOT enough for a Worker specifically;
 * Chrome treats spinning up a dedicated Worker more like nesting a browsing
 * context than loading an ordinary subresource. This is why the static-file
 * → admin-ajax → init-hook delivery changes never fixed it on their own —
 * none of those attempts had ever set COEP on this response, only CORP.
 */
final class Worker_Script_Route {
    private const QUERY_PARAM = 'oj_code_lab_worker';

    public static function register(): void {
        add_action('init', array(__CLASS__, 'maybe_serve'), 0);
    }

    public static function url(): string {
        // The version tag isn't read by maybe_serve() — its only job is to
        // change the URL on every plugin update, so a browser (or any
        // intermediate cache) that cached an older response under the old
        // URL can never accidentally keep serving it after a fix ships.
        return add_query_arg(
            array(self::QUERY_PARAM => '1', 'v' => OJ_CODE_LAB_VERSION),
            home_url('/')
        );
    }

    public static function maybe_serve(): void {
        if (! isset($_GET[self::QUERY_PARAM])) {
            return;
        }

        // Drains anything already buffered this request (a stray PHP
        // notice, another plugin's own output buffer, whitespace before an
        // opening <?php tag) so nothing gets prepended to the JS this
        // sends — a single stray byte is enough to turn a valid script
        // into a SyntaxError inside the worker.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (! headers_sent()) {
            header('Content-Type: application/javascript; charset=utf-8');
            // The header a static file couldn't send at all (see class
            // doc) — and, per Chrome's own diagnosis, the one that
            // actually matters for a Worker's own script under a
            // cross-origin-isolated parent. Kept alongside CORP too: CORP
            // is still the correct header for this response being loaded
            // as an ordinary same-origin subresource, and costs nothing to
            // include.
            header('Cross-Origin-Embedder-Policy: require-corp');
            header('Cross-Origin-Resource-Policy: same-origin');
            // Safe to cache generously now that url() ties a version tag
            // to the URL itself — a stale cached response from an older
            // plugin version can never be reused after an update, because
            // the URL it was cached under no longer matches.
            header('Cache-Control: public, max-age=604800');
        }

        $path = OJ_CODE_LAB_PATH . 'resources/js/pyodide-worker.js';
        if (is_readable($path)) {
            readfile($path);
        }

        exit;
    }
}
