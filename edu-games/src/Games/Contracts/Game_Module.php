<?php
declare(strict_types=1);

namespace EduGames\Games\Contracts;

interface Game_Module {
    public function id(): string;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array;
}
