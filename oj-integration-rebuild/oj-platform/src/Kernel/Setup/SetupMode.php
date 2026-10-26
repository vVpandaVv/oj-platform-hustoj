<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup;

enum SetupMode: string
{
    case FreshInstall = 'fresh_install';
    case Resume = 'resume';
    case AlreadyInstalled = 'already_installed';
    case MigrationTarget = 'migration_target';
    case NameCollision = 'name_collision';

    public function label(): string
    {
        return match ($this) {
            self::FreshInstall => '신규 설치',
            self::Resume => '설치 계속',
            self::AlreadyInstalled => '설치 완료',
            self::MigrationTarget => '기존 데이터 확인',
            self::NameCollision => '테이블 이름 충돌',
        };
    }

    public function allowsExecution(): bool
    {
        return in_array($this, [
            self::FreshInstall,
            self::Resume,
            self::MigrationTarget,
        ], true);
    }
}
