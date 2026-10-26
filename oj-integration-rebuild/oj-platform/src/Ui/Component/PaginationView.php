<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Component;

final class PaginationView
{
    /**
     * @param array<string, mixed> $query
     */
    public function links(
        int $page,
        int $pageCount,
        string $basePath,
        array $query = []
    ): string {
        if ($pageCount <= 1) {
            return '';
        }
        $links = '';
        $previous = 0;
        foreach ($this->pageNumbers($page, $pageCount) as $number) {
            if ($number - $previous > 1) {
                $links .= '<span class="ojp-pagination__ellipsis" aria-hidden="true">…</span>';
            }
            $isCurrent = $number === $page;
            $links .= '<a class="ojp-pagination__link'
                . ($isCurrent ? ' is-active' : '') . '"'
                . ($isCurrent ? ' aria-current="page"' : '')
                . ' href="' . $this->escape($this->url($basePath, $query, $number))
                . '">' . $number . '</a>';
            $previous = $number;
        }

        return '<nav class="ojp-pagination ojp-pagination--pages" aria-label="페이지 이동">'
            . $links . '</nav>';
    }

    /**
     * @return list<int>
     */
    private function pageNumbers(int $page, int $pageCount): array
    {
        $numbers = [1, $pageCount];
        for ($offset = -2; $offset <= 2; $offset++) {
            $candidate = $page + $offset;
            if ($candidate >= 1 && $candidate <= $pageCount) {
                $numbers[] = $candidate;
            }
        }
        $numbers = array_values(array_unique($numbers));
        sort($numbers, SORT_NUMERIC);

        return $numbers;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function url(string $basePath, array $query, int $page): string
    {
        $parameters = array_merge($query, ['page' => $page]);

        return $basePath . '?' . http_build_query(
            $parameters,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
