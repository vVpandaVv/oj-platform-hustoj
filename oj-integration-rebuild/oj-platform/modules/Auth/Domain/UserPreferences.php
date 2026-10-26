<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

use CodingPanda\OjPlatform\Ui\Editor\AceEditorThemeCatalog;
use InvalidArgumentException;

final class UserPreferences
{
    private const VISIBILITY_VALUES = ['hidden', 'members'];
    private const DEFAULT_EDITORS = ['code', 'blockly'];
    private const EDITOR_FONT_SIZES = [14, 16, 18];

    private readonly string $editorTheme;

    public function __construct(
        private readonly int $userId,
        private readonly string $profileVisibility = 'members',
        private readonly string $rankingVisibility = 'members',
        private readonly string $activityVisibility = 'members',
        private readonly ?int $defaultLanguage = null,
        string $editorTheme = AceEditorThemeCatalog::DEFAULT_THEME,
        private readonly ?string $codeTemplate = null,
        private readonly string $defaultEditor = 'code',
        private readonly int $editorFontSize = 16,
        private readonly bool $inlineSubmissionPanel = false
    ) {
        if ($this->userId < 1) {
            throw new InvalidArgumentException('사용자 번호는 1 이상이어야 합니다.');
        }
        foreach ([$this->profileVisibility, $this->rankingVisibility, $this->activityVisibility] as $visibility) {
            if (! in_array($visibility, self::VISIBILITY_VALUES, true)) {
                throw new InvalidArgumentException('공개 범위 값이 올바르지 않습니다.');
            }
        }
        if ($this->defaultLanguage !== null && $this->defaultLanguage < 0) {
            throw new InvalidArgumentException('기본 제출 언어가 올바르지 않습니다.');
        }
        $canonicalTheme = AceEditorThemeCatalog::canonical($editorTheme);
        if ($canonicalTheme === null) {
            throw new InvalidArgumentException('편집기 테마가 올바르지 않습니다.');
        }
        $this->editorTheme = $canonicalTheme;
        if (! in_array($this->defaultEditor, self::DEFAULT_EDITORS, true)) {
            throw new InvalidArgumentException('기본 제출 편집기가 올바르지 않습니다.');
        }
        if (! in_array($this->editorFontSize, self::EDITOR_FONT_SIZES, true)) {
            throw new InvalidArgumentException('편집기 글자 크기가 올바르지 않습니다.');
        }
    }

    public function userId(): int { return $this->userId; }
    public function profileVisibility(): string { return $this->profileVisibility; }
    public function rankingVisibility(): string { return $this->rankingVisibility; }
    public function activityVisibility(): string { return $this->activityVisibility; }
    public function defaultLanguage(): ?int { return $this->defaultLanguage; }
    public function editorTheme(): string { return $this->editorTheme; }
    public function codeTemplate(): ?string { return $this->codeTemplate; }
    public function defaultEditor(): string { return $this->defaultEditor; }
    public function editorFontSize(): int { return $this->editorFontSize; }
    public function inlineSubmissionPanel(): bool { return $this->inlineSubmissionPanel; }

    public function withRankingVisibility(string $visibility): self
    {
        return new self(
            $this->userId,
            $this->profileVisibility,
            $visibility,
            $this->activityVisibility,
            $this->defaultLanguage,
            $this->editorTheme,
            $this->codeTemplate,
            $this->defaultEditor,
            $this->editorFontSize,
            $this->inlineSubmissionPanel
        );
    }

    public function withSelfSettings(
        string $profileVisibility,
        string $activityVisibility,
        ?int $defaultLanguage,
        string $editorTheme,
        ?string $codeTemplate,
        string $defaultEditor,
        int $editorFontSize,
        bool $inlineSubmissionPanel
    ): self {
        return new self(
            $this->userId,
            $profileVisibility,
            $this->rankingVisibility,
            $activityVisibility,
            $defaultLanguage,
            $editorTheme,
            $codeTemplate,
            $defaultEditor,
            $editorFontSize,
            $inlineSubmissionPanel
        );
    }
}
