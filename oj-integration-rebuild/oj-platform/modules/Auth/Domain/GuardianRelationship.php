<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

enum GuardianRelationship: string
{
    case Parent = 'parent';
    case LegalGuardian = 'legal_guardian';

    public function label(): string
    {
        return match ($this) {
            self::Parent => '부모',
            self::LegalGuardian => '법적으로 지정된 후견인',
        };
    }
}
