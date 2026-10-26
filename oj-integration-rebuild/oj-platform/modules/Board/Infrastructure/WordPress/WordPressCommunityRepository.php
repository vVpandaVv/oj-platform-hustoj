<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityRepository;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityComment;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContext;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPost;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPostDraft;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPostSearch;
use InvalidArgumentException;
use RuntimeException;

final class WordPressCommunityRepository implements CommunityRepository
{
    private const POST_TYPES = [
        'board' => 'oj_board_post',
        'notice' => 'oj_notice',
    ];

    public function search(
        CommunityPostSearch $search,
        int $limit,
        int $offset
    ): array {
        $this->requireFunction('get_posts');
        $sortKey = $search->kind() === 'board'
            ? '_oj_board_pinned'
            : '_oj_notice_priority';
        $arguments = [
            'post_type' => self::POST_TYPES[$search->kind()],
            'post_status' => $search->statuses(),
            'posts_per_page' => max(1, min(500, $limit)),
            'offset' => max(0, $offset),
            'orderby' => ['meta_value_num' => 'DESC', 'date' => 'DESC'],
            'order' => 'DESC',
            'meta_key' => $sortKey,
            's' => $search->keyword(),
            'no_found_rows' => true,
            'suppress_filters' => false,
        ];
        $meta = [];
        if ($search->contextType() !== null) {
            $meta[] = [
                'key' => '_oj_board_context_type',
                'value' => $search->contextType(),
                'compare' => '=',
            ];
        }
        if ($search->contextId() !== null) {
            $meta[] = [
                'key' => '_oj_board_context_id',
                'value' => $search->contextId(),
                'compare' => '=',
                'type' => 'NUMERIC',
            ];
        }
        if ($meta !== []) {
            $arguments['meta_query'] = $meta;
        }

        $posts = get_posts($arguments);
        $items = [];
        foreach (is_array($posts) ? $posts : [] as $post) {
            $item = $this->hydratePost($post);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function findPost(int $postId): ?CommunityPost
    {
        $this->requireFunction('get_post');
        return $this->hydratePost(get_post($postId));
    }

    public function createPost(CommunityPostDraft $draft): int
    {
        $this->requireFunction('wp_insert_post');
        $data = $this->postData($draft);
        $postId = wp_insert_post($data, true);
        if ($this->isError($postId) || (int) $postId < 1) {
            throw new RuntimeException('게시글을 저장하지 못했습니다.');
        }
        $this->writePostMeta((int) $postId, $draft);

        return (int) $postId;
    }

    public function updatePost(int $postId, CommunityPostDraft $draft): void
    {
        $this->requireFunction('wp_update_post');
        $result = wp_update_post([
            'ID' => $postId,
            ...$this->postData($draft),
        ], true);
        if ($this->isError($result) || (int) $result < 1) {
            throw new RuntimeException('게시글을 수정하지 못했습니다.');
        }
        $this->writePostMeta($postId, $draft);
    }

    public function setPostHidden(
        int $postId,
        bool $hidden,
        int $actorUserId,
        string $reason
    ): void {
        $this->meta($postId, '_oj_board_hidden', $hidden ? '1' : '0');
        $this->auditPost(
            $postId,
            $hidden ? 'hidden' : 'visible',
            $actorUserId,
            $reason
        );
    }

    public function setPostPinned(int $postId, bool $pinned): void
    {
        $post = $this->findPost($postId);
        if ($post === null) {
            throw new RuntimeException('게시글을 찾을 수 없습니다.');
        }
        $key = $post->kind() === 'board'
            ? '_oj_board_pinned'
            : '_oj_notice_pinned';
        $this->meta($postId, $key, $pinned ? '1' : '0');
        if ($post->kind() === 'notice') {
            $this->meta(
                $postId,
                '_oj_notice_priority',
                (string) ($pinned ? 2 : ($post->isImportant() ? 1 : 0))
            );
        }
    }

    public function trashPost(
        int $postId,
        int $actorUserId,
        string $reason
    ): void {
        $this->requireFunction('wp_trash_post');
        if (wp_trash_post($postId) === false) {
            throw new RuntimeException('글을 휴지통으로 이동하지 못했습니다.');
        }
        $this->auditPost($postId, 'trash', $actorUserId, $reason);
    }

    public function restorePost(int $postId, int $actorUserId): void
    {
        $this->requireFunction('wp_untrash_post');
        if (wp_untrash_post($postId) === false) {
            throw new RuntimeException('글을 복원하지 못했습니다.');
        }
        $this->auditPost($postId, 'restore', $actorUserId, '휴지통에서 복원');
    }

    public function commentCounts(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter(
            array_map('intval', $postIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($postIds === []) {
            return [];
        }
        $this->requireFunction('get_comments');
        $comments = get_comments([
            'post__in' => $postIds,
            'status' => 'approve',
            'number' => 0,
        ]);
        $counts = array_fill_keys($postIds, 0);
        foreach (is_array($comments) ? $comments : [] as $comment) {
            $item = $this->hydrateComment($comment);
            if ($item !== null && ! $item->isHidden() && ! $item->isTrashed()) {
                $counts[$item->postId()] = ($counts[$item->postId()] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function comments(int $postId, bool $includeModerated = false): array
    {
        $this->requireFunction('get_comments');
        $comments = get_comments([
            'post_id' => $postId,
            'status' => $includeModerated ? 'all' : 'approve',
            'orderby' => 'comment_date_gmt',
            'order' => 'ASC',
            'number' => 0,
        ]);
        $items = [];
        foreach (is_array($comments) ? $comments : [] as $comment) {
            $item = $this->hydrateComment($comment);
            if (
                $item !== null
                && ($includeModerated || (! $item->isHidden() && ! $item->isTrashed()))
            ) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function findComment(int $commentId): ?CommunityComment
    {
        $this->requireFunction('get_comment');
        return $this->hydrateComment(get_comment($commentId));
    }

    public function createComment(
        int $postId,
        int $authorUserId,
        string $body,
        string $bodyHash
    ): int {
        $this->requireFunction('wp_insert_comment');
        $commentId = wp_insert_comment([
            'comment_post_ID' => $postId,
            'user_id' => $authorUserId,
            'comment_content' => $body,
            'comment_approved' => 1,
            'comment_date' => current_time('mysql'),
            'comment_date_gmt' => current_time('mysql', true),
        ]);
        if ((int) $commentId < 1) {
            throw new RuntimeException('댓글을 저장하지 못했습니다.');
        }
        $this->commentMeta((int) $commentId, '_oj_comment_hash', $bodyHash);
        $this->commentMeta((int) $commentId, '_oj_comment_hidden', '0');

        return (int) $commentId;
    }

    public function hasRecentDuplicateComment(
        int $postId,
        int $authorUserId,
        string $bodyHash,
        int $windowSeconds
    ): bool {
        $this->requireFunction('get_comments');
        $comments = get_comments([
            'post_id' => $postId,
            'user_id' => $authorUserId,
            'status' => 'approve',
            'number' => 1,
            'meta_key' => '_oj_comment_hash',
            'meta_value' => $bodyHash,
            'date_query' => [[
                'after' => gmdate('Y-m-d H:i:s', time() - max(1, $windowSeconds)),
                'column' => 'comment_date_gmt',
                'inclusive' => true,
            ]],
        ]);

        return is_array($comments) && $comments !== [];
    }

    public function setCommentHidden(
        int $commentId,
        bool $hidden,
        int $actorUserId,
        string $reason
    ): void {
        $this->commentMeta(
            $commentId,
            '_oj_comment_hidden',
            $hidden ? '1' : '0'
        );
        $this->auditComment(
            $commentId,
            $hidden ? 'hidden' : 'visible',
            $actorUserId,
            $reason
        );
    }

    public function trashComment(
        int $commentId,
        int $actorUserId,
        string $reason
    ): void {
        $this->requireFunction('wp_trash_comment');
        if (! wp_trash_comment($commentId)) {
            throw new RuntimeException('댓글을 휴지통으로 이동하지 못했습니다.');
        }
        $this->auditComment($commentId, 'trash', $actorUserId, $reason);
    }

    public function restoreComment(int $commentId, int $actorUserId): void
    {
        $this->requireFunction('wp_untrash_comment');
        if (! wp_untrash_comment($commentId)) {
            throw new RuntimeException('댓글을 복원하지 못했습니다.');
        }
        $this->auditComment(
            $commentId,
            'restore',
            $actorUserId,
            '휴지통에서 복원'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function postData(CommunityPostDraft $draft): array
    {
        $data = [
            'post_type' => self::POST_TYPES[$draft->kind()],
            'post_author' => $draft->authorUserId(),
            'post_title' => $draft->title(),
            'post_content' => $draft->body(),
            'post_status' => $draft->status(),
            'comment_status' => $draft->commentsAllowed() ? 'open' : 'closed',
            'ping_status' => 'closed',
        ];
        if ($draft->publishAt() !== null) {
            $data['post_date'] = $draft->publishAt();
            $data['edit_date'] = true;
        }

        return $data;
    }

    private function writePostMeta(int $postId, CommunityPostDraft $draft): void
    {
        $this->meta($postId, '_oj_board_context_type', $draft->context()->type());
        $this->meta(
            $postId,
            '_oj_board_context_id',
            (string) $draft->context()->targetId()
        );
        $this->meta(
            $postId,
            '_oj_board_context_label',
            $draft->context()->label()
        );
        if ($draft->kind() === 'board') {
            $this->meta(
                $postId,
                '_oj_board_pinned',
                $draft->isPinned() ? '1' : '0'
            );
            $this->meta(
                $postId,
                '_oj_board_secret',
                $draft->isSecret() ? '1' : '0'
            );
            if (! $this->hasPostMeta($postId, '_oj_board_hidden')) {
                $this->meta($postId, '_oj_board_hidden', '0');
            }

            return;
        }

        $this->meta(
            $postId,
            '_oj_notice_pinned',
            $draft->isPinned() ? '1' : '0'
        );
        $this->meta(
            $postId,
            '_oj_notice_important',
            $draft->isImportant() ? '1' : '0'
        );
        $this->meta(
            $postId,
            '_oj_notice_priority',
            (string) ($draft->isPinned() ? 2 : ($draft->isImportant() ? 1 : 0))
        );
        $this->meta(
            $postId,
            '_oj_notice_publish_at',
            $draft->publishAt() ?? ''
        );
        $this->meta(
            $postId,
            '_oj_notice_ends_at',
            $draft->endsAt() ?? ''
        );
    }

    private function hydratePost(mixed $post): ?CommunityPost
    {
        if (! is_object($post)) {
            return null;
        }
        $postType = (string) ($post->post_type ?? '');
        $kind = array_search($postType, self::POST_TYPES, true);
        if (! is_string($kind)) {
            return null;
        }
        $postId = (int) ($post->ID ?? 0);
        $contextType = (string) $this->postMeta(
            $postId,
            '_oj_board_context_type',
            'global'
        );
        $contextId = (int) $this->postMeta(
            $postId,
            '_oj_board_context_id',
            '0'
        );
        $contextLabel = (string) $this->postMeta(
            $postId,
            '_oj_board_context_label',
            $contextType === 'global' ? '전체 게시판' : $contextType . ' #' . $contextId
        );

        try {
            return new CommunityPost(
                $postId,
                $kind,
                (int) ($post->post_author ?? 0),
                (string) ($post->post_title ?? ''),
                (string) ($post->post_content ?? ''),
                (string) ($post->post_status ?? ''),
                new CommunityContext($contextType, $contextId, $contextLabel),
                $this->truthy($this->postMeta(
                    $postId,
                    $kind === 'board'
                        ? '_oj_board_pinned'
                        : '_oj_notice_pinned',
                    '0'
                )),
                $this->truthy($this->postMeta(
                    $postId,
                    '_oj_notice_important',
                    '0'
                )),
                $this->truthy($this->postMeta(
                    $postId,
                    '_oj_board_hidden',
                    '0'
                )),
                $this->truthy($this->postMeta(
                    $postId,
                    '_oj_board_secret',
                    '0'
                )),
                (string) ($post->comment_status ?? 'closed') === 'open',
                $this->nullableMeta($postId, '_oj_notice_publish_at'),
                $this->nullableMeta($postId, '_oj_notice_ends_at'),
                (string) ($post->post_date ?? ''),
                (string) ($post->post_modified ?? '')
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function hydrateComment(mixed $comment): ?CommunityComment
    {
        if (! is_object($comment)) {
            return null;
        }
        $commentId = (int) ($comment->comment_ID ?? 0);
        $approved = (string) ($comment->comment_approved ?? '');

        try {
            return new CommunityComment(
                $commentId,
                (int) ($comment->comment_post_ID ?? 0),
                (int) ($comment->user_id ?? 0),
                (string) ($comment->comment_content ?? ''),
                $approved === 'trash' ? 'trash' : 'approve',
                $this->truthy($this->commentMetaValue(
                    $commentId,
                    '_oj_comment_hidden',
                    '0'
                )),
                (string) ($comment->comment_date ?? '')
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function nullableMeta(int $postId, string $key): ?string
    {
        $value = trim((string) $this->postMeta($postId, $key, ''));

        return $value === '' ? null : $value;
    }

    private function postMeta(int $postId, string $key, mixed $default): mixed
    {
        return function_exists('get_post_meta')
            ? (($value = get_post_meta($postId, $key, true)) === '' ? $default : $value)
            : $default;
    }

    private function meta(int $postId, string $key, string $value): void
    {
        $this->requireFunction('update_post_meta');
        update_post_meta($postId, $key, $value);
    }

    private function hasPostMeta(int $postId, string $key): bool
    {
        return function_exists('metadata_exists')
            ? metadata_exists('post', $postId, $key)
            : $this->postMeta($postId, $key, null) !== null;
    }

    private function commentMeta(int $commentId, string $key, string $value): void
    {
        $this->requireFunction('update_comment_meta');
        update_comment_meta($commentId, $key, $value);
    }

    private function commentMetaValue(
        int $commentId,
        string $key,
        mixed $default
    ): mixed {
        return function_exists('get_comment_meta')
            ? (($value = get_comment_meta($commentId, $key, true)) === ''
                ? $default
                : $value)
            : $default;
    }

    private function auditPost(
        int $postId,
        string $operation,
        int $actorUserId,
        string $reason
    ): void {
        if (function_exists('add_post_meta')) {
            add_post_meta($postId, '_oj_moderation_event', json_encode([
                'operation' => $operation,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    private function auditComment(
        int $commentId,
        string $operation,
        int $actorUserId,
        string $reason
    ): void {
        if (function_exists('add_comment_meta')) {
            add_comment_meta($commentId, '_oj_moderation_event', json_encode([
                'operation' => $operation,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    private function truthy(mixed $value): bool
    {
        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }

    private function isError(mixed $value): bool
    {
        return function_exists('is_wp_error') && is_wp_error($value);
    }

    private function requireFunction(string $name): void
    {
        if (! function_exists($name)) {
            throw new RuntimeException('WordPress 커뮤니티 API를 사용할 수 없습니다.');
        }
    }
}
