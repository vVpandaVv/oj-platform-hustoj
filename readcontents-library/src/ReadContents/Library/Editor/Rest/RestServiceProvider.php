<?php
/**
 * REST API integration.
 *
 * @package ReadContentsLibrary\Rest
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\Rest;

use ReadContents\Library\Editor\Storage\ContentRepository;
use ReadContents\Library\Editor\Content\ContentPostType;
use WP_REST_Server;

final class RestServiceProvider
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
        (new ContentPostType())->register();
    }

    public function registerRoutes(): void
    {
        (new ContentRoutes(new ContentRepository()))->register();

        register_rest_route(
            ContentRoutes::REST_NAMESPACE,
            '/health',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => static fn (): array => [
                    'ok' => true,
                    'version' => READCONTENTS_LIBRARY_VERSION,
                ],
                'permission_callback' => '__return_true',
            ]
        );
    }
}
