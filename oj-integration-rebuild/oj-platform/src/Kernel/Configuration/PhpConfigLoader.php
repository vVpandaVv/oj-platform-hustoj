<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Configuration;

use RuntimeException;

final class PhpConfigLoader
{
    public function __construct(private readonly string $configDirectory, private readonly array $contributions = [])
    {
    }

    /**
     * @return array<mixed>
     */
    public function load(string $name): array
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
            throw new RuntimeException(sprintf('설정 이름 형식이 올바르지 않습니다: %s', $name));
        }

        $path = rtrim($this->configDirectory, '/\\') . DIRECTORY_SEPARATOR . $name . '.php';
        if (! is_file($path)) {
            throw new RuntimeException(sprintf('설정 파일을 찾을 수 없습니다: %s', $name));
        }

        // Configuration scripts use local loop names; isolate their scope from the lookup key.
        $configuration = (static fn (string $file): mixed => require $file)($path);
        if (! is_array($configuration)) {
            throw new RuntimeException(sprintf('설정 파일은 배열을 반환해야 합니다: %s', $name));
        }

        $extra = $this->contributions[$name] ?? [];
        if (! is_array($extra)) { throw new RuntimeException('확장 설정은 배열이어야 합니다.'); }
        if (array_is_list($configuration) && array_is_list($extra)) { return [...$configuration, ...$extra]; }
        foreach ($extra as $key => $value) {
            if (array_key_exists($key, $configuration)) { throw new RuntimeException('기존 설정을 덮어쓸 수 없습니다: ' . $name . '.' . $key); }
            $configuration[$key] = $value;
        }
        return $configuration;
    }
}
