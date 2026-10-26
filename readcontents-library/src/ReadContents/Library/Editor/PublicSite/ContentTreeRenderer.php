<?php
/**
 * Public library index renderer — one card per topic, linking to that
 * topic's own overview page instead of dumping every chapter/section/lesson
 * onto a single page (which stopped scaling once the library grew past a
 * handful of topics).
 *
 * @package ReadContentsLibrary\Editor\PublicSite
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\PublicSite;

use ReadContents\Library\Addon\AddonRoutes;
use ReadContents\Library\Addon\PageResponse;

final class ContentTreeRenderer
{
    /** @param array<int, array<string, mixed>> $trees */
    public function render(array $trees): string
    {
        $trees = array_values(array_filter($trees, [$this, 'hasPublishedLessons']));

        if (! $trees) {
            return '<div class="rcis-library">'
                . PageResponse::header('읽기자료', '')
                . PageResponse::emptyState(
                    '공개된 읽기자료가 없습니다',
                    '선생님이 읽기자료를 공개하면 여기에 나타납니다.'
                )
                . '</div>';
        }

        $lesson_count = 0;

        foreach ($trees as $tree) {
            $lesson_count += $this->lessonCount($tree);
        }

        $html = '<div class="rcis-library">';
        $html .= PageResponse::header(
            '읽기자료',
            '주제 ' . count($trees) . '개, 레슨 ' . $lesson_count . '개를 읽을 수 있습니다.'
        );

        $html .= '<div class="rcis-topic-index-grid">';

        foreach ($trees as $tree) {
            $html .= $this->renderTopicCard($tree);
        }

        return $html . '</div></div>';
    }

    /** @param array<string, mixed> $tree */
    private function renderTopicCard(array $tree): string
    {
        $topic = is_array($tree['topic'] ?? null) ? $tree['topic'] : [];
        $title = (string) ($topic['title'] ?? '읽기자료 주제');
        $slug = (string) ($topic['slug'] ?? '');
        $chapter_count = $this->publishedChapterCount($tree);
        $lesson_count = $this->lessonCount($tree);

        $html = '<a class="rcis-topic-index-card" href="' . esc_url(home_url(AddonRoutes::topicOverviewPath($slug))) . '">';
        $html .= '<span class="rcis-topic-index-card__label">주제</span>';
        $html .= '<h2 class="rcis-topic-index-card__title">' . esc_html($title) . '</h2>';
        $html .= '<p class="rcis-topic-index-card__meta">'
            . esc_html('장 ' . $chapter_count . '개 · 레슨 ' . $lesson_count . '개')
            . '</p>';
        $html .= '<span class="rcis-topic-index-card__cta">둘러보기'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6"/></svg>'
            . '</span>';

        return $html . '</a>';
    }

    /** @param array<string, mixed> $tree */
    private function hasPublishedLessons(array $tree): bool
    {
        return $this->lessonCount($tree) > 0;
    }

    /** @param array<string, mixed> $tree */
    private function publishedChapterCount(array $tree): int
    {
        $count = 0;

        foreach ((array) ($tree['chapters'] ?? []) as $chapter_group) {
            if (is_array($chapter_group) && $this->chapterHasPublishedLessons($chapter_group)) {
                ++$count;
            }
        }

        return $count;
    }

    /** @param array<string, mixed> $chapter_group */
    private function chapterHasPublishedLessons(array $chapter_group): bool
    {
        if ('published' !== (($chapter_group['chapter']['status'] ?? ''))) {
            return false;
        }

        foreach ((array) ($chapter_group['sections'] ?? []) as $section_group) {
            if (! is_array($section_group) || 'published' !== (($section_group['section']['status'] ?? ''))) {
                continue;
            }

            foreach ((array) ($section_group['lessons'] ?? []) as $lesson) {
                if (is_array($lesson) && 'published' === ($lesson['status'] ?? '')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $tree */
    private function lessonCount(array $tree): int
    {
        $count = 0;

        foreach ((array) ($tree['chapters'] ?? []) as $chapter_group) {
            if (! is_array($chapter_group) || 'published' !== (($chapter_group['chapter']['status'] ?? ''))) {
                continue;
            }

            foreach ((array) ($chapter_group['sections'] ?? []) as $section_group) {
                if (! is_array($section_group) || 'published' !== (($section_group['section']['status'] ?? ''))) {
                    continue;
                }

                foreach ((array) ($section_group['lessons'] ?? []) as $lesson) {
                    if (is_array($lesson) && 'published' === ($lesson['status'] ?? '')) {
                        ++$count;
                    }
                }
            }
        }

        return $count;
    }
}
