<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Shared\Judge\JudgeLanguageCatalog;

final class SubmissionLanguageCatalog
{
    public const BLOCKLY_PYTHON_ID = JudgeLanguageCatalog::PYTHON_3_ID;

    /** @var array<int, string> */
    private const EDITOR_MODES = [
        0 => 'c_cpp', 1 => 'c_cpp', 2 => 'pascal', 3 => 'java',
        4 => 'ruby', 5 => 'sh', 6 => 'python', 7 => 'php',
        8 => 'perl', 9 => 'csharp', 10 => 'objectivec', 11 => 'basic',
        12 => 'scheme', 13 => 'c_cpp', 14 => 'c_cpp', 15 => 'lua',
        16 => 'javascript', 17 => 'golang', 18 => 'sql', 19 => 'fortran',
        20 => 'matlab', 21 => 'cobol', 22 => 'r', 23 => 'text',
        24 => 'text',
    ];

    public function __construct(
        private readonly JudgeLanguageCatalog $catalog = new JudgeLanguageCatalog()
    ) {
    }

    /** @return array<int, string> */
    public function all(): array
    {
        return $this->catalog->all();
    }

    public function label(int $languageId): string
    {
        return $this->catalog->label($languageId);
    }

    public function idForKey(string $languageKey): int
    {
        return $this->catalog->idForKey($languageKey);
    }

    public function isAllowed(
        int $languageId,
        int $siteDisabledMask,
        ?int $problemDisabledMask
    ): bool {
        return $this->catalog->isAllowed(
            $languageId,
            $siteDisabledMask,
            $problemDisabledMask
        );
    }

    /** @return array<int, string> */
    public function allowed(
        int $siteDisabledMask,
        ?int $problemDisabledMask
    ): array {
        return $this->catalog->allowed(
            $siteDisabledMask,
            $problemDisabledMask
        );
    }

    /** @return list<int> */
    public function enabledIds(?int $disabledMask): array
    {
        return $this->catalog->enabledIds($disabledMask);
    }

    /** @param list<int> $enabledLanguageIds */
    public function disabledMaskForEnabled(array $enabledLanguageIds): int
    {
        return $this->catalog->disabledMaskForEnabled($enabledLanguageIds);
    }

    public function editorMode(int $languageId): string
    {
        return self::EDITOR_MODES[$languageId] ?? 'text';
    }

    /** @return list<string> */
    public function editorModes(): array
    {
        return array_values(array_unique(self::EDITOR_MODES));
    }
}
