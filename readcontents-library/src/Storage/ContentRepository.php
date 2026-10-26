<?php
/**
 * Storage layer for content hierarchy and Plate lesson documents.
 *
 * @package ContentsEditor\Storage
 */

declare(strict_types=1);

namespace ContentsEditor\Storage;

use ContentsEditor\Content\ContentNode;
use ContentsEditor\Content\ContentNodeType;
use ContentsEditor\Content\ContentPostType;
use ContentsEditor\Content\ContentStatus;
use ContentsEditor\Content\LessonDocument;
use InvalidArgumentException;
use RuntimeException;
use WP_Post;
use WP_Query;

final class ContentRepository
{
    private const META_TYPE = '_contents_editor_node_type';
    private const META_STATUS = '_contents_editor_node_status';
    private const META_CONTENT_TYPE = '_contents_editor_content_type';
    private const META_SCHEMA_VERSION = '_contents_editor_schema_version';
    private const META_METADATA = '_contents_editor_metadata';
    private const META_LESSON_DOCUMENT = '_contents_editor_lesson_document';

    /**
     * @param array<string, mixed> $filters
     * @return array<int, ContentNode>
     */
    public function listNodes(array $filters = []): array
    {
        $query_args = [
            'post_type' => ContentPostType::POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'orderby' => [
                'menu_order' => 'ASC',
                'date' => 'ASC',
            ],
            'order' => 'ASC',
            'no_found_rows' => true,
        ];

        if (array_key_exists('parentId', $filters)) {
            $query_args['post_parent'] = $this->toPostId($filters['parentId']);
        }

        $query = new WP_Query($query_args);

        $nodes = array_map(
            fn (WP_Post $post): ContentNode => $this->nodeFromPost($post),
            $query->posts
        );

        if (! $filters) {
            return array_values($nodes);
        }

        $type_filter = null;
        $status_filter = null;
        $include_archived = (bool) ($filters['includeArchived'] ?? false);

        if (! empty($filters['type'])) {
            $type_filter = $this->readType($filters['type']);
        }

        if (! empty($filters['status'])) {
            $status_filter = $this->readStatus($filters['status']);
        }

        return array_values(
            array_filter(
                $nodes,
                function (ContentNode $node) use ($type_filter, $status_filter, $include_archived): bool {
                    if (null !== $type_filter && $type_filter !== $node->type) {
                        return false;
                    }

                    if (null !== $status_filter && $status_filter !== $node->status) {
                        return false;
                    }

                    return $include_archived || ContentStatus::ARCHIVED !== $node->status;
                }
            )
        );
    }

