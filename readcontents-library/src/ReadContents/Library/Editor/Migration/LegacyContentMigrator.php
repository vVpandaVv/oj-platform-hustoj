<?php
/**
 * Imports the bundled reading corpus into editable WordPress content nodes.
 *
 * @package ReadContentsLibrary\Editor\Migration
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\Migration;

use ReadContents\Library\Content\LessonRepository;
use ReadContents\Library\Editor\Content\ContentNode;
use ReadContents\Library\Editor\Content\ContentNodeType;
use ReadContents\Library\Editor\Content\ContentPostType;
use ReadContents\Library\Editor\Storage\ContentRepository;
use Throwable;

final class LegacyContentMigrator
{
    private const MIGRATION_OPTION = 'rcis_editor_migration_version';
    private const MIGRATION_VERSION = '3.0.0';
    private const TOPIC_SLUG = 'information-science-reading';

    public static function register(): void
    {
        add_action('init', [self::class, 'maybeMigrate'], 30);
    }

    public static function activate(): void
    {
        ContentPostType::registerPostType();
        self::migrate();
    }

    public static function maybeMigrate(): void
    {
        if (self::MIGRATION_VERSION === (string) get_option(self::MIGRATION_OPTION, '')) {
            return;
        }

        try {
            self::migrate();
        } catch (Throwable $exception) {
            error_log('[readcontents-library] 콘텐츠 자동 이관 실패: ' . $exception->getMessage());
        }
    }

    public static function migrate(): void
    {
        if (self::MIGRATION_VERSION === (string) get_option(self::MIGRATION_OPTION, '')) {
            return;
        }

        $legacy = new LessonRepository();
        $index = $legacy->index();

        if (! is_array($index['chapters'] ?? null)) {
            return;
        }

        $repository = new ContentRepository();
        $topic = self::findOrCreate(
            $repository,
            self::TOPIC_SLUG,
            [
                'type' => ContentNodeType::TOPIC->value,
                'title' => '정보과학 이야기 읽기자료',
                'slug' => self::TOPIC_SLUG,
                'status' => 'published',
                'metadata' => [
                    'legacySource' => 'bundled-readcontents-library',
                ],
            ]
        );

        foreach (array_values($index['chapters']) as $chapter_index => $chapter) {
            if (! is_array($chapter)) {
                continue;
            }

            $chapter_slug = sanitize_title((string) ($chapter['id'] ?? 'chapter-' . ($chapter_index + 1)));
            $chapter_node = self::findOrCreate(
                $repository,
                $chapter_slug,
                [
                    'type' => ContentNodeType::CHAPTER->value,
                    'parentId' => $topic->id,
                    'title' => (string) ($chapter['title'] ?? '챕터 ' . ($chapter_index + 1)),
                    'slug' => $chapter_slug,
                    'order' => (int) ($chapter['order'] ?? $chapter_index + 1),
                    'status' => 'published',
                    'metadata' => [
                        'legacyId' => (string) ($chapter['id'] ?? ''),
                        'summary' => (string) ($chapter['summary'] ?? ''),
                    ],
                ]
            );

            foreach (array_values((array) ($chapter['sections'] ?? [])) as $section_index => $section) {
                if (! is_array($section)) {
                    continue;
                }

                $section_slug = sanitize_title((string) ($section['id'] ?? $chapter_slug . '-section-' . ($section_index + 1)));
                $section_node = self::findOrCreate(
                    $repository,
                    $section_slug,
                    [
                        'type' => ContentNodeType::SECTION->value,
                        'parentId' => $chapter_node->id,
                        'title' => (string) ($section['title'] ?? '장 ' . ($section_index + 1)),
                        'slug' => $section_slug,
                        'order' => $section_index + 1,
                        'status' => 'published',
                        'metadata' => [
                            'legacyId' => (string) ($section['id'] ?? ''),
                        ],
                    ]
                );

                foreach (array_values((array) ($section['lessons'] ?? [])) as $lesson_index => $lesson_summary) {
                    if (! is_array($lesson_summary)) {
                        continue;
                    }

                    $legacy_topic_id = LessonRepository::normalizeTopicId((string) ($lesson_summary['topicId'] ?? ''));

                    if ('' === $legacy_topic_id) {
                        continue;
                    }

                    $lesson_data = $legacy->lesson($legacy_topic_id);

                    if (! $lesson_data) {
                        continue;
                    }

                    $lesson_node = self::findOrCreate(
                        $repository,
                        $legacy_topic_id,
                        [
                            'type' => ContentNodeType::LESSON->value,
                            'parentId' => $section_node->id,
                            'title' => self::lessonTitle($lesson_data, (string) ($lesson_summary['title'] ?? $legacy_topic_id)),
                            'slug' => $legacy_topic_id,
                            'order' => (int) ($lesson_summary['order'] ?? $lesson_index + 1),
                            'status' => 'published' === (string) ($lesson_data['status'] ?? 'draft') ? 'published' : 'draft',
                            'metadata' => [
                                'legacyId' => (string) ($lesson_summary['lessonId'] ?? ''),
                                'legacyTopicId' => $legacy_topic_id,
                            ],
                        ]
                    );

                    $document = $repository->getLessonDocument($lesson_node->id);

                    if ($document->revision <= 1) {
                        $repository->saveLessonDocument(
                            $lesson_node->id,
                            LegacyBlockConverter::convert((array) ($lesson_data['blocks'] ?? []), $legacy_topic_id)
                        );
                    }
                }
            }
        }

        update_option(self::MIGRATION_OPTION, self::MIGRATION_VERSION, false);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function findOrCreate(ContentRepository $repository, string $slug, array $payload): ContentNode
    {
        $existing = $repository->getNodeBySlug($slug);

        return $existing ?? $repository->createNode($payload);
    }

    /** @param array<string, mixed> $lesson */
    private static function lessonTitle(array $lesson, string $fallback): string
    {
        foreach ((array) ($lesson['blocks'] ?? []) as $block) {
            if (is_array($block) && 'h1' === ($block['type'] ?? null)) {
                $title = trim((string) ($block['text'] ?? ''));

                if ('' !== $title) {
                    return $title;
                }
            }
        }

        return $fallback;
    }
}
