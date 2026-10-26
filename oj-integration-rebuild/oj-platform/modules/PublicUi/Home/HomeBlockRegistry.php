<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Home;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\HomeBlockProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlock;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockContext;
use InvalidArgumentException;
use Throwable;

final class HomeBlockRegistry
{
    /** @var array<string, HomeBlockProvider> */
    private array $providers = [];

    public function __construct(private readonly ErrorReporter $errors)
    {
    }

    public function register(HomeBlockProvider $provider): void
    {
        $key = $provider->key();
        if (
            preg_match('/^[a-z][a-z0-9_.]*$/', $key) !== 1
            || isset($this->providers[$key])
        ) {
            throw new InvalidArgumentException('홈 블록 Provider 키가 중복되었거나 올바르지 않습니다.');
        }
        $this->providers[$key] = $provider;
    }

    public function load(
        string $key,
        HomeBlockContext $context,
        int $limit
    ): ?HomeBlock {
        $provider = $this->providers[$key] ?? null;
        if (! $provider instanceof HomeBlockProvider) {
            return null;
        }
        try {
            $block = $provider->load($context, max(1, min(6, $limit)));
            if ($block !== null && $block->key() !== $key) {
                throw new \LogicException('홈 블록 Provider가 등록 키와 다른 결과를 반환했습니다.');
            }

            return $block;
        } catch (Throwable $error) {
            $this->errors->report($error);

            return HomeBlock::failure($key, $provider->title());
        }
    }
}
