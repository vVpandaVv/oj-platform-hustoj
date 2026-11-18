<?php
declare(strict_types=1);
namespace EduGames\Blocks;
final class Cipher_Team_Renderer {
    public static function render(array $attributes=[],bool $is_block=false): string {return Binary_Team_Renderer::render_game('cipher-vault',$is_block);}
}
