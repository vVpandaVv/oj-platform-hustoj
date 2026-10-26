<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

final class RuntimeDiagnostic
{
    public function __construct(
        private readonly string $kind,
        private readonly string $type,
        private readonly string $message,
        private readonly string $file = '',
        private readonly ?int $line = null,
        private readonly string $function = ''
    ) {
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function file(): string
    {
        return $this->file;
    }

    public function line(): ?int
    {
        return $this->line;
    }

    public function function(): string
    {
        return $this->function;
    }

    public function fingerprint(): string
    {
        return implode("\0", [
            $this->kind,
            $this->type,
            $this->message,
            $this->file,
            (string) ($this->line ?? 0),
            $this->function,
        ]);
    }
}
