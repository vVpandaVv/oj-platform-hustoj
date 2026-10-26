<?php
/**
 * Internal WordPress post type for content hierarchy nodes.
 *
 * @package ReadContentsLibrary\Content
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\Content;

final class ContentPostType
{
    public const POST_TYPE = 'rcis_content_node';

    public function register(): void
    {
        add_action('init', [self::class, 'registerPostType']);
    }

    public static function registerPostType(): void
    {
        register_post_type(
            self::POST_TYPE,
            [
                'labels' => [
                    'name' => __('읽기자료 콘텐츠', 'readcontents-library'),
                    'singular_name' => __('읽기자료 콘텐츠', 'readcontents-library'),
                ],
                'public' => false,
                'show_ui' => false,
                'show_in_menu' => false,
                'show_in_rest' => false,
                'hierarchical' => true,
                'supports' => ['title', 'revisions'],
                'capability_type' => 'post',
                'rewrite' => false,
                'query_var' => false,
            ]
        );
    }
}
