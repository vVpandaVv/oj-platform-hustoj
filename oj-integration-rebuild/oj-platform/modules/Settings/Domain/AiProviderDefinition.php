<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Domain;

use InvalidArgumentException;

final class AiProviderDefinition
{
    /**
     * @param list<string> $authenticationMethods
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $adapterKey,
        private readonly string $defaultBaseUrl,
        private readonly string $defaultModel,
        private readonly array $authenticationMethods,
        private readonly bool $selfHosted,
        private readonly string $configurationExample,
        private readonly string $requestExample
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_]*$/', $this->key) !== 1
            || preg_match('/^[a-z][a-z0-9_]*$/', $this->adapterKey) !== 1
            || trim($this->label) === ''
            || trim($this->defaultBaseUrl) === ''
            || trim($this->defaultModel) === ''
            || $this->authenticationMethods === []
        ) {
            throw new InvalidArgumentException('AI Provider 정의가 올바르지 않습니다.');
        }
        foreach ($this->authenticationMethods as $method) {
            if (! in_array($method, ['bearer', 'x_api_key'], true)) {
                throw new InvalidArgumentException('AI Provider 인증 방식이 올바르지 않습니다.');
            }
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function adapterKey(): string
    {
        return $this->adapterKey;
    }

    public function defaultBaseUrl(): string
    {
        return $this->defaultBaseUrl;
    }

    public function defaultModel(): string
    {
        return $this->defaultModel;
    }

    /**
     * @return list<string>
     */
    public function authenticationMethods(): array
    {
        return $this->authenticationMethods;
    }

    public function isSelfHosted(): bool
    {
        return $this->selfHosted;
    }

    public function configurationExample(): string
    {
        return $this->configurationExample;
    }

    public function requestExample(): string
    {
        return $this->requestExample;
    }
}
