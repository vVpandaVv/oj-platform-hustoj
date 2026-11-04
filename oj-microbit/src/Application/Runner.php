<?php
declare(strict_types=1);
namespace OjMicrobit\Application;
interface Runner
{
    public function run(array $project, array $exercise): array;
    public function status(): array;
}
