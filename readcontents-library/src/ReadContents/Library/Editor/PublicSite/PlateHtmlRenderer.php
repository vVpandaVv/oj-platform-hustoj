<?php
/**
 * Read-only renderer for saved Plate lesson values.
 *
 * @package ReadContentsLibrary\Editor\PublicSite
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\PublicSite;

final class PlateHtmlRenderer
{
    private int $heading_counter = 0;

    /** @var list<array{id: string, level: int, text: string}> */
    private array $headings = [];

    private int $quiz_counter = 0;

    /** @var list<array{label: string, text: string}> */
    private array $image_sources = [];

    /**
     * @param array<int, array<string, mixed>> $plate_value
     */
    public function render(array $plate_value): string
    {
        $this->heading_counter = 0;
        $this->headings = [];
        $this->quiz_counter = 0;
        $this->image_sources = [];
        $this->collectHeadings($plate_value);
        $html = $this->renderNodes($plate_value);
        $html .= $this->renderImageSourceList();
        $classes = 'contents-editor-content-surface contents-editor-content-surface--public contents-editor-plate-content';

        if ('' === trim($html)) {
            return '<div class="' . $classes . ' contents-editor-plate-content--empty" data-contents-editor-content-surface="public">아직 작성된 내용이 없습니다.</div>';
        }

        return '<div class="' . $classes . '" data-contents-editor-content-surface="public">' . $html . '</div>';
    }

    private function renderImageSourceList(): string
    {
        if (! $this->image_sources) {
            return '';
        }

        $items = '';

        foreach ($this->image_sources as $index => $entry) {
            $items .= '<li id="contents-editor-image-source-' . ($index + 1) . '">'
                . '<span class="contents-editor-image-source-label">' . $this->escape($entry['label']) . '</span> '
                . $this->escape($entry['text'])
                . '</li>';
        }

        return '<section class="contents-editor-plate-image-sources">'
            . '<h2>이미지 출처</h2>'
            . '<ol>' . $items . '</ol>'
            . '</section>';
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private function renderNodes(array $nodes): string
    {
        $html = '';
        $count = count($nodes);

        for ($index = 0; $index < $count;) {
            $node = $nodes[$index] ?? null;

            if (! is_array($node)) {
                $index++;
                continue;
            }

            $list_tag = $this->listTag($node);

            if (null === $list_tag) {
                $html .= $this->renderNode($node);
                $index++;
                continue;
            }

            $html .= '<' . $list_tag . ' class="contents-editor-plate-list">';

            while ($index < $count) {
                $item = $nodes[$index] ?? null;

                if (! is_array($item) || $this->listTag($item) !== $list_tag) {
                    break;
                }

                $indent = max(0, min(8, (int) ($item['indent'] ?? 0)));
                $style = $indent > 1 ? ' style="margin-inline-start: ' . (($indent - 1) * 1.25) . 'rem"' : '';
                $html .= '<li data-list-indent="' . $indent . '"' . $style . '>';

                if ('todo' === (string) ($item['listStyleType'] ?? '')) {
                    $html .= '<input type="checkbox" disabled' . (! empty($item['checked']) ? ' checked' : '') . ' aria-label="할 일 상태">';
                }

                $html .= $this->renderChildren($item) . '</li>';
                $index++;
            }

            $html .= '</' . $list_tag . '>';
        }

        return $html;
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
            'h1', 'heading-one' => $this->renderHeading('h1', 1, $node),
            'h2', 'heading-two' => $this->renderHeading('h2', 2, $node),
            'h3', 'heading-three' => $this->renderHeading('h3', 3, $node),
            'h4', 'heading-four' => $this->renderHeading('h4', 4, $node),
            'h5', 'heading-five' => $this->renderHeading('h5', 5, $node),
            'h6', 'heading-six' => $this->renderHeading('h6', 6, $node),
            'blockquote' => $this->renderTag('blockquote', $node),
            'ul', 'bulleted-list' => $this->renderTag('ul', $node),
            'ol', 'numbered-list' => $this->renderTag('ol', $node),
            'li', 'list-item' => $this->renderTag('li', $node),
            'table' => $this->renderTable($node),
            'tr', 'table-row' => $this->renderTableRow($node),
            'th', 'table-header-cell' => $this->renderTableCell('th', $node),
            'td', 'table-cell' => $this->renderTableCell('td', $node),
            'a', 'link' => $this->renderLink($node),
            'img', 'image' => $this->renderImage($node),
            'video' => $this->renderVideo($node),
            'audio' => $this->renderAudio($node),
            'file' => $this->renderFile($node),
            'media_embed', 'media-embed' => $this->renderMediaEmbed($node),
            'code_block', 'code-block' => $this->renderCodeBlock($node),
            'code_line', 'code-line' => $this->escape($this->extractCodeText($node)),
            'hr', 'horizontal_rule', 'horizontal-rule' => '<hr aria-hidden="true">',
            'callout' => $this->renderCallout($node),
            'column_group', 'column-group' => $this->renderColumnGroup($node),
            'column' => $this->renderColumn($node),
            'toggle' => $this->renderToggle($node),
            'equation' => $this->renderEquation($node, false),
            'inline_equation', 'inline-equation' => $this->renderEquation($node, true),
            'date' => $this->renderDate($node),
            'mention' => $this->renderMention($node),
            'toc' => $this->renderTableOfContents(),
            'code_drawing', 'code-drawing' => $this->renderCodeDrawing($node),
            'excalidraw' => $this->renderExcalidraw($node),
            'visualization' => $this->renderVisualization($node),
            'quiz' => $this->renderQuiz($node),
            'p', 'paragraph' => $this->renderTag('p', $node),
            default => $this->renderFallback($type, $node),
        };
    }

    /** @param array<string, mixed> $node */
    private function renderTextNode(array $node): string
    {
        $text = (string) ($node['text'] ?? '');

        if ('' === $text) {
            return '';
        }

        $html = nl2br($this->escape($text), false);
        $styles = $this->textStyles($node);

        if ([] !== $styles) {
            $html = '<span' . $this->styleAttribute($styles) . '>' . $html . '</span>';
        }

        if (! empty($node['code'])) {
            $html = '<code>' . $html . '</code>';
        }

        if (! empty($node['kbd'])) {
            $html = '<kbd>' . $html . '</kbd>';
        }

        if (! empty($node['highlight'])) {
            $html = '<mark>' . $html . '</mark>';
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

        if (! empty($node['superscript'])) {
            $html = '<sup>' . $html . '</sup>';
        } elseif (! empty($node['subscript'])) {
            $html = '<sub>' . $html . '</sub>';
        }

        return $html;
    }

    /** @param array<string, mixed> $node */
    private function renderHeading(string $tag, int $level, array $node): string
    {
        $heading = $this->headings[$this->heading_counter] ?? null;
        $this->heading_counter++;
        $id = is_array($heading) ? (string) $heading['id'] : 'contents-editor-heading-' . $this->heading_counter;

        return '<' . $tag . ' id="' . $this->escapeAttribute($id) . '"' . $this->styleAttribute($this->blockStyles($node)) . '>'
            . $this->renderChildren($node)
            . '</' . $tag . '>';
    }

    /** @param array<string, mixed> $node */
    private function renderTag(string $tag, array $node): string
    {
        return '<' . $tag . $this->styleAttribute($this->blockStyles($node)) . '>' . $this->renderChildren($node) . '</' . $tag . '>';
    }

    /** @param array<string, mixed> $node */
    private function renderLink(array $node): string
    {
        $url = $this->safeUrl((string) ($node['url'] ?? $node['href'] ?? ''));
        $children = $this->renderChildren($node);

        if ('' === $url) {
            return $children;
        }

        return '<a href="' . $url . '" rel="noopener noreferrer">' . $children . '</a>';
    }

    /** @param array<string, mixed> $node */
    private function renderImage(array $node): string
    {
        $src = $this->safeUrl((string) ($node['url'] ?? $node['src'] ?? ''));

        if ('' === $src) {
            return '';
        }

        $alt = $this->escapeAttribute((string) ($node['alt'] ?? ''));
        $caption = $this->renderCaption($node);
        $html = '<img src="' . $src . '" alt="' . $alt . '" loading="lazy">';
        $source_note = $this->renderImageSourceNote($node);

        if ('' !== $caption || '' !== $source_note) {
            $html .= '<figcaption>' . $caption . $source_note . '</figcaption>';
        }

        return '<figure class="contents-editor-plate-image"' . $this->mediaStyleAttribute($node) . '>' . $html . '</figure>';
    }

    /** @param array<string, mixed> $node */
    private function renderImageSourceNote(array $node): string
    {
        $source = trim((string) ($node['source'] ?? ''));

        if ('' === $source) {
            return '';
        }

        $license = trim((string) ($node['license'] ?? ''));
        $label = '출처: ' . $source . ('' !== $license ? ' · ' . $license : '');
        $index = count($this->image_sources) + 1;
        $this->image_sources[] = [
            'label' => '[' . $index . ']',
            'text' => $label,
        ];

        return '<span class="contents-editor-plate-image-source">'
            . '<a href="#contents-editor-image-source-' . $index . '">[' . $index . ']</a> '
            . $this->escape($label)
            . '</span>';
    }

    /** @param array<string, mixed> $node */
    private function renderVideo(array $node): string
    {
        $src = $this->safeUrl((string) ($node['url'] ?? $node['src'] ?? ''));

        if ('' === $src) {
            return '';
        }

        $caption = $this->renderCaption($node);
        $html = '<video controls preload="metadata" src="' . $src . '"></video>';

        if ('' !== $caption) {
            $html .= '<figcaption>' . $caption . '</figcaption>';
        }

        return '<figure class="contents-editor-plate-media contents-editor-plate-media--video"' . $this->mediaStyleAttribute($node) . '>' . $html . '</figure>';
    }

    /** @param array<string, mixed> $node */
    private function renderAudio(array $node): string
    {
        $src = $this->safeUrl((string) ($node['url'] ?? $node['src'] ?? ''));

        if ('' === $src) {
            return '';
        }

        $caption = $this->renderCaption($node);
        $html = '<audio controls preload="metadata" src="' . $src . '"></audio>';

        if ('' !== $caption) {
            $html .= '<figcaption>' . $caption . '</figcaption>';
        }

        return '<figure class="contents-editor-plate-media contents-editor-plate-media--audio"' . $this->mediaStyleAttribute($node) . '>' . $html . '</figure>';
    }

    /** @param array<string, mixed> $node */
    private function renderFile(array $node): string
    {
        $href = $this->safeUrl((string) ($node['url'] ?? $node['href'] ?? ''));

        if ('' === $href) {
            return '';
        }

        $fallback_name = basename((string) ($node['url'] ?? '첨부 파일'));
        $name = $this->escape((string) ($node['name'] ?? $fallback_name));

        return '<p class="contents-editor-plate-file"><a href="' . $href . '" rel="noopener noreferrer">' . $name . '</a></p>';
    }

    /** @param array<string, mixed> $node */
    private function renderMediaEmbed(array $node): string
    {
        $src = $this->safeUrl((string) ($node['url'] ?? $node['src'] ?? ''));

        if ('' === $src) {
            return '';
        }

        $caption = $this->renderCaption($node);
        $title = $this->escapeAttribute((string) ($node['title'] ?? '외부 콘텐츠'));
        $html = '<iframe src="' . $src . '" title="' . $title . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>';

        if ('' !== $caption) {
            $html .= '<figcaption>' . $caption . '</figcaption>';
        }

        return '<figure class="contents-editor-plate-media contents-editor-plate-media--embed"' . $this->mediaStyleAttribute($node) . '>' . $html . '</figure>';
    }

    /** @param array<string, mixed> $node */
    private function renderCodeBlock(array $node): string
    {
        $code = $this->escape($this->extractCodeText($node));
        $language = preg_replace('/[^a-z0-9_+-]/i', '', (string) ($node['lang'] ?? ''));
        $class = is_string($language) && '' !== $language ? ' class="language-' . $language . '"' : '';

        return '<pre><code' . $class . '>' . $code . '</code></pre>';
    }

    /** @param array<string, mixed> $node */
    private function renderCallout(array $node): string
    {
        $icon = trim((string) ($node['icon'] ?? '💡'));
        $styles = [];
        $background = $this->safeCssColor($node['backgroundColor'] ?? null);

        if (null !== $background) {
            $styles['background-color'] = $background;
        }

        return '<aside class="contents-editor-callout"' . $this->styleAttribute($styles) . '>'
            . '<span class="contents-editor-callout__icon" aria-hidden="true">' . $this->escape($icon) . '</span>'
            . '<div class="contents-editor-callout__content">' . $this->renderChildren($node) . '</div>'
            . '</aside>';
    }

    /** @param array<string, mixed> $node */
    private function renderColumnGroup(array $node): string
    {
        return '<div class="contents-editor-columns">' . $this->renderChildren($node) . '</div>';
    }

    /** @param array<string, mixed> $node */
    private function renderColumn(array $node): string
    {
        $styles = [];
        $width = $this->safeWidth($node['width'] ?? null);

        if (null !== $width) {
            $styles['width'] = $width;
        }

        return '<section class="contents-editor-column"' . $this->styleAttribute($styles) . '>' . $this->renderChildren($node) . '</section>';
    }

    /** @param array<string, mixed> $node */
    private function renderToggle(array $node): string
    {
        return '<details class="contents-editor-toggle" open><summary>' . $this->renderChildren($node) . '</summary></details>';
    }

    /** @param array<string, mixed> $node */
    private function renderEquation(array $node, bool $inline): string
    {
        $expression = trim((string) ($node['texExpression'] ?? $node['tex'] ?? ''));

        if ('' === $expression) {
            return '';
        }

        $tag = $inline ? 'span' : 'div';
        $class = $inline ? 'contents-editor-equation contents-editor-equation--inline' : 'contents-editor-equation';

        return '<' . $tag . ' class="' . $class . '" role="math" aria-label="수식 ' . $this->escapeAttribute($expression) . '"><code>' . $this->escape($expression) . '</code></' . $tag . '>';
    }

    /** @param array<string, mixed> $node */
    private function renderDate(array $node): string
    {
        $date = trim((string) ($node['date'] ?? $node['rawDate'] ?? ''));

        if ('' === $date) {
            return '';
        }

        return '<time class="contents-editor-date" datetime="' . $this->escapeAttribute($date) . '">' . $this->escape($date) . '</time>';
    }

    /** @param array<string, mixed> $node */
    private function renderMention(array $node): string
    {
        $value = trim((string) ($node['value'] ?? $this->extractText($node)));

        return '' === $value ? '' : '<span class="contents-editor-mention">@' . $this->escape(ltrim($value, '@')) . '</span>';
    }

    private function renderTableOfContents(): string
    {
        if ([] === $this->headings) {
            return '';
        }

        $html = '<nav class="contents-editor-plate-toc" aria-label="본문 목차"><ol>';

        foreach ($this->headings as $heading) {
            $html .= '<li data-heading-level="' . $heading['level'] . '"><a href="#' . $this->escapeAttribute($heading['id']) . '">'
                . $this->escape($heading['text'])
                . '</a></li>';
        }

        return $html . '</ol></nav>';
    }

    /** @param array<string, mixed> $node */
    private function renderCodeDrawing(array $node): string
    {
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $code = (string) ($data['code'] ?? '');

        if ('' === trim($code)) {
            return '';
        }

        $json = $this->jsonForScript($data);

        if (null === $json) {
            return '';
        }

        return '<section class="contents-editor-code-drawing" data-contents-editor-code-drawing>'
            . '<script type="application/json" data-contents-editor-code-drawing-data>' . $json . '</script>'
            . '<div class="contents-editor-code-drawing__body" data-contents-editor-code-drawing-root>'
            . '<p class="contents-editor-code-drawing__status" role="status">코드 그림을 불러오는 중입니다.</p>'
            . '</div></section>';
    }

    /** @param array<string, mixed> $node */
    private function renderExcalidraw(array $node): string
    {
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $json = $this->jsonForScript($data);

        if (null === $json) {
            return '';
        }

        return '<section class="contents-editor-excalidraw" data-contents-editor-excalidraw>'
            . '<script type="application/json" data-contents-editor-excalidraw-data>' . $json . '</script>'
            . '<div class="contents-editor-excalidraw__render" data-contents-editor-excalidraw-root role="img" aria-label="Excalidraw 그림">'
            . '<p role="status">그림을 불러오는 중입니다.</p>'
            . '</div></section>';
    }

    /** @param array<string, mixed> $node */
    private function renderTable(array $node): string
    {
        $children = $node['children'] ?? [];

        if (! is_array($children) || [] === $children) {
            return '';
        }

        $html = '<div class="contents-editor-table-scroll" role="region" aria-label="표" tabindex="0"><table>';
        $col_sizes = $node['colSizes'] ?? [];

        if (is_array($col_sizes) && [] !== $col_sizes) {
            $html .= '<colgroup>';
            foreach ($col_sizes as $size) {
                $width = $this->safeWidth($size);
                $html .= null === $width ? '<col>' : '<col style="width: ' . $this->escapeAttribute($width) . '">';
            }
            $html .= '</colgroup>';
        }

        foreach ($children as $row) {
            if (is_array($row)) {
                $html .= $this->renderTableRow($row);
            }
        }

        return $html . '</table></div>';
    }

    /** @param array<string, mixed> $node */
    private function renderTableRow(array $node): string
    {
        $html = '<tr>';

        foreach ((array) ($node['children'] ?? []) as $cell) {
            if (! is_array($cell)) {
                continue;
            }

            $type = (string) ($cell['type'] ?? 'td');
            $html .= $this->renderTableCell(in_array($type, ['th', 'table-header-cell'], true) ? 'th' : 'td', $cell);
        }

        return $html . '</tr>';
    }

    /** @param array<string, mixed> $node */
    private function renderTableCell(string $tag, array $node): string
    {
        $attributes = '';
        $col_span = max(1, min(100, (int) ($node['colSpan'] ?? 1)));
        $row_span = max(1, min(100, (int) ($node['rowSpan'] ?? 1)));

        if ($col_span > 1) {
            $attributes .= ' colspan="' . $col_span . '"';
        }

        if ($row_span > 1) {
            $attributes .= ' rowspan="' . $row_span . '"';
        }

        $background = $this->safeCssColor($node['background'] ?? $node['backgroundColor'] ?? null);
        $styles = null === $background ? [] : ['background-color' => $background];

        return '<' . $tag . $attributes . $this->styleAttribute($styles) . '>' . $this->renderChildren($node) . '</' . $tag . '>';
    }

    /** @param array<string, mixed> $node */
    private function renderQuiz(array $node): string
    {
        $data = $node['data'] ?? [];

        if (! is_array($data)) {
            return '';
        }

        $choices = is_array($data['choices'] ?? null) ? $data['choices'] : [];
        $normalized_choices = [];

        foreach ($choices as $choice) {
            if (! is_array($choice)) {
                continue;
            }

            $id = (string) ($choice['id'] ?? '');
            $text = trim((string) ($choice['text'] ?? ''));

            if ('' !== $id && '' !== $text) {
                $normalized_choices[] = ['id' => $id, 'text' => $text];
            }
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
            $html .= '<h3 class="contents-editor-quiz__question">' . nl2br($this->escape($question), false) . '</h3>';
        }

        $html .= '<div class="contents-editor-quiz__choices" role="radiogroup" aria-label="퀴즈 선택지">';

        foreach ($normalized_choices as $index => $choice) {
            $choice_id = $quiz_id . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $choice['id']);
            $html .= '<label class="contents-editor-quiz__choice" for="' . $this->escapeAttribute($choice_id) . '">';
            $html .= '<input id="' . $this->escapeAttribute($choice_id) . '" type="radio" name="' . $this->escapeAttribute($quiz_id) . '" value="' . $this->escapeAttribute($choice['id']) . '">';
            $html .= '<span class="contents-editor-quiz__choice-index">' . ($index + 1) . '</span>';
            $html .= '<span class="contents-editor-quiz__choice-text">' . nl2br($this->escape($choice['text']), false) . '</span>';
            $html .= '</label>';
        }

        $html .= '</div><div class="contents-editor-quiz__actions">';
        $html .= '<button class="contents-editor-quiz__check" type="button" data-contents-editor-quiz-check>정답 확인</button>';
        $html .= '<span class="contents-editor-quiz__result" data-contents-editor-quiz-result aria-live="polite"></span></div>';

        if ('' !== $explanation) {
            $html .= '<div class="contents-editor-quiz__explanation" data-contents-editor-quiz-explanation hidden><strong>해설</strong><p>'
                . nl2br($this->escape($explanation), false)
                . '</p></div>';
        }

        return $html . '</section>';
    }

    /** @param array<string, mixed> $node */
    private function renderVisualization(array $node): string
    {
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];

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
            return '<section class="contents-editor-public-visualization contents-editor-public-visualization--empty">' . esc_html__('시각화 자료를 불러올 수 없습니다.', 'readcontents-library') . '</section>';
        }

        $json = $this->jsonForScript($data);

        if (null === $json) {
            return '<section class="contents-editor-public-visualization contents-editor-public-visualization--empty">' . esc_html__('시각화 자료를 불러올 수 없습니다.', 'readcontents-library') . '</section>';
        }

        $title = (string) ($data['title'] ?? __('시각화 자료', 'readcontents-library'));
        $visual_type = (string) ($data['visualType'] ?? '');

        return sprintf(
            '<section class="contents-editor-public-visualization" data-contents-editor-visualization data-visualization-type="%1$s"><script type="application/json" data-contents-editor-visualization-data>%2$s</script><div class="contents-editor-public-visualization__root" data-contents-editor-visualization-root aria-label="%3$s"></div><noscript><p>%4$s</p></noscript></section>',
            esc_attr($visual_type),
            $json,
            esc_attr($title),
            esc_html__('시각화 자료를 보려면 브라우저에서 JavaScript를 사용할 수 있어야 합니다.', 'readcontents-library')
        );
    }

    /** @param array<string, mixed> $node */
    private function renderFallback(string $type, array $node): string
    {
        $children = $this->renderChildren($node);

        if ('' === trim($children)) {
            return '';
        }

        return '<div class="contents-editor-plate-block" data-plate-type="' . $this->escapeAttribute($type) . '">' . $children . '</div>';
    }

    /** @param array<string, mixed> $node */
    private function renderChildren(array $node): string
    {
        $children = $node['children'] ?? [];

        return is_array($children) ? $this->renderNodes($children) : '';
    }

    /** @param array<string, mixed> $node */
    private function renderCaption(array $node): string
    {
        $caption = $node['caption'] ?? '';

        if (is_string($caption)) {
            return nl2br($this->escape($caption), false);
        }

        return is_array($caption) ? $this->renderNodes($caption) : '';
    }

    /** @param array<string, mixed> $node */
    private function extractCodeText(array $node): string
    {
        if (array_key_exists('text', $node)) {
            return (string) $node['text'];
        }

        $parts = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                $parts[] = $this->extractCodeText($child);
            }
        }

        $separator = in_array($node['type'] ?? '', ['code_block', 'code-block'], true) ? "\n" : '';

        return implode($separator, $parts);
    }

    /** @param array<string, mixed> $node */
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

    /** @param array<int, array<string, mixed>> $nodes */
    private function collectHeadings(array $nodes): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = (string) ($node['type'] ?? '');
            $levels = [
                'h1' => 1,
                'heading-one' => 1,
                'h2' => 2,
                'heading-two' => 2,
                'h3' => 3,
                'heading-three' => 3,
                'h4' => 4,
                'heading-four' => 4,
                'h5' => 5,
                'heading-five' => 5,
                'h6' => 6,
                'heading-six' => 6,
            ];

            if (isset($levels[$type])) {
                $text = trim($this->extractText($node));

                if ('' !== $text) {
                    $this->headings[] = [
                        'id' => 'contents-editor-heading-' . (count($this->headings) + 1),
                        'level' => $levels[$type],
                        'text' => $text,
                    ];
                }
            }

            if (is_array($node['children'] ?? null)) {
                $this->collectHeadings($node['children']);
            }
        }
    }

    /** @param array<string, mixed> $node */
    private function listTag(array $node): ?string
    {
        $style = strtolower(trim((string) ($node['listStyleType'] ?? '')));

        if ('' === $style) {
            return null;
        }

        return in_array($style, ['decimal', 'decimal-leading-zero', 'lower-alpha', 'lower-roman', 'upper-alpha', 'upper-roman'], true)
            ? 'ol'
            : 'ul';
    }

    /** @param array<string, mixed> $node @return array<string, string> */
    private function blockStyles(array $node): array
    {
        $styles = [];
        $align = (string) ($node['align'] ?? $node['textAlign'] ?? '');

        if (in_array($align, ['left', 'center', 'right', 'justify', 'start', 'end'], true)) {
            $styles['text-align'] = $align;
        }

        $line_height = $node['lineHeight'] ?? null;

        if (is_numeric($line_height)) {
            $number = (float) $line_height;
            if ($number >= 1 && $number <= 3) {
                $styles['line-height'] = (string) $number;
            }
        }

        return $styles;
    }

    /** @param array<string, mixed> $node @return array<string, string> */
    private function textStyles(array $node): array
    {
        $styles = [];
        $color = $this->safeCssColor($node['color'] ?? null);
        $background = $this->safeCssColor($node['backgroundColor'] ?? null);
        $font_size = $this->safeFontSize($node['fontSize'] ?? null);

        if (null !== $color) {
            $styles['color'] = $color;
        }

        if (null !== $background) {
            $styles['background-color'] = $background;
        }

        if (null !== $font_size) {
            $styles['font-size'] = $font_size;
        }

        return $styles;
    }

    /** @param array<string, mixed> $node */
    private function mediaStyleAttribute(array $node): string
    {
        $styles = [];
        $width = $this->safeWidth($node['width'] ?? null);

        if (null !== $width) {
            $styles['width'] = $width;
        }

        $align = (string) ($node['align'] ?? 'center');

        if ('left' === $align) {
            $styles['margin-inline-end'] = 'auto';
        } elseif ('right' === $align) {
            $styles['margin-inline-start'] = 'auto';
        } else {
            $styles['margin-inline'] = 'auto';
        }

        return $this->styleAttribute($styles);
    }

    /** @param array<string, string> $styles */
    private function styleAttribute(array $styles): string
    {
        if ([] === $styles) {
            return '';
        }

        $declarations = [];

        foreach ($styles as $property => $value) {
            $declarations[] = $property . ': ' . $value;
        }

        return ' style="' . $this->escapeAttribute(implode('; ', $declarations)) . '"';
    }

    private function safeWidth(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $number = max(40, min(2400, (float) $value));

            return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.') . 'px';
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(\d+(?:\.\d+)?)(px|%)$/', $value, $matches)) {
            $number = (float) $matches[1];
            $unit = $matches[2];
            $maximum = '%' === $unit ? 100 : 2400;

            if ($number >= 1 && $number <= $maximum) {
                return $value;
            }
        }

        return null;
    }

    private function safeFontSize(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;

            return $number >= 8 && $number <= 96 ? $number . 'px' : null;
        }

        if (is_string($value) && preg_match('/^(\d+(?:\.\d+)?)(px|rem|em|%)$/', trim($value), $matches)) {
            $number = (float) $matches[1];

            return $number > 0 && $number <= 200 ? trim($value) : null;
        }

        return null;
    }

    private function safeCssColor(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^#[0-9a-f]{3,8}$/i', $value)) {
            return $value;
        }

        if (preg_match('/^(?:rgb|rgba|hsl|hsla)\([0-9.,%\s-]+\)$/i', $value)) {
            return $value;
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function jsonForScript(array $data): ?string
    {
        $json = wp_json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );

        return is_string($json) ? $json : null;
    }

    private function safeUrl(string $url): string
    {
        $url = trim($url);

        if ('' === $url) {
            return '';
        }

        if (! preg_match('#^(https?:|mailto:|tel:|/|\./|\.\./)#i', $url)
            && ! preg_match('#^data:image/(?:png|jpeg|gif|webp);base64,#i', $url)) {
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
