<?php
/**
 * Deactivation hook.
 *
 * @package ContentsEditor\Core
 */

declare(strict_types=1);

namespace ContentsEditor\Core;

final class Deactivator
{
    public static function deactivate(): void
    {
        delete_transient('contents_editor_runtime_health');

        flush_rewrite_rules();
    }
}
