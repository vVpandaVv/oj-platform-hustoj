<?php
declare(strict_types=1);
namespace EduGames\Rankings;

final class Page {
    public static function meta(int $total, $page = 1, $size = 20): array {
        $size = is_scalar($size) && in_array((int)$size, array(20, 50, 100), true) ? (int)$size : 20;
        $pages = max(1, (int)ceil(max(0, $total) / $size));
        $page = is_scalar($page) ? max(1, min($pages, (int)$page)) : 1;
        return array('page'=>$page, 'perPage'=>$size, 'total'=>max(0,$total), 'totalPages'=>$pages, 'offset'=>($page-1)*$size);
    }
    public static function error(): \WP_Error {
        return new \WP_Error('edu_games_ranking_read', '순위를 불러오지 못했습니다. 다시 시도하세요.', array('status'=>500));
    }
}
