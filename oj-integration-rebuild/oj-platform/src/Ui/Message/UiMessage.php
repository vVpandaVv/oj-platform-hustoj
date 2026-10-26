<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Message;

use InvalidArgumentException;

final class UiMessage
{
    public function __construct(
        private readonly string $messageCode,
        private readonly MessageTone $messageTone,
        private readonly string $title,
        private readonly string $body
    ) {
        if (preg_match('/^[a-z][a-z0-9_.]*$/', $this->messageCode) !== 1) {
            throw new InvalidArgumentException('UI 메시지 코드 형식이 올바르지 않습니다.');
        }

        if (trim($this->title) === '' || trim($this->body) === '') {
            throw new InvalidArgumentException('UI 메시지 제목과 내용은 비어 있을 수 없습니다.');
        }
    }

    public function code(): string
    {
        return $this->messageCode;
    }

    public function tone(): MessageTone
    {
        return $this->messageTone;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function body(): string
    {
        return $this->body;
    }
}
