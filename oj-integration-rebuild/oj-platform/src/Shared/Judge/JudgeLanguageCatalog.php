<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Shared\Judge;

use DomainException;

final class JudgeLanguageCatalog
{
    /** @var array<int, string> */
    private const LANGUAGES = [
        0 => 'C',
        1 => 'C++',
        2 => 'Pascal',
        3 => 'Java',
        4 => 'Ruby',
        5 => 'Bash',
        6 => 'Python 3',
        7 => 'PHP',
        8 => 'Perl',
        9 => 'C#',
        10 => 'Objective-C',
        11 => 'FreeBasic',
        12 => 'Scheme',
        13 => 'Clang',
        14 => 'Clang++',
        15 => 'Lua',
        16 => 'JavaScript',
        17 => 'Go',
        18 => 'SQL',
        19 => 'Fortran',
        20 => 'Octave',
        21 => 'COBOL',
        22 => 'R',
        23 => 'Scratch 3',
        24 => 'Cangjie',
    ];

    /** @var array<string, int> */
    private const KEYS = [
        'c' => 0,
        'cpp' => 1,
        'c++' => 1,
        'java' => 3,
        'python' => 6,
        'python3' => 6,
        'php' => 7,
        'csharp' => 9,
        'javascript' => 16,
        'js' => 16,
        'go' => 17,
    ];

    public const PYTHON_3_ID = 6;

    /** @return array<int, string> */
    public function all(): array
    {
        return self::LANGUAGES;
    }

    public function label(int $languageId): string
    {
        return self::LANGUAGES[$languageId] ?? '언어 ' . $languageId;
    }

    /** @return array<string, string> */
    public function keyedLanguages(): array
    {
        $options = [];
        $seen = [];
        foreach (self::KEYS as $key => $languageId) {
            if (! isset($seen[$languageId])) {
                $options[$key] = self::LANGUAGES[$languageId];
                $seen[$languageId] = true;
            }
        }

        return $options;
    }

    public function idForKey(string $languageKey): int
    {
        $key = strtolower(trim($languageKey));
        if (! isset(self::KEYS[$key])) {
            throw new DomainException('빈칸 완성 문제의 언어를 지원하지 않습니다.');
        }

        return self::KEYS[$key];
    }

    public function isAllowed(
        int $languageId,
        int $siteDisabledMask,
        ?int $problemDisabledMask
    ): bool {
        if (! isset(self::LANGUAGES[$languageId])) {
            return false;
        }

        return ! $this->bitEnabled($siteDisabledMask, $languageId)
            && ! $this->bitEnabled($problemDisabledMask ?? 0, $languageId);
    }

    /** @return array<int, string> */
    public function allowed(
        int $siteDisabledMask,
        ?int $problemDisabledMask
    ): array {
        return array_filter(
            self::LANGUAGES,
            fn (string $label, int $languageId): bool => $this->isAllowed(
                $languageId,
                $siteDisabledMask,
                $problemDisabledMask
            ),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** @return list<int> */
    public function enabledIds(?int $disabledMask): array
    {
        return array_values(array_map(
            static fn (int|string $languageId): int => (int) $languageId,
            array_keys($this->allowed(0, $disabledMask))
        ));
    }

    /**
     * @param list<int> $enabledLanguageIds
     */
    public function disabledMaskForEnabled(array $enabledLanguageIds): int
    {
        $enabled = [];
        foreach ($enabledLanguageIds as $languageId) {
            if (isset(self::LANGUAGES[$languageId])) {
                $enabled[$languageId] = true;
            }
        }
        if ($enabled === []) {
            throw new DomainException('허용 언어를 하나 이상 선택해 주세요.');
        }

        $mask = 0;
        foreach (self::LANGUAGES as $languageId => $label) {
            unset($label);
            if (! isset($enabled[$languageId])) {
                $mask |= 1 << $languageId;
            }
        }

        return $mask;
    }

    private function bitEnabled(int $mask, int $languageId): bool
    {
        return $mask > 0 && ($mask & (1 << $languageId)) !== 0;
    }
}
