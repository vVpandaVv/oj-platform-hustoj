<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;
interface AcceptedSubmissionTimeGateway
{
    /** @param list<string> $userLogins @param list<int> $problemIds
     * @return list<array{user_login:string,problem_id:int,accepted_at:string}> */
    public function earliestAcceptedTimes(array $userLogins, array $problemIds, ?string $from, ?string $until, int $maximum): array;
}
