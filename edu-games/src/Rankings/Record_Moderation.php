<?php
declare(strict_types=1);
namespace EduGames\Rankings;

final class Record_Moderation {
    public static function allowed(): bool {
        return is_user_logged_in() && current_user_can('manage_options');
    }

    public static function fields(string $store, int $id, string $game): array {
        if (!self::allowed() || $id <= 0) return array();
        return array('deleteUrl' => rest_url('edu-games/v1/leaderboards/game/'.sanitize_key($game).'/records/'.$store.'/'.$id));
    }
}
