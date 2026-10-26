<?php
/**
 * @var array<string, mixed> $attributes
 */

if (! defined('ABSPATH')) {
    exit;
}

echo \EduGames\Blocks\Device_Matching_Renderer::render($attributes, true);
