<?php
/**
 * Public site integration.
 *
 * @package ContentsEditor\PublicSite
 */

declare(strict_types=1);

namespace ContentsEditor\PublicSite;

use ContentsEditor\Infrastructure\Assets;

final class PublicServiceProvider
{
    public function __construct(private readonly Assets $assets)
    {
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('init', [$this, 'registerShortcodes']);
    }

    public function enqueueAssets(): void
    {
        $this->assets->enqueuePublic();
    }

    public function registerShortcodes(): void
    {
        (new TopicShortcode($this->assets))->register();
        (new LessonShortcode($this->assets))->register();
    }
}
