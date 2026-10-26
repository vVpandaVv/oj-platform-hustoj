<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemBookVisibility;
use DomainException;

final class ProblemBookRequestFactory
{
    /**
     * @return array{
     *     book_id:?int,
     *     parent_id:?int,
     *     name:string,
     *     slug:string,
     *     description:string,
     *     visibility:ProblemBookVisibility,
     *     sort_order:int,
     *     problem_ids:list<int>
     * }
     */
    public function book(HttpRequest $request): array
    {
        $visibility = ProblemBookVisibility::tryFrom(
            $request->bodyString('visibility')
        );
        if ($visibility === null) {
            throw new DomainException('문제집 공개 범위가 올바르지 않습니다.');
        }
        $bookId = $request->bodyInt('book_id');
        $parentId = $request->bodyInt('parent_id');

        return [
            'book_id' => $bookId > 0 ? $bookId : null,
            'parent_id' => $parentId > 0 ? $parentId : null,
            'name' => $request->bodyString('name'),
            'slug' => $request->bodyString('slug'),
            'description' => $request->bodyString('description'),
            'visibility' => $visibility,
            'sort_order' => max(0, $request->bodyInt('sort_order')),
            'problem_ids' => $request->bodyIntList('problem_ids'),
        ];
    }
}
