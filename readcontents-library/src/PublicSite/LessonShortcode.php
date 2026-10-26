<?php
/**
 * Public lesson shortcode.
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

final class LessonShortcode
{
    public function __construct(
        private readonly Assets $assets,
        private readonly ContentRepository $repository = new ContentRepository(),
        private readonly PlateHtmlRenderer $renderer = new PlateHtmlRenderer()
    ) {
    }

    public function register(): void
    {
        add_shortcode('contents_editor_lesson', [$this, 'render']);
    }

    /**
     * @param array<string, mixed>|string $attributes
     */
    public function render(array|string $attributes = []): string
    {
        $attributes = shortcode_atts(
            [
                'id' => 0,
            ],
            is_array($attributes) ? $attributes : [],
            'contents_editor_lesson'
        );

        $lesson_id = absint($attributes['id'] ?? 0);

        if ($lesson_id <= 0) {
            return $this->notice(__('레슨 ID가 필요합니다.', 'contents-editor'));
        }

        try {
            $lesson = $this->repository->getNode((string) $lesson_id);

            if (! $lesson || ContentNodeType::LESSON !== $lesson->type) {
                return $this->notice(__('레슨을 찾을 수 없습니다.', 'contents-editor'));
            }

            if (ContentStatus::PUBLISHED !== $lesson->status && ! current_user_can(CONTENTS_EDITOR_CAPABILITY)) {
                return $this->notice(__('아직 공개되지 않은 레슨입니다.', 'contents-editor'));
            }

            $document = $this->repository->getLessonDocument((string) $lesson_id);
            $this->assets->enqueuePublic();

            return sprintf(
                '<article class="contents-editor-public-lesson" data-lesson-id="%1$d" data-schema-version="%2$s">%3$s</article>',
                $lesson_id,
                esc_attr($document->schemaVersion),
                $this->renderer->render($document->plateValue)
            );
        } catch (Throwable $throwable) {
            return $this->notice($throwable->getMessage());
        }
    }

    private function notice(string $message): string
    {
        return '<div class="contents-editor-public-notice">' . esc_html($message) . '</div>';
    }
}
