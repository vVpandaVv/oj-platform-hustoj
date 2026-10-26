<?php
declare(strict_types=1);

namespace EduGames\Blocks;

use EduGames\Sessions\Game_Session_Service;

abstract class Abstract_Game_Renderer {
    /**
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected static function build_config(string $game_id, array $rules, array $extra = array()): array {
        $user_id = get_current_user_id();

        return array_merge(
            array(
                'gameId' => $game_id,
                'hubUrl' => home_url('/oj-addons/edu-games/games'),
                'rules' => $rules,
                'restUrl' => esc_url_raw(rest_url('edu-games/v1')),
                'nonce' => wp_create_nonce('wp_rest'),
                'isLoggedIn' => is_user_logged_in(),
                'currentUserId' => $user_id,
                'sessionToken' => $user_id > 0 ? Game_Session_Service::start($user_id, $game_id) : '',
            ),
            $extra
        );
    }

    protected static function wrapper_attributes(bool $is_block, string $block_class): string {
        Rank_Pager_Renderer::assets();
        Game_Shell_Renderer::assets();
        $class = 'platform-page edu-game ' . $block_class;

        if ($is_block) {
            return get_block_wrapper_attributes(array('class' => $class));
        }

        return 'class="' . esc_attr($class) . '"';
    }
}
