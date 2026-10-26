<?php
/**
 * @var array<string, mixed> $attributes
 */

if (! defined('ABSPATH')) {
    exit;
}

echo \EduGames\Blocks\Missing_Values_Renderer::render($attributes, true);
