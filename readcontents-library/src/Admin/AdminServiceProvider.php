<?php
/**
 * WordPress admin integration.
 *
 * @package ContentsEditor\Admin
 */

declare(strict_types=1);

namespace ContentsEditor\Admin;

use ContentsEditor\Infrastructure\Assets;

final class AdminServiceProvider
{
    public function __construct(private readonly Assets $assets)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerMenu(): void
    {
        add_menu_page(
            __('Contents Editor', 'contents-editor'),
            __('Contents Editor', 'contents-editor'),
            CONTENTS_EDITOR_CAPABILITY,
            'contents-editor',
            [$this, 'renderPage'],
            'dashicons-edit-page',
            58
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_contents-editor') {
            return;
        }

        $this->assets->enqueueAdmin();
    }

    public function renderPage(): void
    {
        $admin_settings = $this->assets->adminSettings();
        $admin_boot_diagnostics_script = $this->assets->adminBootDiagnosticsScript();

        require CONTENTS_EDITOR_PATH . 'templates/admin/app.php';
    }
}
