<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceBatchProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceRepository;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserPreferences;
use CodingPanda\OjPlatform\Ui\Editor\AceEditorThemeCatalog;
use RuntimeException;

final class WordPressUserPreferenceRepository implements
    UserPreferenceRepository,
    UserPreferenceBatchProvider
{
    private const TABLE = 'user_preferences';

    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : new \stdClass());
    }

    public function find(int $userId): ?UserPreferences
    {
        if (! method_exists($this->database, 'prepare')) {
            throw new RuntimeException('WordPress 데이터베이스 연결을 사용할 수 없습니다.');
        }

        $sql = $this->database->prepare(
            'SELECT user_id, profile_visibility, ranking_visibility, activity_visibility,'
            . ' default_language, editor_theme, code_template, default_editor,'
            . ' editor_font_size, inline_submission_panel'
            . ' FROM `' . self::TABLE . '` WHERE user_id = %d',
            $userId
        );
        $row = $this->database->get_row($sql, 'ARRAY_A');
        if (! is_array($row)) {
            return null;
        }

        return $this->fromRow($row);
    }

    public function findMany(array $userIds): array
    {
        if (
            ! method_exists($this->database, 'prepare')
            || ! method_exists($this->database, 'get_results')
        ) {
            throw new RuntimeException('WordPress 데이터베이스 연결을 사용할 수 없습니다.');
        }
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $userId): bool => $userId > 0
        )));
        if ($userIds === []) {
            return [];
        }

        $preferences = [];
        foreach (array_chunk($userIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $sql = $this->database->prepare(
                'SELECT user_id, profile_visibility, ranking_visibility,'
                    . ' activity_visibility, default_language, editor_theme,'
                    . ' code_template, default_editor, editor_font_size,'
                    . ' inline_submission_panel'
                    . ' FROM `' . self::TABLE . '`'
                    . ' WHERE user_id IN (' . $placeholders . ')',
                ...$chunk
            );
            $rows = $this->database->get_results($sql, 'ARRAY_A');
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $item = $this->fromRow($row);
                if ($item->userId() > 0) {
                    $preferences[$item->userId()] = $item;
                }
            }
        }

        return $preferences;
    }

    public function save(UserPreferences $preferences): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $data = [
            'profile_visibility' => $preferences->profileVisibility(),
            'ranking_visibility' => $preferences->rankingVisibility(),
            'activity_visibility' => $preferences->activityVisibility(),
            'default_language' => $preferences->defaultLanguage(),
            'editor_theme' => $preferences->editorTheme(),
            'code_template' => $preferences->codeTemplate(),
            'default_editor' => $preferences->defaultEditor(),
            'editor_font_size' => $preferences->editorFontSize(),
            'inline_submission_panel' => $preferences->inlineSubmissionPanel() ? 1 : 0,
            'updated_at' => $now,
        ];
        $formats = ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s'];

        if ($this->find($preferences->userId()) === null) {
            $result = $this->database->insert(
                self::TABLE,
                ['user_id' => $preferences->userId(), ...$data, 'created_at' => $now],
                ['%d', ...$formats, '%s']
            );
        } else {
            $result = $this->database->update(
                self::TABLE,
                $data,
                ['user_id' => $preferences->userId()],
                $formats,
                ['%d']
            );
        }

        if ($result === false) {
            throw new RuntimeException('사용자 환경설정을 저장하지 못했습니다.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): UserPreferences
    {
        return new UserPreferences(
            (int) ($row['user_id'] ?? 0),
            $this->visibility($row['profile_visibility'] ?? 'members'),
            $this->visibility($row['ranking_visibility'] ?? 'members'),
            $this->visibility($row['activity_visibility'] ?? 'members'),
            isset($row['default_language'])
                ? (int) $row['default_language']
                : null,
            $this->editorTheme($row['editor_theme'] ?? 'light'),
            isset($row['code_template'])
                ? (string) $row['code_template']
                : null,
            $this->defaultEditor($row['default_editor'] ?? 'code'),
            $this->editorFontSize($row['editor_font_size'] ?? 16),
            $this->boolColumn($row['inline_submission_panel'] ?? 0)
        );
    }

    private function visibility(mixed $value): string
    {
        return strtolower(trim((string) $value)) === 'hidden'
            ? 'hidden'
            : 'members';
    }

    private function editorTheme(mixed $value): string
    {
        return AceEditorThemeCatalog::normalize((string) $value);
    }

    private function defaultEditor(mixed $value): string
    {
        return strtolower(trim((string) $value)) === 'blockly'
            ? 'blockly'
            : 'code';
    }

    private function editorFontSize(mixed $value): int
    {
        $fontSize = (int) $value;

        return in_array($fontSize, [14, 16, 18], true) ? $fontSize : 16;
    }

    private function boolColumn(mixed $value): bool
    {
        return (bool) ((int) $value);
    }
}
