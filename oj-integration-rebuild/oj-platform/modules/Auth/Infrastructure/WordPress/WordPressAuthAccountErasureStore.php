<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthAccountErasureStore;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;

final class WordPressAuthAccountErasureStore implements AuthAccountErasureStore
{
    public function __construct(private readonly ApplicationDatabase $database)
    {
    }

    public function lock(int $userId): void
    {
        $this->database->update(
            'user_profiles',
            [
                'access_status' => 'suspended',
                'access_status_changed_by_user_id' => $userId,
                'access_status_changed_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['user_id' => $userId],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );
    }

    public function erasePrivateData(int $userId): void
    {
        $this->database->delete('user_preferences', ['user_id' => $userId], ['%d']);
        $this->database->delete('guardian_verifications', ['user_id' => $userId], ['%d']);
        $this->database->update(
            'user_agreement_log',
            ['ip_address' => null, 'user_agent' => null],
            ['user_id' => $userId],
            ['%s', '%s'],
            ['%d']
        );
        $this->database->update(
            'user_profiles',
            [
                'affiliation' => null,
                'birth_date' => null,
                'access_status' => 'suspended',
                'must_change_password' => 0,
                'last_login_at' => null,
                'registration_requirements' => null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['user_id' => $userId],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s'],
            ['%d']
        );
    }

    public function verify(int $userId): bool
    {
        $privateRows = (int) $this->database->value(
            'SELECT (SELECT COUNT(*) FROM `user_preferences` WHERE `user_id` = %d)'
            . ' + (SELECT COUNT(*) FROM `guardian_verifications` WHERE `user_id` = %d)'
            . ' + (SELECT COUNT(*) FROM `user_agreement_log` WHERE `user_id` = %d'
            . ' AND (`ip_address` IS NOT NULL OR `user_agent` IS NOT NULL))',
            [$userId, $userId, $userId]
        );
        $profile = $this->database->row(
            'SELECT `affiliation`, `birth_date`, `access_status`, `last_login_at`,'
            . ' `registration_requirements` FROM `user_profiles` WHERE `user_id` = %d',
            [$userId]
        );

        return $privateRows === 0
            && ($profile === null || (
                ($profile['affiliation'] ?? null) === null
                && ($profile['birth_date'] ?? null) === null
                && ($profile['access_status'] ?? null) === 'suspended'
                && ($profile['last_login_at'] ?? null) === null
                && ($profile['registration_requirements'] ?? null) === null
            ));
    }
}
