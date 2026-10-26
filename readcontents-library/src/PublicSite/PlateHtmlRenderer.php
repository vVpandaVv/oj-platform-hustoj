<?php
/**
 * Read-only renderer for saved Plate lesson values.
 *
 * @package ContentsEditor\PublicSite
 */

declare(strict_types=1);

namespace ContentsEditor\PublicSite;

final class PlateHtmlRenderer
{
    private int $quiz_counter = 0;

    /**
     * @param array<int, array<string, mixed>> $plate_value
     */
    public function render(array $plate_value): string
    {
        $this->quiz_counter = 0;
        $html = '';

        foreach ($plate_value as $node) {
            if (is_array($node)) {
                $html .= $this->renderNode($node);
            }
        }

        if ('' === trim($html)) {
            return '<div class="contents-editor-plate-content contents-editor-plate-content--empty">아직 작성된 내용이 없습니다.</div>';
        }

        return '<div class="contents-editor-plate-content">' . $html . '</div>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderNode(array $node): string
    {
        if (array_key_exists('text', $node)) {
            return $this->renderTextNode($node);
        }

        $type = (string) ($node['type'] ?? 'p');

        return match ($type) {
            'h1', 'heading-one' => $this->renderTag('h1', $node),
            'h2', 'heading-two' => $this->renderTag('h2', $node),
            'h3', 'heading-three' => $this->renderTag('h3', $node),
            'h4', 'heading-four' => $this->renderTag('h4', $node),
            'h5', 'heading-five' => $this->renderTag('h5', $node),
            'h6', 'heading-six' => $this->renderTag('h6', $node),
            'blockquote' => $this->renderTag('blockquote', $node),
            'ul', 'bulleted-list' => $this->renderTag('ul', $node),
            'ol', 'numbered-list' => $this->renderTag('ol', $node),
            'li', 'list-item' => $this->renderTag('li', $node),
            'table' => $this->renderTag('table', $node),
            'tr', 'table-row' => $this->renderTag('tr', $node),
            'th', 'table-header-cell' => $this->renderTag('th', $node),
            'td', 'table-cell' => $this->renderTag('td', $node),
            'a', 'link' => $this->renderLink($node),
            'img', 'image' => $this->renderImage($node),
            'video' => $this->renderVideo($node),
            'audio' => $this->renderAudio($node),
            'file' => $this->renderFile($node),
            'media_embed', 'media-embed' => $this->renderMediaEmbed($node),
            'code_block', 'code-block' => $this->renderCodeBlock($node),
            'code_line', 'code-line' => $this->escape($this->extractText($node)),
            'visualization' => $this->renderVisualization($node),
            'quiz' => $this->renderQuiz($node),
            'p', 'paragraph' => $this->renderTag('p', $node),
            default => $this->renderFallback($type, $node),
        };
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderTextNode(array $node): string
    {
        $html = $this->escape((string) ($node['text'] ?? ''));

        if ('' === $html) {
            return '';
        }

        if (! empty($node['code'])) {
            $html = '<code>' . $html . '</code>';
        }

        if (! empty($node['bold'])) {
            $html = '<strong>' . $html . '</strong>';
        }

        if (! empty($node['italic'])) {
            $html = '<em>' . $html . '</em>';
        }

        if (! empty($node['underline'])) {
            $html = '<span class="contents-editor-text-underline">' . $html . '</span>';
        }

        if (! empty($node['strikethrough'])) {
            $html = '<s>' . $html . '</s>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderTag(string $tag, array $node): string
    {
        return '<' . $tag . '>' . $this->renderChildren($node) . '</' . $tag . '>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderLink(array $node): string
    {
        $url = $this->safeUrl((string) ($node['url'] ?? $node['href'] ?? ''));
        $children = $this->renderChildren($node);

        if ('' === $url) {
            return $children;
        }

        return '<a href="' . $url . '" rel="noopener noreferrer">' . $children . '</a>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderImage(array $node): string
    {
        $src = $this->safeUrl((string) ($node['url'] ?? $node['src'] ?? ''));

        if ('' === $src) {
            return '';
        }

        $alt = $this->escape((string) ($node['alt'] ?? ''));
        $caption = $this->renderCaption($node);
        $html = '<img src="' . $src . '" alt="' . $alt . '" loading="lazy" />';

        if ('' !== $caption) {
            $html .= '<figcaption>' . $caption . '</figcaption>';
        }

        return '<figure class="contents-editor-plate-image">' . $html . '</figure>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderVideo(array $node): string
    {
        $src = $this->safeUrl((string) ($node['url'] ?? $node['src'] ?? ''));

        if ('' === $src) {
            return '';
        }

        $caption = $this->renderCaption($node);
        $html = '<video controls src="' . $src . '"></video>';

        if ('' !== $caption) {
            $html .= '<figcaption>' . $caption . '</figcaption>';
        }

        return '<figure class="contents-editor-plate-media contents-editor-plate-media--video">' . $html . '</figure>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderAudio(array $node): string
    {
        $src = $this->safeUrl((string) ($node['url'] ?? $node['src'] ?? ''));

        if ('' === $src) {
            return '';
        }

        $caption = $this->renderCaption($node);
        $html = '<audio controls src="' . $src . '"></audio>';

        if ('' !== $caption) {
            $html .= '<figcaption>' . $caption . '</figcaption>';
        }

        return '<figure class="contents-editor-plate-media contents-editor-plate-media--audio">' . $html . '</figure>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderFile(array $node): string
    {
        $href = $this->safeUrl((string) ($node['url'] ?? $node['href'] ?? ''));

        if ('' === $href) {
            return '';
        }

        $name = $this->escape((string) ($node['name'] ?? basename((string) ($node['url'] ?? '첨부 파일'))));

        return '<p class="contents-editor-plate-file"><a href="' . $href . '" rel="noopener noreferrer">' . $name . '</a></p>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderMediaEmbed(array $node): string
    {
        $src = $this->safeUrl((string) ($node['url'] ?? $node['src'] ?? ''));

        if ('' === $src) {
            return '';
        }

        $caption = $this->renderCaption($node);
        $title = $this->escapeAttribute((string) ($node['title'] ?? '임베드 자료'));
        $html = '<iframe src="' . $src . '" title="' . $title . '" loading="lazy" allowfullscreen></iframe>';

        if ('' !== $caption) {
            $html .= '<figcaption>' . $caption . '</figcaption>';
        }

        return '<figure class="contents-editor-plate-media contents-editor-plate-media--embed">' . $html . '</figure>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderCodeBlock(array $node): string
    {
        $code = $this->escape($this->extractText($node));

        return '<pre><code>' . $code . '</code></pre>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderQuiz(array $node): string
    {
        $data = $node['data'] ?? [];

        if (! is_array($data)) {
            return '';
        }

        $choices = $data['choices'] ?? [];

        if (! is_array($choices)) {
            $choices = [];
        }

        $normalized_choices = [];

        foreach ($choices as $choice) {
            if (! is_array($choice)) {
                continue;
            }

            $id = (string) ($choice['id'] ?? '');
            $text = trim((string) ($choice['text'] ?? ''));

            if ('' === $id || '' === $text) {
                continue;
            }

            $normalized_choices[] = [
                'id' => $id,
                'text' => $text,
            ];
        }

        if (count($normalized_choices) < 2) {
            return '';
        }

        $this->quiz_counter++;
        $quiz_id = 'contents-editor-quiz-' . $this->quiz_counter;
        $question = trim((string) ($data['question'] ?? ''));
        $correct_choice_id = (string) ($data['correctChoiceId'] ?? $normalized_choices[0]['id']);
        $explanation = trim((string) ($data['explanation'] ?? ''));
        $feedback_correct = trim((string) ($data['feedbackCorrect'] ?? '정답입니다.'));
        $feedback_incorrect = trim((string) ($data['feedbackIncorrect'] ?? '다시 생각해 보세요.'));

        $html = '<section class="contents-editor-quiz" data-contents-editor-quiz data-correct-choice="' . $this->escapeAttribute($correct_choice_id) . '" data-feedback-correct="' . $this->escapeAttribute($feedback_correct) . '" data-feedback-incorrect="' . $this->escapeAttribute($feedback_incorrect) . '">';
        $html .= '<div class="contents-editor-quiz__badge">퀴즈</div>';

        if ('' !== $question) {
            $html .= '<h3 class="contents-editor-quiz__question">' . nl2br($this->escape($question)) . '</h3>';
        }

        $html .= '<div class="contents-editor-quiz__choices" role="radiogroup" aria-label="퀴즈 선택지">';

        foreach ($normalized_choices as $index => $choice) {
            $choice_id = $quiz_id . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $choice['id']);
            $html .= '<label class="contents-editor-quiz__choice" for="' . $this->escapeAttribute($choice_id) . '">';
            $html .= '<input id="' . $this->escapeAttribute($choice_id) . '" type="radio" name="' . $this->escapeAttribute($quiz_id) . '" value="' . $this->escapeAttribute($choice['id']) . '" />';
            $html .= '<span class="contents-editor-quiz__choice-index">' . $this->escape((string) ($index + 1)) . '</span>';
            $html .= '<span class="contents-editor-quiz__choice-text">' . nl2br($this->escape($choice['text'])) . '</span>';
            $html .= '</label>';
        }

        $html .= '</div>';
        $html .= '<div class="contents-editor-quiz__actions">';
        $html .= '<button class="contents-editor-quiz__check" type="button" data-contents-editor-quiz-check>정답 확인</button>';
        $html .= '<span class="contents-editor-quiz__result" data-contents-editor-quiz-result aria-live="polite"></span>';
        $html .= '</div>';

        if ('' !== $explanation) {
            $html .= '<div class="contents-editor-quiz__explanation" data-contents-editor-quiz-explanation hidden>';
            $html .= '<strong>해설</strong>';
            $html .= '<p>' . nl2br($this->escape($explanation)) . '</p>';
            $html .= '</div>';
        }

        $html .= '</section>';

        return $html;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderVisualization(array $node): string
    {
        $data = $node['data'] ?? null;

        if (! is_array($data)) {
            $data = [];
        }

        if (empty($data) && isset($node['visualType'])) {
            $data = array_filter(
                [
                    'visualId' => $node['visualId'] ?? null,
                    'visualType' => $node['visualType'] ?? null,
                    'title' => $node['title'] ?? null,
                    'source' => $node['source'] ?? null,
                    'metadata' => $node['metadata'] ?? null,
                ],
                static fn (mixed $value): bool => null !== $value
            );
        }

        if (empty($data['visualType']) || ! is_array($data['source'] ?? null)) {
            return '<section class="contents-editor-public-visualization contents-editor-public-visualization--empty">' . esc_html__('시각화 자료를 불러올 수 없습니다.', 'contents-editor') . '</section>';
        }

        $json = wp_json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );

        if (! is_string($json)) {
            return '<section class="contents-editor-public-visualization contents-editor-public-visualization--empty">' . esc_html__('시각화 자료를 불러올 수 없습니다.', 'contents-editor') . '</section>';
        }

        $title = (string) ($data['title'] ?? __('시각화 자료', 'contents-editor'));
        $visual_type = (string) ($data['visualType'] ?? '');

        return sprintf(
            '<section class="contents-editor-public-visualization" data-contents-editor-visualization data-visualization-type="%1$s"><script type="application/json" data-contents-editor-visualization-data>%2$s</script><div class="contents-editor-public-visualization__root" data-contents-editor-visualization-root aria-label="%3$s"></div><noscript><p>%4$s</p></noscript></section>',
            esc_attr($visual_type),
            $json,
            esc_attr($title),
            esc_html__('시각화 자료를 보려면 브라우저에서 JavaScript를 사용할 수 있어야 합니다.', 'contents-editor')
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderFallback(string $type, array $node): string
    {
        $children = $this->renderChildren($node);

        if ('' === trim($children)) {
            return '';
        }

        return '<div class="contents-editor-plate-block" data-plate-type="' . $this->escapeAttribute($type) . '">' . $children . '</div>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderChildren(array $node): string
    {
        $children = $node['children'] ?? [];

        if (! is_array($children)) {
            return '';
        }

        $html = '';

        foreach ($children as $child) {
            if (is_array($child)) {
                $html .= $this->renderNode($child);
            }
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderCaption(array $node): string
    {
        $caption = $node['caption'] ?? '';

        if (is_string($caption)) {
            return $this->escape($caption);
        }

        if (! is_array($caption)) {
            return '';
        }

        $html = '';

        foreach ($caption as $caption_node) {
            if (is_array($caption_node)) {
                $html .= $this->renderNode($caption_node);
            }
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function extractText(array $node): string
    {
        if (array_key_exists('text', $node)) {
            return (string) $node['text'];
        }

        $children = $node['children'] ?? [];

        if (! is_array($children)) {
            return '';
        }

        $parts = [];

        foreach ($children as $child) {
            if (is_array($child)) {
                $parts[] = $this->extractText($child);
            }
        }

        return implode("\n", array_filter($parts, static fn (string $part): bool => '' !== $part));
    }

    private function safeUrl(string $url): string
    {
        $url = trim($url);

        if ('' === $url) {
            return '';
        }

        if (! preg_match('#^(https?:|mailto:|tel:|/)#i', $url)) {
            return '';
        }

        return $this->escapeAttribute($url);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
