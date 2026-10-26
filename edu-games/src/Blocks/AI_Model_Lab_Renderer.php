<?php
declare(strict_types=1);
namespace EduGames\Blocks;

final class AI_Model_Lab_Renderer extends Abstract_Game_Renderer {
    public static function render(array $attributes = array(), bool $is_block = false): string {
        return Play_Renderer::render('ai-model-lab', $is_block);
    }
}