    public function getNode(string $id): ?ContentNode
    {
        $post = get_post($this->toPostId($id));

        if (! $post instanceof WP_Post || ContentPostType::POST_TYPE !== $post->post_type) {
            return null;
        }

        return $this->nodeFromPost($post);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createNode(array $payload): ContentNode
    {
        $type = $this->readType($payload['type'] ?? '');
        $title = $this->readTitle($payload['title'] ?? '');
        $status = $this->readStatus($payload['status'] ?? ContentStatus::DRAFT->value);
        $parent_id = $this->readNullableId($payload['parentId'] ?? null);
        $order = $this->readOrder($payload['order'] ?? 0);
        $slug = sanitize_title((string) ($payload['slug'] ?? $title));
        $content_type = $this->readString($payload['contentType'] ?? 'default', 'default');
        $schema_version = $this->readString($payload['schemaVersion'] ?? '1.0.0', '1.0.0');
        $metadata = $this->readArray($payload['metadata'] ?? []);

        $this->assertParentAllowed($type, $parent_id);

        $post_id = wp_insert_post(
            [
                'post_type' => ContentPostType::POST_TYPE,
                'post_status' => $this->mapDomainStatusToPostStatus($status),
                'post_title' => $title,
                'post_name' => $slug,
                'post_parent' => $parent_id ? (int) $parent_id : 0,
                'menu_order' => $order,
            ],
            true
        );

        if (is_wp_error($post_id)) {
            throw new RuntimeException($post_id->get_error_message());
        }

        $this->writeNodeMeta((int) $post_id, $type, $status, $content_type, $schema_version, $metadata);

        $node = $this->getNode((string) $post_id);

        if (! $node) {
            throw new RuntimeException(__('Created content node could not be loaded.', 'contents-editor'));
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateNode(string $id, array $payload): ContentNode
    {
        $existing = $this->getNode($id);

        if (! $existing) {
            throw new InvalidArgumentException(__('Content node was not found.', 'contents-editor'));
        }

        if (isset($payload['type']) && $payload['type'] !== $existing->type->value) {
            throw new InvalidArgumentException(__('Content node type cannot be changed after creation.', 'contents-editor'));
        }

        $title = array_key_exists('title', $payload) ? $this->readTitle($payload['title']) : $existing->title;
        $status = array_key_exists('status', $payload) ? $this->readStatus($payload['status']) : $existing->status;
        $parent_id = array_key_exists('parentId', $payload) ? $this->readNullableId($payload['parentId']) : $existing->parentId;
        $order = array_key_exists('order', $payload) ? $this->readOrder($payload['order']) : $existing->order;
        $slug = array_key_exists('slug', $payload) ? sanitize_title((string) $payload['slug']) : $existing->slug;
        $content_type = array_key_exists('contentType', $payload) ? $this->readString($payload['contentType'], 'default') : $existing->contentType;
        $schema_version = array_key_exists('schemaVersion', $payload) ? $this->readString($payload['schemaVersion'], '1.0.0') : $existing->schemaVersion;
        $metadata = array_key_exists('metadata', $payload) ? $this->readArray($payload['metadata']) : $existing->metadata;

        if ((string) $id === (string) $parent_id) {
            throw new InvalidArgumentException(__('Content node cannot be its own parent.', 'contents-editor'));
        }

        $this->assertParentAllowed($existing->type, $parent_id);
        $this->assertNoParentCycle($id, $parent_id);

        $updated = wp_update_post(
            [
                'ID' => (int) $id,
                'post_status' => $this->mapDomainStatusToPostStatus($status),
                'post_title' => $title,
                'post_name' => $slug,
                'post_parent' => $parent_id ? (int) $parent_id : 0,
                'menu_order' => $order,
            ],
            true
        );

        if (is_wp_error($updated)) {
            throw new RuntimeException($updated->get_error_message());
        }

        $this->writeNodeMeta((int) $id, $existing->type, $status, $content_type, $schema_version, $metadata);

        $node = $this->getNode($id);

        if (! $node) {
            throw new RuntimeException(__('Updated content node could not be loaded.', 'contents-editor'));
        }

        return $node;
    }

    public function archiveNode(string $id): ContentNode
    {
        return $this->updateNode(
            $id,
            [
                'status' => ContentStatus::ARCHIVED->value,
            ]
        );
    }

    public function restoreNode(string $id): ContentNode
    {
        return $this->updateNode(
            $id,
            [
                'status' => ContentStatus::DRAFT->value,
            ]
        );
    }

    /**
     * Permanently delete a node and all descendant nodes.
     *
     * @return array{node: array<string, mixed>, deleted: array{topics: int, chapters: int, sections: int, lessons: int}}
     */
    public function deleteNode(string $id): array
    {
        $node = $this->getNode($id);

        if (! $node) {
            throw new InvalidArgumentException(__('Content node was not found.', 'contents-editor'));
        }

        $deleted = [
            'topics' => 0,
            'chapters' => 0,
            'sections' => 0,
            'lessons' => 0,
        ];
        $deleted_node = $node->toArray();

        $this->deleteNodeRecursive($node, $deleted);

        return [
            'node' => $deleted_node,
            'deleted' => $deleted,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTopicTree(string $topic_id): array
    {
        $topic = $this->getNode($topic_id);

        if (! $topic || ContentNodeType::TOPIC !== $topic->type) {
            throw new InvalidArgumentException(__('Topic was not found.', 'contents-editor'));
        }

        $chapters = array_map(
            function (ContentNode $chapter): array {
                $sections = array_map(
                    function (ContentNode $section): array {
                        return [
                            'section' => $section->toArray(),
                            'lessons' => $this->nodesToArray(
                                $this->listNodes(
                                    [
                                        'type' => ContentNodeType::LESSON->value,
                                        'parentId' => $section->id,
                                    ]
                                )
                            ),
                        ];
                    },
                    $this->listNodes(
                        [
                            'type' => ContentNodeType::SECTION->value,
                            'parentId' => $chapter->id,
                        ]
                    )
                );

                return [
                    'chapter' => $chapter->toArray(),
                    'sections' => $sections,
                ];
            },
            $this->listNodes(
                [
                    'type' => ContentNodeType::CHAPTER->value,
                    'parentId' => $topic->id,
                ]
            )
        );

        return [
            'topic' => $topic->toArray(),
            'chapters' => $chapters,
        ];
    }

    public function getLessonDocument(string $lesson_id): LessonDocument
    {
        $lesson = $this->requireLesson($lesson_id);
        $stored = get_post_meta((int) $lesson->id, self::META_LESSON_DOCUMENT, true);

        if (is_array($stored) && isset($stored['plateValue']) && is_array($stored['plateValue'])) {
            return new LessonDocument(
                $lesson->id,
                array_values($stored['plateValue']),
                $this->readArray($stored['assets'] ?? []),
                $this->readArray($stored['visuals'] ?? []),
                $this->readString($stored['schemaVersion'] ?? '1.0.0', '1.0.0'),
                max(1, (int) ($stored['revision'] ?? 1))
            );
        }

        return new LessonDocument(
            $lesson->id,
            [
                [
                    'type' => 'h1',
                    'children' => [
                        [
                            'text' => $lesson->title,
                        ],
                    ],
                ],
                [
                    'type' => 'p',
                    'children' => [
                        [
                            'text' => '',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveLessonDocument(string $lesson_id, array $payload): LessonDocument
    {
        $lesson = $this->requireLesson($lesson_id);

        if (! isset($payload['plateValue']) || ! is_array($payload['plateValue'])) {
            throw new InvalidArgumentException(__('Plate editor value must be an array.', 'contents-editor'));
        }

        $previous = $this->getLessonDocument($lesson->id);
        $document = new LessonDocument(
            $lesson->id,
            array_values($payload['plateValue']),
            $this->readArray($payload['assets'] ?? []),
            $this->readArray($payload['visuals'] ?? []),
            $this->readString($payload['schemaVersion'] ?? $previous->schemaVersion, $previous->schemaVersion),
            $previous->revision + 1
        );

        update_post_meta((int) $lesson->id, self::META_LESSON_DOCUMENT, wp_slash($document->toArray()));

        return $document;
    }

    /**
     * @param array<int, ContentNode> $nodes
     * @return array<int, array<string, mixed>>
     */
    public function nodesToArray(array $nodes): array
    {
        return array_values(
            array_map(
                static fn (ContentNode $node): array => $node->toArray(),
                $nodes
            )
        );
    }

    private function nodeFromPost(WP_Post $post): ContentNode
    {
        $meta_type = get_post_meta($post->ID, self::META_TYPE, true);
        $type = ContentNodeType::tryFrom((string) $meta_type) ?? $this->inferNodeTypeFromParent($post->ID);
        $status = ContentStatus::tryFrom((string) get_post_meta($post->ID, self::META_STATUS, true))
            ?? $this->inferStatusFromPostStatus($post->post_status);
        $metadata = get_post_meta($post->ID, self::META_METADATA, true);

        return new ContentNode(
            (string) $post->ID,
            $post->post_name,
            get_the_title($post),
            $type,
            $status,
            $post->post_parent > 0 ? (string) $post->post_parent : null,
            (int) $post->menu_order,
            $this->readString(get_post_meta($post->ID, self::META_CONTENT_TYPE, true), 'default'),
            $this->readString(get_post_meta($post->ID, self::META_SCHEMA_VERSION, true), '1.0.0'),
            is_array($metadata) ? $metadata : []
        );
    }

    private function inferNodeTypeFromParent(int $post_id): ContentNodeType
    {
        $post = get_post($post_id);

        if (! $post instanceof WP_Post || $post->post_parent <= 0) {
            return ContentNodeType::TOPIC;
        }

        $parent = get_post($post->post_parent);

        if (! $parent instanceof WP_Post) {
            return ContentNodeType::TOPIC;
        }

        $parent_type = ContentNodeType::tryFrom((string) get_post_meta($parent->ID, self::META_TYPE, true))
            ?? $this->inferNodeTypeFromParent($parent->ID);

        return match ($parent_type) {
            ContentNodeType::TOPIC => ContentNodeType::CHAPTER,
            ContentNodeType::CHAPTER => ContentNodeType::SECTION,
            ContentNodeType::SECTION => ContentNodeType::LESSON,
            ContentNodeType::LESSON => ContentNodeType::LESSON,
        };
    }

    private function writeNodeMeta(
        int $post_id,
        ContentNodeType $type,
        ContentStatus $status,
        string $content_type,
        string $schema_version,
        array $metadata
    ): void {
        update_post_meta($post_id, self::META_TYPE, $type->value);
        update_post_meta($post_id, self::META_STATUS, $status->value);
        update_post_meta($post_id, self::META_CONTENT_TYPE, $content_type);
        update_post_meta($post_id, self::META_SCHEMA_VERSION, $schema_version);
        update_post_meta($post_id, self::META_METADATA, $metadata);
    }

    private function inferStatusFromPostStatus(string $post_status): ContentStatus
    {
        return match ($post_status) {
            'publish' => ContentStatus::PUBLISHED,
            default => ContentStatus::DRAFT,
        };
    }

    private function requireLesson(string $lesson_id): ContentNode
    {
        $lesson = $this->getNode($lesson_id);

        if (! $lesson || ContentNodeType::LESSON !== $lesson->type) {
            throw new InvalidArgumentException(__('Lesson was not found.', 'contents-editor'));
        }

        return $lesson;
    }

    /**
     * @param array{topics: int, chapters: int, sections: int, lessons: int} $deleted
     */
    private function deleteNodeRecursive(ContentNode $node, array &$deleted): void
    {
        $children = $this->listNodes(
            [
                'parentId' => $node->id,
                'includeArchived' => true,
            ]
        );

        foreach ($children as $child) {
            $this->deleteNodeRecursive($child, $deleted);
        }

        if (ContentNodeType::LESSON === $node->type) {
            delete_post_meta((int) $node->id, self::META_LESSON_DOCUMENT);
        }

        $result = wp_delete_post((int) $node->id, true);

        if (! $result) {
            throw new RuntimeException(__('Content node could not be deleted.', 'contents-editor'));
        }

        match ($node->type) {
            ContentNodeType::TOPIC => ++$deleted['topics'],
            ContentNodeType::CHAPTER => ++$deleted['chapters'],
            ContentNodeType::SECTION => ++$deleted['sections'],
            ContentNodeType::LESSON => ++$deleted['lessons'],
        };
    }

    private function readType(mixed $value): ContentNodeType
    {
        $type = ContentNodeType::tryFrom((string) $value);

        if (! $type) {
            throw new InvalidArgumentException(__('Invalid content node type.', 'contents-editor'));
        }

        return $type;
    }

    private function readStatus(mixed $value): ContentStatus
    {
        $status = ContentStatus::tryFrom((string) $value);

        if (! $status) {
            throw new InvalidArgumentException(__('Invalid content status.', 'contents-editor'));
        }

        return $status;
    }

    private function readNullableId(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $id = $this->toPostId($value);

        if ($id <= 0) {
            throw new InvalidArgumentException(__('Invalid parent content node.', 'contents-editor'));
        }

        return (string) $id;
    }

    private function readTitle(mixed $value): string
    {
        $title = trim(sanitize_text_field((string) $value));

        if ('' === $title) {
            throw new InvalidArgumentException(__('Content title is required.', 'contents-editor'));
        }

        return $title;
    }

    private function readOrder(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private function readString(mixed $value, string $fallback): string
    {
        $string = trim(sanitize_text_field((string) $value));

        return '' !== $string ? $string : $fallback;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function readArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function toPostId(mixed $value): int
    {
        return max(0, absint($value));
    }

    private function mapDomainStatusToPostStatus(ContentStatus $status): string
    {
        return ContentStatus::PUBLISHED === $status ? 'publish' : 'draft';
    }

    private function assertParentAllowed(ContentNodeType $type, ?string $parent_id): void
    {
        $expected_parent = match ($type) {
            ContentNodeType::TOPIC => null,
            ContentNodeType::CHAPTER => ContentNodeType::TOPIC,
            ContentNodeType::SECTION => ContentNodeType::CHAPTER,
            ContentNodeType::LESSON => ContentNodeType::SECTION,
        };

        if (null === $expected_parent) {
            if (null !== $parent_id) {
                throw new InvalidArgumentException(__('Topic cannot have a parent.', 'contents-editor'));
            }

            return;
        }

        $parent = $parent_id ? $this->getNode($parent_id) : null;

        if (! $parent || $expected_parent !== $parent->type) {
            throw new InvalidArgumentException(__('Content node parent does not match the required hierarchy.', 'contents-editor'));
        }
    }

    private function assertNoParentCycle(string $id, ?string $parent_id): void
    {
        $cursor = $parent_id;

        while ($cursor) {
            if ((string) $id === (string) $cursor) {
                throw new InvalidArgumentException(__('Content hierarchy cannot contain a cycle.', 'contents-editor'));
            }

            $parent = $this->getNode($cursor);
            $cursor = $parent ? $parent->parentId : null;
        }
    }
}
