<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use Throwable;

final class WordPressManagedUserNameGuard
{
    public function __construct(private readonly UserProfileRepository $profiles)
    {
    }

    public function register(): void
    {
        if (! function_exists('add_filter')) {
            return;
        }
        add_filter('add_user_metadata', [$this, 'filterWrite'], PHP_INT_MAX, 5);
        add_filter('update_user_metadata', [$this, 'filterWrite'], PHP_INT_MAX, 5);
        add_filter('delete_user_metadata', [$this, 'filterDelete'], PHP_INT_MAX, 5);
        add_filter('wp_pre_insert_user_data', [$this, 'preserveFallbackName'], PHP_INT_MAX, 4);
    }

    public function filterWrite(
        mixed $check,
        int $userId,
        string $key,
        mixed $value,
        mixed $extra = null
    ): mixed {
        unset($value, $extra);
        if ($key !== 'nickname' || WordPressManagedUserNameWriteScope::allows($userId)) {
            return $check;
        }
        try {
            return $userId > 0 && $this->profiles->find($userId) === null ? $check : false;
        } catch (Throwable) {
            return false;
        }
    }

    public function filterDelete(
        mixed $check,
        int $userId,
        string $key,
        mixed $value,
        bool $deleteAll = false
    ): mixed {
        // A key-wide delete could also remove protected OJ names outside the target scope.
        if ($key === 'nickname' && $deleteAll) {
            return false;
        }

        return $this->filterWrite($check, $userId, $key, $value);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function preserveFallbackName(array $data, bool $update, ?int $userId, array $userdata): array
    {
        unset($userdata);
        if (! $update || $userId === null || ! array_key_exists('display_name', $data)
            || WordPressManagedUserNameWriteScope::allows($userId)) {
            return $data;
        }
        try {
            if ($this->profiles->find($userId) === null || trim((string) get_user_meta($userId, 'nickname', true)) !== '') {
                return $data;
            }
            $user = get_user_by('id', $userId);
            if (! is_object($user) || (int) ($user->ID ?? 0) !== $userId) {
                unset($data['display_name']);
                return $data;
            }
            $previousName = (string) ($user->display_name ?? '');
            if ((string) $data['display_name'] === $previousName) {
                return $data;
            }
            // Legacy blank names fall back to display_name; freeze that existing value
            // before a native or OJ self-service update changes the public name.
            WordPressManagedUserNameWriteScope::run(
                $userId,
                static fn () => update_user_meta($userId, 'nickname', wp_slash($previousName))
            );
            if ($previousName === '' || (string) get_user_meta($userId, 'nickname', true) !== $previousName) {
                $data['display_name'] = $previousName;
            }
        } catch (Throwable) {
            // Preserve other account updates while refusing an unverifiable name change.
            unset($data['display_name']);
        }

        return $data;
    }
}
