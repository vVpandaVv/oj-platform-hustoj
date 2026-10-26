<?php
/**
 * Activation hook.
 *
 * @package ContentsEditor\Core
 */

declare(strict_types=1);

namespace ContentsEditor\Core;

use ContentsEditor\Content\ContentPostType;

final class Activator
{
    public static function activate(): void
    {
        ContentPostType::registerPostType();

        add_option('contents_editor_version', CONTENTS_EDITOR_VERSION, '', false);
        update_option('contents_editor_version', CONTENTS_EDITOR_VERSION, false);
        update_option('contents_editor_activated_at', time(), false);

        flush_rewrite_rules();
    }
}
