<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobTarget;
use DomainException;

final class GroupBulkMemberImportService
{
    private const MAX_ROWS = 100;

    public function __construct(
        private readonly GroupService $groups,
        private readonly WordPressUserGateway $users,
        private readonly JobDispatcher $jobs
    ) {
    }

    public function enqueue(
        int $actorUserId,
        int $groupId,
        string $csv
    ): JobEnqueueResult {
        $this->groups->findManaged($groupId, $actorUserId);
        $csv = trim($csv);
        if ($csv === '') {
            throw new DomainException('등록할 구성원을 입력해 주세요.');
        }
        $lines = preg_split('/\R/u', $csv) ?: [];
        if (count($lines) > self::MAX_ROWS) {
            throw new DomainException('일괄 등록은 한 번에 100명까지 가능합니다.');
        }

        $targets = [];
        $seenLogins = [];
        foreach ($lines as $index => $line) {
            $rowNumber = $index + 1;
            $columns = array_map('trim', str_getcsv($line, ',', '"', ''));
            if (count($columns) > 4) {
                throw new DomainException(
                    $rowNumber . '행의 열이 4개를 초과했습니다.'
                );
            }
            $login = $columns[0] ?? '';
            if ($login === '') {
                throw new DomainException(
                    $rowNumber . '행의 로그인 아이디가 비어 있습니다.'
                );
            }
            $normalizedLogin = strtolower($login);
            if (isset($seenLogins[$normalizedLogin])) {
                throw new DomainException(
                    $rowNumber . '행의 로그인 아이디가 중복되었습니다.'
                );
            }
            $seenLogins[$normalizedLogin] = true;

            $email = $columns[1] ?? '';
            $displayName = $columns[2] ?? $login;
            $password = $columns[3] ?? '';
            if ($this->users->findByLogin($login) === null) {
                if (
                    $email === ''
                    || filter_var($email, FILTER_VALIDATE_EMAIL) === false
                ) {
                    throw new DomainException(
                        $rowNumber . '행의 새 계정 이메일이 올바르지 않습니다.'
                    );
                }
                if ($displayName === '') {
                    throw new DomainException(
                        $rowNumber . '행의 새 계정 이름이 비어 있습니다.'
                    );
                }
                if ($password === '') {
                    throw new DomainException(
                        $rowNumber . '행의 새 계정 초기 비밀번호가 비어 있습니다.'
                    );
                }
            }

            $targets[] = new JobTarget(
                'group_member',
                $groupId . ':' . $rowNumber . ':' . $login,
                [
                    'group_id' => $groupId,
                    'row_number' => $rowNumber,
                    'user_login' => $login,
                    'email' => $email,
                    'display_name' => $displayName,
                    'password' => $password,
                ]
            );
        }

        return $this->jobs->enqueue(
            'group_member_csv_import',
            'selected',
            $actorUserId,
            $targets,
            [
                'columns_version' => '1',
                'passwords_stored_in_encrypted_payload' => true,
            ],
            [
                'group_id' => $groupId,
                'candidate_count' => count($targets),
            ],
            'groups.member_csv_import'
        );
    }
}
