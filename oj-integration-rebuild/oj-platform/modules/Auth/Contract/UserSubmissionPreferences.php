<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Ui\Editor\AceEditorThemeCatalog;
use InvalidArgumentException;

final class UserSubmissionPreferences
{
    private readonly string $editorTheme;

    public function __construct(
        private readonly ?int $defaultLanguage = null,
        string $editorTheme = AceEditorThemeCatalog::DEFAULT_THEME,
        private readonly ?string $codeTemplate = null,
        private readonly string $defaultEditor = 'code',
        private readonly int $editorFontSize = 16,
        private readonly bool $inlineSubmissionPanel = false
    ) {
        if ($this->defaultLanguage !== null && $this->defaultLanguage < 0) {
            throw new InvalidArgumentException('기본 제출 언어가 올바르지 않습니다.');
        }
        $canonicalTheme = AceEditorThemeCatalog::canonical($editorTheme);
        if ($canonicalTheme === null) {
            throw new InvalidArgumentException('편집기 테마가 올바르지 않습니다.');
        }
        $this->editorTheme = $canonicalTheme;
        if (! in_array($this->defaultEditor, ['code', 'blockly'], true)) {
            throw new InvalidArgumentException('기본 제출 편집기가 올바르지 않습니다.');
        }
        if (! in_array($this->editorFontSize, [14, 16, 18], true)) {
            throw new InvalidArgumentException('편집기 글자 크기가 올바르지 않습니다.');
        }
    }

    public function defaultLanguage(): ?int
    {
        return $this->defaultLanguage;
    }

    public function editorTheme(): string
    {
        return $this->editorTheme;
    }

    public function codeTemplate(): ?string
    {
        return $this->codeTemplate;
    }

    public function defaultEditor(): string
    {
        return $this->defaultEditor;
    }

    public function editorFontSize(): int
    {
        return $this->editorFontSize;
    }

    public function inlineSubmissionPanel(): bool
    {
        return $this->inlineSubmissionPanel;
    }
}
