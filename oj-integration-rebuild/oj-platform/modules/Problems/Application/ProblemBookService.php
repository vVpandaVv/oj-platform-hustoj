<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemBookRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\PersonalProblemBookItem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemBook;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemBookVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use DomainException;

final class ProblemBookService
{
    public function __construct(
        private readonly ProblemBookRepository $books,
        private readonly ProblemCatalogService $catalog,
        private readonly Authorization $permissions
    ) {
    }

    /**
     * @return array{
     *     public:list<array{book:ProblemBook, problems:list<JudgeProblem>}>,
     *     personal:list<array{item:PersonalProblemBookItem, problem:JudgeProblem}>
     * }
     */
    public function publicView(int $userId): array
    {
        $public = [];
        foreach ($this->books->publicBooks() as $book) {
            $public[] = [
                'book' => $book,
                'problems' => $this->visibleProblems($userId, $book->problemIds()),
            ];
        }
        $personal = [];
        foreach ($this->books->personalItems($userId) as $item) {
            $problem = $this->catalog->findVisible($userId, $item->problemId());
            if ($problem !== null) {
                $personal[] = ['item' => $item, 'problem' => $problem];
            }
        }

        return ['public' => $public, 'personal' => $personal];
    }

    /**
     * @return array{
     *     books:list<ProblemBook>,
     *     selected:?ProblemBook,
     *     problems:list<JudgeProblem>
     * }
     */
    public function managedView(int $userId, ?int $selectedBookId): array
    {
        $this->assertManage($userId);
        $books = $this->books->managedBooks();
        $selected = null;
        if ($selectedBookId !== null) {
            foreach ($books as $book) {
                if ($book->id() === $selectedBookId) {
                    $selected = $book;
                    break;
                }
            }
            if ($selected === null) {
                throw new DomainException('편집할 공용 문제집을 찾을 수 없습니다.');
            }
        }

        return [
            'books' => $books,
            'selected' => $selected,
            'problems' => $this->availableProblems($userId),
        ];
    }

    /**
     * @param list<int> $problemIdValues
     */
    public function save(
        int $userId,
        ?int $bookId,
        ?int $parentId,
        string $name,
        string $slug,
        string $description,
        ProblemBookVisibility $visibility,
        int $sortOrder,
        array $problemIdValues
    ): ProblemBook {
        $this->assertManage($userId);
        $problemIds = array_map(
            static fn (int $problemId): ProblemId => new ProblemId($problemId),
            array_values(array_unique($problemIdValues))
        );
        foreach ($problemIds as $problemId) {
            if (
                $this->catalog->findVisible($userId, $problemId) === null
                && $this->catalog->findManaged($userId, $problemId) === null
            ) {
                throw new DomainException(
                    '문제집에 추가할 수 없는 문제 번호가 포함되어 있습니다.'
                );
            }
        }

        return $this->books->save(
            $bookId,
            $parentId,
            trim($name),
            strtolower(trim($slug)),
            $description,
            $visibility,
            $sortOrder,
            $problemIds,
            $userId
        );
    }

    public function delete(int $userId, int $bookId): void
    {
        $this->assertManage($userId);
        if ($this->books->find($bookId) === null) {
            throw new DomainException('삭제할 공용 문제집을 찾을 수 없습니다.');
        }
        $this->books->delete($bookId);
    }

    public function addPersonal(
        int $userId,
        ProblemId $problemId,
        string $note = ''
    ): void {
        if ($this->catalog->findVisible($userId, $problemId) === null) {
            throw new DomainException('개인 문제집에 추가할 수 없는 문제입니다.');
        }
        $this->books->addPersonalItem($userId, $problemId, $note);
    }

    public function removePersonal(int $userId, ProblemId $problemId): void
    {
        $this->books->removePersonalItem($userId, $problemId);
    }

    public function hasPersonal(int $userId, ProblemId $problemId): bool
    {
        return $this->books->hasPersonalItem($userId, $problemId);
    }

    /**
     * @param list<ProblemId> $problemIds
     * @return list<JudgeProblem>
     */
    private function visibleProblems(int $userId, array $problemIds): array
    {
        $problems = [];
        foreach ($problemIds as $problemId) {
            $problem = $this->catalog->findVisible($userId, $problemId);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * @return list<JudgeProblem>
     */
    private const CATALOG_PAGE_SIZE = 100;
    private const CATALOG_MAX_PROBLEMS = 5000;

    /**
     * @return list<JudgeProblem>
     */
    private function availableProblems(int $userId): array
    {
        $items = [];
        foreach ($this->catalogPages(
            fn (int $limit, int $offset): array =>
                $this->catalog->publicPage('', $limit, $offset)
        ) as $problem) {
            $items[$problem->id()->value()] = $problem;
        }
        foreach ($this->catalogPages(
            fn (int $limit, int $offset): array =>
                $this->catalog->managedPage($userId, '', null, $limit, $offset)
        ) as $problem) {
            $items[$problem->id()->value()] = $problem;
        }
        ksort($items);

        return array_values($items);
    }

    /**
     * @param callable(int, int): list<JudgeProblem> $fetchPage
     * @return list<JudgeProblem>
     */
    private function catalogPages(callable $fetchPage): array
    {
        $items = [];
        $offset = 0;
        do {
            $batch = $fetchPage(self::CATALOG_PAGE_SIZE, $offset);
            array_push($items, ...$batch);
            $offset += count($batch);
        } while (
            count($batch) === self::CATALOG_PAGE_SIZE
            && $offset < self::CATALOG_MAX_PROBLEMS
        );

        return $items;
    }

    private function assertManage(int $userId): void
    {
        if (! $this->permissions->can($userId, 'oj_manage_problem_books')) {
            throw new DomainException('공용 문제집을 관리할 권한이 없습니다.');
        }
    }
}
