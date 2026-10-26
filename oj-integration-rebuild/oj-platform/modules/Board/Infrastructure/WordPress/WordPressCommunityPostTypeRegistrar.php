<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Infrastructure\WordPress;

final class WordPressCommunityPostTypeRegistrar
{
    public function register(): void
    {
        if (! function_exists('register_post_type')) {
            return;
        }

        register_post_type('oj_board_post', [
            'label' => '게시글',
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'editor', 'author', 'comments', 'revisions'],
            'rewrite' => false,
            'query_var' => false,
        ]);
        register_post_type('oj_notice', [
            'label' => '공지',
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'editor', 'author', 'comments', 'revisions'],
            'rewrite' => false,
            'query_var' => false,
        ]);

        if (function_exists('add_filter')) {
            // Native WordPress submissions do not enforce OJ account, context or nonce policies.
            // OJ comments are inserted only after CommentService authorizes the request.
            add_filter('comments_open', [$this, 'nativeCommentsOpen'], 10, 2);
        }
    }

    public function nativeCommentsOpen(bool $open, int $postId): bool
    {
        if (! function_exists('get_post_type')) {
            return $open;
        }

        return in_array(get_post_type($postId), ['oj_board_post', 'oj_notice'], true)
            ? false
            : $open;
    }
}
