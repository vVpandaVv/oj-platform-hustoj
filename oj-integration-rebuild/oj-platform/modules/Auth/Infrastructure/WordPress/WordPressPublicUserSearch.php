<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\PublicUserSearch;

final class WordPressPublicUserSearch implements PublicUserSearch
{
    public function search(string $keyword, int $limit, int $offset): array
    {
        $keyword = trim(str_replace('*', '', $keyword));
        if ($keyword === '' || strlen($keyword) > 200) { return []; }
        $query = new \WP_User_Query([
            'search' => '*' . str_replace('*', '', $keyword) . '*',
            'search_columns' => ['user_login', 'display_name'],
            'fields' => 'ID', 'number' => max(1, min(100, $limit)),
            'offset' => max(0, $offset), 'orderby' => 'ID', 'order' => 'ASC',
            'count_total' => false,
        ]);
        return array_values(array_map('intval', $query->get_results()));
    }
}
