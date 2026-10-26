<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Application;

use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockSetting;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use DomainException;

final class HomeSettingsService
{
    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function snapshot(): SettingsSnapshot
    {
        return $this->settings->read(
            'home',
            ['blocks' => array_map(
                static fn (HomeBlockSetting $block): array => $block->toArray(),
                $this->defaults()
            )]
        );
    }

    /**
     * @return list<HomeBlockSetting>
     */
    public function blocks(): array
    {
        $value = $this->snapshot()->value();
        $raw = is_array($value) && is_array($value['blocks'] ?? null)
            ? $value['blocks']
            : [];
        $blocks = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                throw new DomainException('홈 구성 저장 형식이 올바르지 않습니다.');
            }
            $block = HomeBlockSetting::fromArray($item);
            if (isset($blocks[$block->key()])) {
                throw new DomainException('홈 블록이 중복되었습니다.');
            }
            $blocks[$block->key()] = $block;
        }
        foreach ($this->defaults() as $default) {
            $blocks[$default->key()] ??= $default;
        }
        uasort(
            $blocks,
            static fn (HomeBlockSetting $left, HomeBlockSetting $right): int =>
                [$left->order(), $left->key()] <=> [$right->order(), $right->key()]
        );

        return array_values($blocks);
    }

    public function block(string $key): HomeBlockSetting
    {
        foreach ($this->blocks() as $block) {
            if ($block->key() === $key) {
                return $block;
            }
        }
        throw new DomainException('등록되지 않은 홈 블록입니다.');
    }

    /**
     * @param list<HomeBlockSetting> $blocks
     */
    public function save(array $blocks, int $expectedRevision): SettingsSnapshot
    {
        $indexed = [];
        foreach ($blocks as $block) {
            if (! $block instanceof HomeBlockSetting || isset($indexed[$block->key()])) {
                throw new DomainException('홈 블록이 중복되었거나 올바르지 않습니다.');
            }
            $indexed[$block->key()] = $block;
        }
        $expectedKeys = array_map(
            static fn (HomeBlockSetting $block): string => $block->key(),
            $this->defaults()
        );
        sort($expectedKeys);
        $actualKeys = array_keys($indexed);
        sort($actualKeys);
        if ($expectedKeys !== $actualKeys) {
            throw new DomainException('홈 구성은 등록된 Provider만 사용할 수 있습니다.');
        }
        uasort(
            $indexed,
            static fn (HomeBlockSetting $left, HomeBlockSetting $right): int =>
                [$left->order(), $left->key()] <=> [$right->order(), $right->key()]
        );

        return $this->settings->save(
            'home',
            ['blocks' => array_map(
                static fn (HomeBlockSetting $block): array => $block->toArray(),
                array_values($indexed)
            )],
            $expectedRevision
        );
    }

    /**
     * @return list<HomeBlockSetting>
     */
    private function defaults(): array
    {
        return [
            new HomeBlockSetting('continue_learning', 10, 4, true),
            new HomeBlockSetting('learning_spaces', 20, 4, true),
            new HomeBlockSetting('recent_submissions', 30, 5, true),
            new HomeBlockSetting('notices', 40, 5, true),
            new HomeBlockSetting('staff_shortcuts', 50, 4, true),
        ];
    }
}
