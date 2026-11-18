<?php
declare(strict_types=1);
namespace OjMicrobit\Application;
interface LiveCompiler
{
    public function compile(array $project): array;
}
