<?php
declare(strict_types=1);

namespace EduGames\Games;

final class Game_Visibility {
    private const OPTION_NAME = 'edu_games_closed_games';

    /**
     * @return array<int, string>
     */
    public static function closed_ids(): array {
        $value = get_option(self::OPTION_NAME, array());
        if (! is_array($value)) {
            return array();
        }

        $ids = array_map(
            static fn($id): string => sanitize_key((string) $id),
            $value
        );

        return array_values(array_filter(array_unique($ids)));
    }

    public static function is_open(string $game_id): bool {
        return ! in_array(sanitize_key($game_id), self::closed_ids(), true);
    }

    /**
     * @param array<int, string> $open_ids
     * @param array<int, string> $all_ids
     */
    public static function save_open_ids(array $open_ids, array $all_ids): void {
        $open = array_map(
            static fn($id): string => sanitize_key((string) $id),
            $open_ids
        );
        $all = array_map(
            static fn($id): string => sanitize_key((string) $id),
            $all_ids
        );

        $open = array_values(array_filter(array_unique($open)));
        $all = array_values(array_filter(array_unique($all)));
        $closed = array_values(array_diff($all, $open));

        update_option(self::OPTION_NAME, $closed, false);
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public static function apply(array $definition): array {
        $game_id = sanitize_key((string) ($definition['id'] ?? ''));
        $is_open = '' === $game_id || self::is_open($game_id);
        $definition['isOpen'] = $is_open;

        if (! $is_open) {
            $definition['originalStatus'] = (string) ($definition['status'] ?? 'planned');
            $definition['originalStatusLabel'] = (string) ($definition['statusLabel'] ?? '');
            $definition['status'] = 'closed';
            $definition['statusLabel'] = __('비공개', 'edu-games');
        }

        return $definition;
    }
}
