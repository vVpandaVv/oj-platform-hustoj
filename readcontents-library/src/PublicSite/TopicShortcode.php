<?php
/**
 * Public topic shortcode with navigable lesson list.
 *
 * @package ContentsEditor\PublicSite
 */

declare(strict_types=1);

namespace ContentsEditor\PublicSite;

use ContentsEditor\Content\ContentNodeType;
use ContentsEditor\Content\ContentStatus;
use ContentsEditor\Infrastructure\Assets;
use ContentsEditor\Storage\ContentRepository;
use Throwable;

final class TopicShortcode
{
    public function __construct(
        private readonly Assets $assets,
        private readonly ContentRepository $repository = new ContentRepository(),
        private readonly PlateHtmlRenderer $renderer = new PlateHtmlRenderer()
    ) {
    }

    public function register(): void
    {
        add_shortcode('contents_editor_topic', [$this, 'render']);
    }

    /**
     * @param array<string, mixed>|string $attributes
     */
    public function render(array|string $attributes = []): string
    {
        $attributes = shortcode_atts(
            [
                'id' => 0,
                'lesson' => 0,
            ],
            is_array($attributes) ? $attributes : [],
            'contents_editor_topic'
        );

        $topic_id = absint($attributes['id'] ?? 0);

        if ($topic_id <= 0) {
            return $this->notice(__('주제 ID가 필요합니다.', 'contents-editor'));
        }

        try {
            $can_manage = current_user_can(CONTENTS_EDITOR_CAPABILITY);
            $topic = $this->repository->getNode((string) $topic_id);

            if (! $topic || ContentNodeType::TOPIC !== $topic->type) {
                return $this->notice(__('주제를 찾을 수 없습니다.', 'contents-editor'));
            }

            if (! $can_manage && ContentStatus::PUBLISHED !== $topic->status) {
                return $this->notice(__('아직 공개되지 않은 주제입니다.', 'contents-editor'));
            }

            $tree = $this->repository->buildTopicTree((string) $topic_id);

            if (! $can_manage) {
                $tree = $this->publishedTree($tree);
            }

            $lessons = $this->flattenLessons($tree);
            $selected_id = $this->selectedLessonId($attributes);
            $selected_lesson = $selected_id > 0 ? $this->findLesson($lessons, (string) $selected_id) : null;
            $selected_lesson ??= $lessons[0] ?? null;

            $this->assets->enqueuePublic();

            return $this->renderTopic($tree, $lessons, $selected_lesson, $can_manage);
        } catch (Throwable $throwable) {
            return $this->notice($throwable->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function publishedTree(array $tree): array
    {
        $tree['chapters'] = array_values(
            array_filter(
                array_map(
                    function (array $chapter_group): ?array {
                        $chapter = $chapter_group['chapter'] ?? null;

                        if (! is_array($chapter) || ! $this->isPublished($chapter)) {
                            return null;
                        }

                        $chapter_group['sections'] = array_values(
                            array_filter(
                                array_map(
                                    function (array $section_group): ?array {
                                        $section = $section_group['section'] ?? null;

                                        if (! is_array($section) || ! $this->isPublished($section)) {
                                            return null;
                                        }

                                        $lessons = is_array($section_group['lessons'] ?? null)
                                            ? $section_group['lessons']
                                            : [];

                                        $section_group['lessons'] = array_values(
                                            array_filter(
                                                $lessons,
                                                fn (mixed $lesson): bool => is_array($lesson) && $this->isPublished($lesson)
                                            )
                                        );

                                        return $section_group;
                                    },
                                    is_array($chapter_group['sections'] ?? null) ? $chapter_group['sections'] : []
                                )
                            )
                        );

                        return $chapter_group;
                    },
                    is_array($tree['chapters'] ?? null) ? $tree['chapters'] : []
                )
            )
        );

        return $tree;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isPublished(array $node): bool
    {
        return ContentStatus::PUBLISHED->value === (string) ($node['status'] ?? '');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function selectedLessonId(array $attributes): int
    {
        $attribute_lesson = absint($attributes['lesson'] ?? 0);

        if ($attribute_lesson > 0) {
            return $attribute_lesson;
        }

        $query_lesson = isset($_GET['ce_lesson']) ? wp_unslash($_GET['ce_lesson']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return absint(is_scalar($query_lesson) ? (string) $query_lesson : 0);
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<int, array<string, mixed>>
     */
    private function flattenLessons(array $tree): array
    {
        $lessons = [];

        foreach (is_array($tree['chapters'] ?? null) ? $tree['chapters'] : [] as $chapter_group) {
            foreach (is_array($chapter_group['sections'] ?? null) ? $chapter_group['sections'] : [] as $section_group) {
                foreach (is_array($section_group['lessons'] ?? null) ? $section_group['lessons'] : [] as $lesson) {
                    if (is_array($lesson)) {
                        $lessons[] = $lesson;
                    }
                }
            }
        }

        return $lessons;
    }

    /**
     * @param array<int, array<string, mixed>> $lessons
     * @return array<string, mixed>|null
     */
    private function findLesson(array $lessons, string $lesson_id): ?array
    {
        foreach ($lessons as $lesson) {
            if ((string) ($lesson['id'] ?? '') === $lesson_id) {
                return $lesson;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<int, array<string, mixed>> $lessons
     * @param array<string, mixed>|null $selected_lesson
     */
    private function renderTopic(array $tree, array $lessons, ?array $selected_lesson, bool $can_manage): string
    {
        $topic = is_array($tree['topic'] ?? null) ? $tree['topic'] : [];
        $topic_id = (string) ($topic['id'] ?? '');
        $topic_title = (string) ($topic['title'] ?? __('콘텐츠 주제', 'contents-editor'));
        $selected_lesson_id = (string) ($selected_lesson['id'] ?? '');
        $lesson_html = '';

        if ($selected_lesson) {
            $document = $this->repository->getLessonDocument($selected_lesson_id);
            $lesson_html = sprintf(
                '<article class="contents-editor-topic-reader" data-lesson-id="%1$s" data-schema-version="%2$s"><header class="contents-editor-topic-reader__header"><p>%3$s</p><h2>%4$s</h2></header>%5$s</article>',
                esc_attr($selected_lesson_id),
                esc_attr($document->schemaVersion),
                esc_html(__('선택한 레슨', 'contents-editor')),
                esc_html((string) ($selected_lesson['title'] ?? '')),
                $this->renderer->render($document->plateValue)
            );
        } else {
            $lesson_html = $this->notice(__('공개된 레슨이 없습니다.', 'contents-editor'));
        }

        $navigation_id = $this->elementId('contents-editor-topic-nav', $topic_id);

        return sprintf(
            '<section class="contents-editor-public contents-editor-public-topic" data-topic-id="%1$s" data-contents-editor-topic-menu-open="false"><header class="contents-editor-topic-header"><p>%2$s</p><h1>%3$s</h1>%4$s</header><button class="contents-editor-topic-menu-button" type="button" aria-controls="%5$s" aria-expanded="false" data-contents-editor-topic-menu-button><span>%6$s</span><span class="contents-editor-topic-menu-button__icon" aria-hidden="true">+</span></button><div class="contents-editor-topic-layout"><nav id="%5$s" class="contents-editor-topic-nav" aria-label="%7$s" data-contents-editor-topic-nav>%8$s</nav>%9$s</div></section>',
            esc_attr($topic_id),
            esc_html(__('콘텐츠 주제', 'contents-editor')),
            esc_html($topic_title),
            $can_manage ? '<p class="contents-editor-topic-admin-note">' . esc_html(sprintf(__('관리자 미리보기: 주제 ID %s', 'contents-editor'), $topic_id)) . '</p>' : '',
            esc_attr($navigation_id),
            esc_html__('목차 보기', 'contents-editor'),
            esc_attr__('콘텐츠 목차', 'contents-editor'),
            $this->renderNavigation($tree, $selected_lesson_id, $can_manage),
            $lesson_html
        );
    }

    /**
     * @param array<string, mixed> $tree
     */
    private function renderNavigation(array $tree, string $selected_lesson_id, bool $can_manage): string
    {
        $html = '<h2>' . esc_html__('목차', 'contents-editor') . '</h2>';

        if (empty($tree['chapters']) || ! is_array($tree['chapters'])) {
            return $html . '<p class="contents-editor-topic-nav__empty">' . esc_html__('등록된 목차가 없습니다.', 'contents-editor') . '</p>';
        }

        $html .= '<ol class="contents-editor-topic-nav__chapters">';

        foreach ($tree['chapters'] as $chapter_index => $chapter_group) {
            if (! is_array($chapter_group)) {
                continue;
            }

            $chapter = is_array($chapter_group['chapter'] ?? null) ? $chapter_group['chapter'] : [];
            $chapter_id = $this->elementId('contents-editor-topic-chapter', (string) ($chapter['id'] ?? $chapter_index));
            $chapter_sections_id = $chapter_id . '-sections';
            $chapter_is_open = $this->chapterHasLesson($chapter_group, $selected_lesson_id);

            $html .= sprintf(
                '<li class="contents-editor-topic-nav__item" data-contents-editor-topic-nav-item><button class="contents-editor-topic-nav__toggle contents-editor-topic-nav__chapter" type="button" aria-expanded="%1$s" aria-controls="%2$s" data-contents-editor-topic-branch-toggle><span>%3$s%4$s</span><span class="contents-editor-topic-nav__toggle-icon" aria-hidden="true">%5$s</span></button>',
                esc_attr($chapter_is_open ? 'true' : 'false'),
                esc_attr($chapter_sections_id),
                esc_html((string) ($chapter['title'] ?? '')),
                $this->adminStatus($chapter, $can_manage),
                esc_html($chapter_is_open ? '-' : '+')
            );
            $html .= sprintf(
                '<ol id="%1$s" class="contents-editor-topic-nav__sections"%2$s>',
                esc_attr($chapter_sections_id),
                $chapter_is_open ? '' : ' hidden'
            );

            foreach (is_array($chapter_group['sections'] ?? null) ? $chapter_group['sections'] : [] as $section_index => $section_group) {
                if (! is_array($section_group)) {
                    continue;
                }

                $section = is_array($section_group['section'] ?? null) ? $section_group['section'] : [];
                $section_id = $this->elementId('contents-editor-topic-section', (string) ($section['id'] ?? $chapter_index . '-' . $section_index));
                $section_lessons_id = $section_id . '-lessons';
                $section_is_open = $this->sectionHasLesson($section_group, $selected_lesson_id);

                $html .= sprintf(
                    '<li class="contents-editor-topic-nav__item" data-contents-editor-topic-nav-item><button class="contents-editor-topic-nav__toggle contents-editor-topic-nav__section" type="button" aria-expanded="%1$s" aria-controls="%2$s" data-contents-editor-topic-branch-toggle><span>%3$s%4$s</span><span class="contents-editor-topic-nav__toggle-icon" aria-hidden="true">%5$s</span></button>',
                    esc_attr($section_is_open ? 'true' : 'false'),
                    esc_attr($section_lessons_id),
                    esc_html((string) ($section['title'] ?? '')),
                    $this->adminStatus($section, $can_manage),
                    esc_html($section_is_open ? '-' : '+')
                );
                $html .= sprintf(
                    '<ol id="%1$s" class="contents-editor-topic-nav__lessons"%2$s>',
                    esc_attr($section_lessons_id),
                    $section_is_open ? '' : ' hidden'
                );

                foreach (is_array($section_group['lessons'] ?? null) ? $section_group['lessons'] : [] as $lesson) {
                    if (! is_array($lesson)) {
                        continue;
                    }

                    $lesson_id = (string) ($lesson['id'] ?? '');
                    $html .= sprintf(
                        '<li><a class="%1$s" href="%2$s">%3$s%4$s</a></li>',
                        esc_attr($lesson_id === $selected_lesson_id ? 'is-active' : ''),
                        esc_url(add_query_arg('ce_lesson', rawurlencode($lesson_id), remove_query_arg('ce_lesson'))),
                        esc_html((string) ($lesson['title'] ?? '')),
                        $this->adminStatus($lesson, $can_manage)
                    );
                }

                $html .= '</ol></li>';
            }

            $html .= '</ol></li>';
        }

        return $html . '</ol>';
    }

    /**
     * @param array<string, mixed> $chapter_group
     */
    private function chapterHasLesson(array $chapter_group, string $lesson_id): bool
    {
        foreach (is_array($chapter_group['sections'] ?? null) ? $chapter_group['sections'] : [] as $section_group) {
            if (is_array($section_group) && $this->sectionHasLesson($section_group, $lesson_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $section_group
     */
    private function sectionHasLesson(array $section_group, string $lesson_id): bool
    {
        foreach (is_array($section_group['lessons'] ?? null) ? $section_group['lessons'] : [] as $lesson) {
            if (is_array($lesson) && (string) ($lesson['id'] ?? '') === $lesson_id) {
                return true;
            }
        }

        return false;
    }

    private function elementId(string $prefix, string $id): string
    {
        $safe_id = sanitize_key($id);

        return $prefix . '-' . ('' !== $safe_id ? $safe_id : md5($id));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function adminStatus(array $node, bool $can_manage): string
    {
        if (! $can_manage) {
            return '';
        }

        $status = (string) ($node['status'] ?? '');
        $id = (string) ($node['id'] ?? '');

        return ' <span class="contents-editor-topic-nav__meta">' . esc_html(sprintf('ID %s · %s', $id, $status)) . '</span>';
    }

    private function notice(string $message): string
    {
        return '<div class="contents-editor-public-notice">' . esc_html($message) . '</div>';
    }
}
