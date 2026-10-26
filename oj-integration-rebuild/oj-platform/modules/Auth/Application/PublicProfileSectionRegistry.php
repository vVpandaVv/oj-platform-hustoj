<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\PublicProfileSectionProvider;
use CodingPanda\OjPlatform\Modules\Auth\Domain\PublicProfileSection;
use InvalidArgumentException;
use Throwable;

final class PublicProfileSectionRegistry
{
    /** @var array<string, PublicProfileSectionProvider> */
    private array $providers = [];

    public function __construct(private readonly ErrorReporter $errors)
    {
    }

    public function register(PublicProfileSectionProvider $provider): void
    {
        $key = $provider->key();
        if (
            preg_match('/^[a-z][a-z0-9_.]*$/', $key) !== 1
            || isset($this->providers[$key])
        ) {
            throw new InvalidArgumentException('프로필 섹션 Provider 키가 중복되었거나 올바르지 않습니다.');
        }
        $this->providers[$key] = $provider;
    }

    /** @return list<PublicProfileSection> */
    public function sections(
        int $viewerUserId,
        int $targetUserId,
        string $targetUserLogin,
        int $limit = 5
    ): array {
        $providers = array_values($this->providers);
        usort(
            $providers,
            static fn (
                PublicProfileSectionProvider $left,
                PublicProfileSectionProvider $right
            ): int => [$left->priority(), $left->key()]
                <=> [$right->priority(), $right->key()]
        );
        $sections = [];
        foreach ($providers as $provider) {
            try {
                $section = $provider->provide(
                    $viewerUserId,
                    $targetUserId,
                    $targetUserLogin,
                    max(1, min(10, $limit))
                );
                if ($section !== null && $section->key() !== $provider->key()) {
                    throw new \LogicException('프로필 섹션 Provider 결과가 등록 계약과 일치하지 않습니다.');
                }
                if ($section !== null) {
                    $sections[] = $section;
                }
            } catch (Throwable $error) {
                $this->errors->report($error);
            }
        }

        return $sections;
    }
}
