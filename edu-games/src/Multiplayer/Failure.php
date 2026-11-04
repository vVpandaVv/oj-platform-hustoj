<?php
declare(strict_types=1);
namespace EduGames\Multiplayer;

final class Failure extends \RuntimeException {
    public function __construct(string $message, public readonly int $status = 409) {
        parent::__construct($message);
    }
}
