<?php
/**
 * @var array<string, mixed> $attributes
 */

if (! defined('ABSPATH')) {
    exit;
}

echo \EduGames\Blocks\Data_Extraction_Renderer::render($attributes, true);
