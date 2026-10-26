<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\UploadedFile;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserImportCandidate;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use DomainException;
use RuntimeException;

final class UserImportCsvParser
{
    private const MAX_FILE_BYTES = 2097152;
    private const MAX_ROWS = 500;

    /**
     * @param DefinitionRegistry<RoleDefinition> $roles
     */
    public function __construct(
        private readonly AccountInputPolicy $inputs,
        private readonly WordPressUserGateway $users,
        private readonly DefinitionRegistry $roles
    ) {
    }

    public function parse(
        UploadedFile $file,
        bool $canAssignOperationalRoles
    ): UserImportPreview {
        if (! $file->isSuccessful()) {
            throw new DomainException('업로드할 CSV 파일을 선택해야 합니다.');
        }
        if ($file->size() > self::MAX_FILE_BYTES) {
            throw new DomainException('CSV 파일은 2MB 이하여야 합니다.');
        }
        if (! is_file($file->temporaryPath()) || ! is_readable($file->temporaryPath())) {
            throw new DomainException('업로드한 CSV 파일을 읽을 수 없습니다.');
        }

        $stream = fopen($file->temporaryPath(), 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('CSV 파일을 열지 못했습니다.');
        }

        try {
            $header = fgetcsv($stream, 10000, ',', '"', '');
            if (! is_array($header)) {
                throw new DomainException('CSV 머리글을 찾을 수 없습니다.');
            }
            $columns = $this->columns($header);
            $candidates = [];
            $seenLogins = [];
            $seenEmails = [];
            $rowNumber = 1;

            while (($row = fgetcsv($stream, 10000, ',', '"', '')) !== false) {
                ++$rowNumber;
                if ($this->isBlankRow($row)) {
                    continue;
                }
                if (count($candidates) >= self::MAX_ROWS) {
                    throw new DomainException('한 번에 최대 500명까지 미리볼 수 있습니다.');
                }

                $values = $this->rowValues($row, $columns);
                $candidates[] = $this->candidate(
                    $rowNumber,
                    $values,
                    $seenLogins,
                    $seenEmails,
                    $canAssignOperationalRoles
                );
            }
        } finally {
            fclose($stream);
        }

        if ($candidates === []) {
            throw new DomainException('등록할 사용자 행이 없습니다.');
        }

        return new UserImportPreview($candidates);
    }

    /**
     * @param list<mixed> $header
     * @return array<string, int>
     */
    private function columns(array $header): array
    {
        $allowed = [
            'user_login',
            'email',
            'password',
            'display_name',
            'affiliation',
            'birth_date',
            'role',
        ];
        $columns = [];
        foreach ($header as $index => $value) {
            $name = strtolower(trim((string) $value));
            if ($index === 0) {
                $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;
            }
            if (
                $name === ''
                || ! in_array($name, $allowed, true)
                || isset($columns[$name])
            ) {
                throw new DomainException('CSV 머리글 이름이 올바르지 않거나 중복되었습니다.');
            }
            $columns[$name] = (int) $index;
        }

        foreach (['user_login', 'email', 'password', 'display_name'] as $required) {
            if (! isset($columns[$required])) {
                throw new DomainException('CSV에 필수 열이 없습니다: ' . $required);
            }
        }

        return $columns;
    }

    /**
     * @param list<mixed> $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<mixed> $row
     * @param array<string, int> $columns
     * @return array<string, string>
     */
    private function rowValues(array $row, array $columns): array
    {
        $values = [];
        foreach ($columns as $name => $index) {
            $value = isset($row[$index]) ? (string) $row[$index] : '';
            if (preg_match('//u', $value) !== 1) {
                throw new DomainException('CSV는 UTF-8로 저장해야 합니다.');
            }
            $values[$name] = trim($value);
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     * @param array<string, true> $seenLogins
     * @param array<string, true> $seenEmails
     */
    private function candidate(
        int $rowNumber,
        array $values,
        array &$seenLogins,
        array &$seenEmails,
        bool $canAssignOperationalRoles
    ): UserImportCandidate {
        $errors = [];
        $userLogin = $this->validated(
            fn (): string => $this->inputs->userLogin($values['user_login'] ?? ''),
            'invalid_login',
            $errors,
            trim($values['user_login'] ?? '')
        );
        $email = $this->validated(
            fn (): string => $this->inputs->email($values['email'] ?? ''),
            'invalid_email',
            $errors,
            trim($values['email'] ?? '')
        );
        $password = $this->validated(
            fn (): string => $this->inputs->password($values['password'] ?? '', '초기 비밀번호'),
            'invalid_password',
            $errors,
            (string) ($values['password'] ?? '')
        );
        $displayName = $this->validated(
            fn (): string => $this->inputs->displayName($values['display_name'] ?? ''),
            'invalid_display_name',
            $errors,
            trim($values['display_name'] ?? '')
        );
        $affiliation = null;
        try {
            $affiliation = $this->inputs->affiliation($values['affiliation'] ?? null);
        } catch (DomainException) {
            $errors[] = 'invalid_affiliation';
        }

        $birthDate = null;
        try {
            $birthDate = $this->inputs->birthDate($values['birth_date'] ?? null);
        } catch (DomainException) {
            $errors[] = 'invalid_birth_date';
        }

        $role = trim($values['role'] ?? '') ?: 'subscriber';
        if ($role !== 'subscriber') {
            if (! $canAssignOperationalRoles) {
                $errors[] = 'role_not_allowed';
            } elseif (! $this->roles->find($role) instanceof RoleDefinition) {
                $errors[] = 'unknown_role';
            }
        }

        $loginKey = strtolower($userLogin);
        if ($userLogin !== '' && isset($seenLogins[$loginKey])) {
            $errors[] = 'duplicate_login';
        } elseif ($userLogin !== '') {
            $seenLogins[$loginKey] = true;
            if ($this->users->findByLogin($userLogin) !== null) {
                $errors[] = 'existing_login';
            }
        }

        $emailKey = strtolower($email);
        if ($email !== '' && isset($seenEmails[$emailKey])) {
            $errors[] = 'duplicate_email';
        } elseif ($email !== '') {
            $seenEmails[$emailKey] = true;
            if ($this->users->findByEmail($email) !== null) {
                $errors[] = 'existing_email';
            }
        }

        return new UserImportCandidate(
            $rowNumber,
            $userLogin,
            $email,
            $password,
            $displayName,
            $affiliation,
            $birthDate,
            $role,
            array_values(array_unique($errors))
        );
    }

    /**
     * @param callable(): string $validator
     * @param list<string> $errors
     */
    private function validated(
        callable $validator,
        string $errorCode,
        array &$errors,
        string $fallback
    ): string {
        try {
            return $validator();
        } catch (DomainException) {
            $errors[] = $errorCode;

            return $fallback;
        }
    }

}
