<?php
/**
 * @var array<string, mixed> $attributes
 */

if (! defined('ABSPATH')) {
    exit;
}

echo \EduGames\Blocks\Data_Visualization_Renderer::render($attributes, true);
