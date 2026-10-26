<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\DashboardBlockProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardBlock;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardContext;
use InvalidArgumentException;
use Throwable;

final class DashboardBlockRegistry
{
    /** @var array<string, DashboardBlockProvider> */
    private array $providers = [];

    public function __construct(private readonly ErrorReporter $errors)
    {
    }

    public function register(DashboardBlockProvider $provider): void
    {
        $key = $provider->key();
        if (
            preg_match('/^[a-z][a-z0-9_.]*$/', $key) !== 1
            || isset($this->providers[$key])
        ) {
            throw new InvalidArgumentException('대시보드 Provider 키가 중복되었거나 올바르지 않습니다.');
        }
        $this->providers[$key] = $provider;
    }

    /** @return list<DashboardBlock> */
    public function load(DashboardContext $context): array
    {
        $providers = array_values($this->providers);
        usort(
            $providers,
            static fn (DashboardBlockProvider $left, DashboardBlockProvider $right): int =>
                [$left->priority(), $left->key()] <=> [$right->priority(), $right->key()]
        );
        $blocks = [];
        foreach ($providers as $provider) {
            try {
                $block = $provider->load($context);
                if ($block === null) {
                    continue;
                }
                if ($block->key() !== $provider->key() || $block->kind() !== $provider->kind()) {
                    throw new \LogicException('대시보드 Provider 결과가 등록 계약과 일치하지 않습니다.');
                }
                $blocks[] = $block;
            } catch (Throwable $error) {
                $this->errors->report($error);
                $blocks[] = DashboardBlock::failure(
                    $provider->key(),
                    $provider->title(),
                    $provider->kind()
                );
            }
        }

        return $blocks;
    }
}
