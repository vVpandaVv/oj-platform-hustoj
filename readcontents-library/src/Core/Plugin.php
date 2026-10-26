<?php
/**
 * Main plugin coordinator.
 *
 * @package ContentsEditor\Core
 */

declare(strict_types=1);

namespace ContentsEditor\Core;

final class Plugin
{
    private static ?self $instance = null;

    private bool $booted = false;

    public static function instance(): self
    {
        if (! self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        // This build is deployed purely as the oj-platform "readcontents-library"
        // C-28 addon (see contents-editor.php's plugin header) -- it must not also
        // stand up ContentsEditor's own standalone WP-admin menu, CPT, REST
        // namespace, or shortcodes (AdminServiceProvider/PublicServiceProvider/
        // ContentPostType/RestServiceProvider). Those created a second, disconnected
        // "Contents Editor" content tree with no relation to the real oj-platform-
        // integrated one. They remain in src/ for a standalone reuse of this
        // codebase elsewhere, just not wired up here.
        //
        // The actual addon registration (oj_platform_register_addons, the ported
        // RestServiceProvider, LegacyContentMigrator) lives at top-level in
        // contents-editor.php, not here -- oj-platform fires that action from its
        // own 'plugins_loaded' handler at priority 1, before this class's
        // 'plugins_loaded' callback (default priority 10) would ever run.

        add_action('init', [$this, 'loadTextDomain']);
    }

    public function loadTextDomain(): void
    {
        load_plugin_textdomain(
            'contents-editor',
            false,
            dirname(plugin_basename(CONTENTS_EDITOR_FILE)) . '/languages'
        );
    }
}
