<?php
/**
 * REST API integration.
 *
 * @package ContentsEditor\Rest
 */

declare(strict_types=1);

namespace ContentsEditor\Rest;

use ContentsEditor\Storage\ContentRepository;
use WP_REST_Server;

final class RestServiceProvider
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        (new ContentRoutes(new ContentRepository()))->register();

        register_rest_route(
            'contents-editor/v1',
            '/health',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => static fn (): array => [
                    'ok' => true,
                    'version' => CONTENTS_EDITOR_VERSION,
                ],
                'permission_callback' => '__return_true',
            ]
        );
    }
}
