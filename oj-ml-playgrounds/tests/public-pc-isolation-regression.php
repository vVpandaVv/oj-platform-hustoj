<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$GLOBALS['oj_ml_public_pc_site'] = 11;
$GLOBALS['oj_ml_public_pc_session'] = 'session-a-1';

function get_current_blog_id(): int
{
    return (int) $GLOBALS['oj_ml_public_pc_site'];
}

function wp_get_session_token(): string
{
    return (string) $GLOBALS['oj_ml_public_pc_session'];
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'test-only-' . $scheme . '-salt-with-enough-entropy';
}

require_once dirname(__DIR__) . '/src/Security/Local_Storage_Credentials.php';

use OJML\Playgrounds\Security\Local_Storage_Credentials;

/** @param mixed $actual */
function ojMlPublicPcAssert(bool $condition, string $message, mixed $actual = null): void
{
    if (!$condition) {
        throw new RuntimeException($message . ($actual === null ? '' : ' actual=' . var_export($actual, true)));
    }
}

$a1 = Local_Storage_Credentials::for_user(101);
$a1Again = Local_Storage_Credentials::for_user(101);
$b1 = Local_Storage_Credentials::for_user(202);

ojMlPublicPcAssert($a1 === $a1Again, 'Storage credentials must be deterministic within one login session.');
ojMlPublicPcAssert($a1['scope'] !== $b1['scope'], 'Different users must receive different opaque scopes.');
ojMlPublicPcAssert($a1['key'] !== $b1['key'], 'Different users must receive different encryption keys.');
ojMlPublicPcAssert(
    preg_match('/^[A-Za-z0-9_-]{24}$/', $a1['scope']) === 1
        && preg_match('/^[A-Za-z0-9_-]{24}$/', $a1['session']) === 1
        && preg_match('/^[A-Za-z0-9_-]{43}$/', $a1['key']) === 1,
    'Storage credentials must use the strict base64url wire format.',
    $a1
);
ojMlPublicPcAssert(
    $a1['database'] === 'ml-playgrounds-oj-v1-' . $a1['scope'],
    'The database name must be bound to the opaque user scope.'
);

$GLOBALS['oj_ml_public_pc_session'] = 'session-a-2';
$a2 = Local_Storage_Credentials::for_user(101);
ojMlPublicPcAssert($a2['scope'] === $a1['scope'], 'A new login must retain the user storage scope.');
ojMlPublicPcAssert($a2['key'] === $a1['key'], 'A new login must retain the user decryption key.');
ojMlPublicPcAssert($a2['session'] !== $a1['session'], 'A new login must rotate the session fingerprint.');

$GLOBALS['oj_ml_public_pc_site'] = 12;
$site2 = Local_Storage_Credentials::for_user(101);
ojMlPublicPcAssert($site2['scope'] !== $a1['scope'], 'Multisite installations must not share browser scopes.');
ojMlPublicPcAssert($site2['key'] !== $a1['key'], 'Multisite installations must not share encryption keys.');

$root = dirname(__DIR__);
$routes = file_get_contents($root . '/src/Rest/Routes.php');
$handler = file_get_contents($root . '/src/Platform/Addon_Page_Handler.php');
$manifest = file_get_contents($root . '/src/Platform/Addon_Manifest.php');
$controller = file_get_contents($root . '/resources/js/public-pc.js');
$bootstrap = file_get_contents($root . '/resources/js/public-pc-bootstrap.js');

foreach (compact('routes', 'handler', 'manifest', 'controller', 'bootstrap') as $name => $source) {
    ojMlPublicPcAssert(is_string($source), 'Public-PC source must be readable: ' . $name);
}

ojMlPublicPcAssert(
    str_contains($routes, "'/session/storage-context'")
        && str_contains($routes, "'Cache-Control', 'no-store, private'")
        && str_contains($routes, "'Vary', 'Cookie'"),
    'The authenticated storage context endpoint must be private and non-cacheable.'
);
ojMlPublicPcAssert(
    !str_contains($handler, 'data-storage-key') && !str_contains($handler, 'data-storage-scope'),
    'Storage secrets and scopes must not be rendered into HTML attributes.'
);
ojMlPublicPcAssert(
    strpos($manifest, "'resources/js/public-pc.js'") < strpos($manifest, "'resources/js/addon.js'")
        && strpos($manifest, "'resources/js/public-pc-bootstrap.js'") < strpos($manifest, "'resources/js/addon.js'"),
    'Public-PC guards must load before the legacy addon controller.'
);
ojMlPublicPcAssert(
    str_contains($bootstrap, 'SESSION_CHECK_MS = 45000')
        && str_contains($bootstrap, "window.addEventListener('focus'")
        && str_contains($bootstrap, "window.addEventListener('pageshow'")
        && str_contains($bootstrap, "document.addEventListener('visibilitychange'")
        && str_contains($bootstrap, 'storageSession: storage.session'),
    'The wrapper must periodically revalidate the login session and pass its fingerprint to bridge v2.'
);
ojMlPublicPcAssert(
    str_contains($controller, "frame.src = 'about:blank'")
        && str_contains($controller, 'destroyFrame();')
        && str_contains($controller, 'next.session === storage.session'),
    'A changed or unverifiable login context must synchronously lock and destroy the old iframe.'
);

fwrite(STDOUT, "OJ ML public-PC isolation regression passed.\n");
