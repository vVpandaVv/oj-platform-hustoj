<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Editor;

/**
 * Where and how the rich text editor uploads images. The fields are rendered
 * inside an inert template so they are never submitted with the surrounding form.
 */
final class RichTextImageUpload
{
    public function __construct(
        public readonly string $url,
        public readonly string $fieldsHtml
    ) {
    }
}
