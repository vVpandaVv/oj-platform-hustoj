<?php
declare(strict_types=1);
namespace EduGames\Blocks;
final class Device_Team_Renderer {
    public static function render(array $attributes=array(),bool $is_block=false): string {return Binary_Team_Renderer::render_game('device-matching',$is_block);}
}
