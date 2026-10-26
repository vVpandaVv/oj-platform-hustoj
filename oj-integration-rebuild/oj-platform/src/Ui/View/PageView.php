<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\View;

use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use InvalidArgumentException;

final class PageView
{
    /**
     * @var list<UiMessage>
     */
    private readonly array $pageMessages;

    /**
     * @param list<UiMessage> $messages
     */
    public function __construct(
        private readonly string $pageKey,
        private readonly string $title,
        private readonly ?string $description,
        array $messages = []
    ) {
        if (preg_match('/^[a-z][a-z0-9_.]*$/', $this->pageKey) !== 1) {
            throw new InvalidArgumentException('페이지 View 키 형식이 올바르지 않습니다.');
        }

        if (trim($this->title) === '') {
            throw new InvalidArgumentException('페이지 제목은 비어 있을 수 없습니다.');
        }

        $this->pageMessages = array_values($messages);
    }

    public function key(): string
    {
        return $this->pageKey;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @return list<UiMessage>
     */
    public function messages(): array
    {
        return $this->pageMessages;
    }
}
