<?php

declare(strict_types=1);

namespace ReadContents\Library\Editor\PublicSite;

use ReadContents\Library\Addon\AddonRoutes;

final class LessonOutlineRenderer
{
    /** @param array<string, mixed> $tree */
    public function render(array $tree, string $currentLessonId): string
    {
        $html = '<h2 class="rcis-reader__outline-heading">목차</h2>';
        $topic = is_array($tree['topic'] ?? null) ? $tree['topic'] : [];

        if ('published' !== ($topic['status'] ?? '')) {
            return $html . '<p>공개된 목차가 없습니다.</p>';
        }

        $chapters = $this->publishedChapters($tree);

        if ([] === $chapters) {
            return $html . '<p>공개된 목차가 없습니다.</p>';
        }

        $open_chapter = 0;

        foreach ($chapters as $index => $chapter_group) {
            if ($this->containsLesson($chapter_group, $currentLessonId)) {
                $open_chapter = $index;
                break;
            }
        }

        $topic_slug = (string) ($topic['slug'] ?? '');
        $html .= '<a class="rcis-reader__topic" href="'
            . esc_url(home_url(AddonRoutes::topicOverviewPath($topic_slug))) . '">'
            . esc_html((string) ($topic['title'] ?? '읽기자료 주제')) . '</a>';
        $html .= '<div class="rcis-reader__chapters">';

        foreach ($chapters as $index => $chapter_group) {
            $html .= $this->renderChapter($chapter_group, $currentLessonId, $index === $open_chapter);
        }

        return $html . '</div>';
    }

    /**
     * @param array<string, mixed> $tree
     * @return list<array<string, mixed>>
     */
    private function publishedChapters(array $tree): array
    {
        $chapters = [];

        foreach ((array) ($tree['chapters'] ?? []) as $chapter_group) {
            if (! is_array($chapter_group)) {
                continue;
            }

            $chapter = is_array($chapter_group['chapter'] ?? null) ? $chapter_group['chapter'] : [];

            if ('published' !== ($chapter['status'] ?? '')) {
                continue;
            }

            $sections = [];

            foreach ((array) ($chapter_group['sections'] ?? []) as $section_group) {
                if (! is_array($section_group)) {
                    continue;
                }

                $section = is_array($section_group['section'] ?? null) ? $section_group['section'] : [];

                if ('published' !== ($section['status'] ?? '')) {
                    continue;
                }

                $lessons = array_values(array_filter(
                    (array) ($section_group['lessons'] ?? []),
                    static fn (mixed $lesson): bool => is_array($lesson) && 'published' === ($lesson['status'] ?? '')
                ));

                if ([] !== $lessons) {
                    $sections[] = [
                        'section' => $section,
                        'lessons' => $lessons,
                    ];
                }
            }

            if ([] !== $sections) {
                $chapters[] = [
                    'chapter' => $chapter,
                    'sections' => $sections,
                ];
            }
        }

        return $chapters;
    }

    /** @param array<string, mixed> $chapter_group */
    private function containsLesson(array $chapter_group, string $currentLessonId): bool
    {
        if ('' === $currentLessonId) {
            return false;
        }

        foreach ($chapter_group['sections'] as $section_group) {
            foreach ($section_group['lessons'] as $lesson) {
                if ($currentLessonId === (string) ($lesson['id'] ?? '')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $chapter_group */
    private function renderChapter(array $chapter_group, string $currentLessonId, bool $open): string
    {
        $chapter = $chapter_group['chapter'];
        $html = '<details class="rcis-reader__chapter"' . ($open ? ' open' : '') . '>';
        $html .= '<summary class="rcis-reader__chapter-title">'
            . esc_html((string) ($chapter['title'] ?? '장')) . '</summary>';

        foreach ($chapter_group['sections'] as $section_group) {
            $html .= $this->renderSection($section_group, $currentLessonId);
        }

        return $html . '</details>';
    }

    /** @param array<string, mixed> $section_group */
    private function renderSection(array $section_group, string $currentLessonId): string
    {
        $section_title = trim((string) ($section_group['section']['title'] ?? ''));
        $html = '<div class="rcis-reader__section">';

        if ('' !== $section_title) {
            $html .= '<h3 class="rcis-reader__section-title">' . esc_html($section_title) . '</h3>';
        }

        $html .= '<ol class="rcis-reader__lessons">';

        foreach ($section_group['lessons'] as $lesson) {
            $slug = (string) ($lesson['slug'] ?? '');
            $current = '' !== $currentLessonId && $currentLessonId === (string) ($lesson['id'] ?? '');
            $html .= '<li><a class="rcis-reader__lesson" href="'
                . esc_url(home_url(AddonRoutes::topicPath($slug))) . '"'
                . ($current ? ' aria-current="page"' : '') . '>'
                . esc_html((string) ($lesson['title'] ?? $slug)) . '</a></li>';
        }

        return $html . '</ol></div>';
    }
}
