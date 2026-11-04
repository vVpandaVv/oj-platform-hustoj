<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
echo \EduGames\Blocks\Binary_Team_Renderer::render($attributes??array(),true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes its output.
