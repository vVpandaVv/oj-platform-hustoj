<?php
/**
 * Renders a single topic's chapter/section/lesson outline as a collapsed
 * accordion, so the reader can scan chapter titles first instead of
 * scrolling past every lesson in the topic at once.
 *
 * @package ReadContentsLibrary\Editor\PublicSite
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\PublicSite;

use ReadContents\Library\Addon\AddonRoutes;
use ReadContents\Library\Addon\PageResponse;

final class TopicOverviewRenderer
{
    /** @param array<string, mixed> $tree */
    public function render(array $tree): string
    {
        $topic = is_array($tree['topic'] ?? null) ? $tree['topic'] : [];
        $title = (string) ($topic['title'] ?? '읽기자료 주제');
        $chapters = array_values(array_filter(
            (array) ($tree['chapters'] ?? []),
            fn (mixed $group): bool => is_array($group) && $this->chapterHasPublishedLessons($group)
        ));

        $html = '<div class="rcis-library rcis-topic-overview">';
        $html .= '<nav class="ojp-breadcrumb rcis-breadcrumb" aria-label="읽기자료 경로">'
            . '<a href="' . esc_url(PageResponse::libraryUrl()) . '">읽기자료</a>'
            . '<span class="rcis-breadcrumb__sep" aria-hidden="true">›</span>'
            . '<span aria-current="page">' . esc_html($title) . '</span>'
            . '</nav>';
        $html .= PageResponse::header($title, $chapters ? '장 ' . count($chapters) . '개' : '');

        if (! $chapters) {
            $html .= PageResponse::emptyState(
                '아직 공개된 레슨이 없습니다',
                '이 주제의 레슨이 공개되면 여기에 나타납니다.',
                PageResponse::libraryUrl(),
                '읽기자료 목록으로 돌아가기'
            );

            return $html . '</div>';
        }

        $html .= '<div class="rcis-chapter-accordion">';

        foreach ($chapters as $index => $chapter_group) {
            $html .= $this->renderChapter($chapter_group, 0 === $index);
        }

        return $html . '</div></div>';
    }

    /** @param array<string, mixed> $chapter_group */
    private function renderChapter(array $chapter_group, bool $openByDefault): string
    {
        $chapter = is_array($chapter_group['chapter'] ?? null) ? $chapter_group['chapter'] : [];
        $sections = array_values(array_filter(
            (array) ($chapter_group['sections'] ?? []),
            fn (mixed $group): bool => is_array($group) && $this->sectionHasPublishedLessons($group)
        ));
        $lesson_count = array_sum(array_map(
            fn (array $section_group): int => count($this->publishedLessons($section_group)),
            $sections
        ));

        $html = '<details class="rcis-chapter-accordion__item"' . ($openByDefault ? ' open' : '') . '>';
        $html .= '<summary>';
        $html .= '<span class="rcis-chapter-label">' . esc_html((string) ($chapter['order'] ?? '')) . '</span>';
        $html .= '<span class="rcis-chapter-accordion__title">' . esc_html((string) ($chapter['title'] ?? '장')) . '</span>';
        $html .= '<span class="rcis-chapter-accordion__meta">' . esc_html('레슨 ' . $lesson_count . '개') . '</span>';
        $html .= '</summary>';
        $html .= '<div class="rcis-chapter-accordion__body">';

        foreach ($sections as $section_group) {
            $html .= $this->renderSection($section_group);
        }

        return $html . '</div></details>';
    }

    /** @param array<string, mixed> $section_group */
    private function renderSection(array $section_group): string
    {
        $section = is_array($section_group['section'] ?? null) ? $section_group['section'] : [];
        $lessons = $this->publishedLessons($section_group);

        $section_title = trim((string) ($section['title'] ?? ''));

        $html = '<section class="rcis-section-outline">';

        // A section is a grouping inside a 장; it has no UI term of its own
        // (D-010 11장: 주제 › 장 › 레슨), so an untitled one gets no heading.
        if ('' !== $section_title) {
            $html .= '<h3 class="rcis-section-outline__title">' . esc_html($section_title) . '</h3>';
        }

        $html .= '<ol class="rcis-section-outline__lessons">';

        foreach ($lessons as $lesson) {
            $slug = (string) ($lesson['slug'] ?? '');
            $html .= '<li><a href="' . esc_url(home_url(AddonRoutes::topicPath($slug))) . '">'
                . esc_html((string) ($lesson['title'] ?? $slug))
                . '</a></li>';
        }

        return $html . '</ol></section>';
    }

    /** @param array<string, mixed> $chapter_group */
    private function chapterHasPublishedLessons(array $chapter_group): bool
    {
        if ('published' !== (($chapter_group['chapter']['status'] ?? ''))) {
            return false;
        }

        foreach ((array) ($chapter_group['sections'] ?? []) as $section_group) {
            if (is_array($section_group) && $this->sectionHasPublishedLessons($section_group)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $section_group */
    private function sectionHasPublishedLessons(array $section_group): bool
    {
        if ('published' !== (($section_group['section']['status'] ?? ''))) {
            return false;
        }

        return [] !== $this->publishedLessons($section_group);
    }

    /**
     * @param array<string, mixed> $section_group
     * @return list<array<string, mixed>>
     */
    private function publishedLessons(array $section_group): array
    {
        return array_values(
            array_filter(
                (array) ($section_group['lessons'] ?? []),
                static fn (mixed $lesson): bool => is_array($lesson) && 'published' === ($lesson['status'] ?? '')
            )
        );
    }
}
