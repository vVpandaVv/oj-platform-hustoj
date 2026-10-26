<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\RegistryDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\AuthenticationRequirement;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteArea;
use InvalidArgumentException;

final class RouteDefinition implements RegistryDefinition
{
    /**
     * @var list<string>
     */
    private readonly array $routeMethods;

    public function __construct(
        private readonly string $routeName,
        private readonly ModuleId $moduleOwner,
        private readonly string $featureKey,
        private readonly string $routePath,
        private readonly RouteArea $routeArea,
        array $methods,
        private readonly AuthenticationRequirement $authentication,
        private readonly string $responseType
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $this->routeName) !== 1) {
            throw new InvalidArgumentException('Route 이름 형식이 올바르지 않습니다.');
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $this->featureKey) !== 1) {
            throw new InvalidArgumentException('Route 기능 키 형식이 올바르지 않습니다.');
        }

        $pathWithoutParameters = preg_replace(
            '/\{[a-z][a-z0-9_]*\}/',
            '',
            $this->routePath
        );
        if (
            $pathWithoutParameters === null
            || ! str_starts_with($this->routePath, '/')
            || str_contains($this->routePath, '//')
            || str_contains($pathWithoutParameters, '{')
            || str_contains($pathWithoutParameters, '}')
            || preg_match('/\s/', $this->routePath) === 1
        ) {
            throw new InvalidArgumentException('Route 경로 형식이 올바르지 않습니다.');
        }

        $normalizedMethods = array_values(array_unique(array_map(
            static fn (mixed $method): string => strtoupper((string) $method),
            $methods
        )));

        if ($normalizedMethods === [] || array_diff($normalizedMethods, ['GET', 'HEAD']) !== []) {
            throw new InvalidArgumentException('화면 Route는 GET과 HEAD만 사용할 수 있습니다.');
        }

        if (! in_array($this->responseType, ['html', 'file'], true)) {
            throw new InvalidArgumentException('Route 응답 형식이 올바르지 않습니다.');
        }

        if (
            ($this->routeArea === RouteArea::Response)
            !== ($this->responseType === 'file')
        ) {
            throw new InvalidArgumentException('파일 응답 Route는 response 영역으로 분류해야 합니다.');
        }

        $this->routeMethods = $normalizedMethods;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            (string) ($definition['name'] ?? ''),
            new ModuleId((string) ($definition['owner_module'] ?? '')),
            (string) ($definition['feature_key'] ?? ''),
            (string) ($definition['path'] ?? ''),
            RouteArea::from((string) ($definition['area'] ?? '')),
            array_values((array) ($definition['methods'] ?? [])),
            AuthenticationRequirement::from((string) ($definition['authentication'] ?? '')),
            (string) ($definition['response_type'] ?? '')
        );
    }

    public function key(): string
    {
        return $this->routeName;
    }

    public function owner(): ModuleId
    {
        return $this->moduleOwner;
    }

    public function featureKey(): string
    {
        return $this->featureKey;
    }

    public function path(): string
    {
        return $this->routePath;
    }

    public function area(): RouteArea
    {
        return $this->routeArea;
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return $this->routeMethods;
    }

    public function authentication(): AuthenticationRequirement
    {
        return $this->authentication;
    }

    public function responseType(): string
    {
        return $this->responseType;
    }

    /**
     * @return list<string>
     */
    public function parameters(): array
    {
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $this->routePath, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
