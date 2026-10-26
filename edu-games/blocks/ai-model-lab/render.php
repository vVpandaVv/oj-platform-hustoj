<?php
/**
 * @var array<string, mixed> $attributes
 */

if (! defined('ABSPATH')) {
    exit;
}

echo \EduGames\Blocks\AI_Model_Lab_Renderer::render($attributes, true);
