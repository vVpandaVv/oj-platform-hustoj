<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Editor;

final class RichTextEditor
{
    public function render(
        string $name,
        string $label,
        string $value,
        int $rows = 10,
        bool $required = false,
        bool $latex = false,
        ?RichTextImageUpload $imageUpload = null,
        bool $tables = true
    ): string {
        $labelId = 'ojp-rich-editor-' . $this->escape($name) . '-label';

        return '<div class="ojp-field ojp-rich-editor-field">'
            . '<span class="ojp-label" id="' . $labelId . '">'
            . $this->escape($label) . ($required ? ' <span aria-hidden="true">*</span>' : '')
            . '</span><div class="ojp-rich-editor" data-ojp-rich-text-editor'
            . ' data-editor-size="' . ($rows >= 10 ? 'large' : ($rows >= 8 ? 'medium' : 'compact')) . '"'
            . ' data-editor-tables="' . ($tables ? 'true' : 'false') . '"'
            . ' data-editor-latex="' . ($latex ? 'true' : 'false') . '"'
            . ($required ? ' data-required="true"' : '') . '>'
            . ($imageUpload === null ? '' : '<template data-ojp-rich-text-upload-fields data-upload-url="'
                . $this->escape($imageUpload->url) . '">' . $imageUpload->fieldsHtml . '</template>')
            . '<textarea class="ojp-textarea ojp-rich-editor__source" name="'
            . $this->escape($name) . '" rows="' . max(3, min(24, $rows)) . '" aria-labelledby="' . $labelId . '"'
            . ($required ? ' required' : '') . ' data-ojp-rich-text-source>'
            . $this->escape((new RichTextContent())->render($value)) . '</textarea>'
            . '<p class="ojp-rich-editor__status" role="status" aria-live="polite" data-ojp-rich-text-status hidden></p>'
            . '<span class="ojp-field-error" data-ojp-rich-text-error hidden>'
            . $this->escape($label) . '을 입력해 주세요.</span></div></div>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
