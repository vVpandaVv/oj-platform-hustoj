<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusReader;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressSmtpConfigurator;

final class OperationsBundle
{
    /**
     * @param array<string, object> $services
     */
    public function __construct(
        private readonly WordPressSmtpConfigurator $smtpConfigurator,
        private readonly array $services
    ) {
    }

    public function smtpConfigurator(): WordPressSmtpConfigurator
    {
        return $this->smtpConfigurator;
    }

    public function status(): SystemStatusReader
    {
        $status = $this->services[SystemStatusReader::class] ?? null;
        if (! $status instanceof SystemStatusReader) {
            throw new \LogicException('운영 상태 조회 서비스가 없습니다.');
        }

        return $status;
    }

    /**
     * @template T of object
     * @param class-string<T> $serviceId
     * @return T
     */
    public function service(string $serviceId): object
    {
        $service = $this->services[$serviceId] ?? null;
        if (! is_object($service) || ! $service instanceof $serviceId) {
            throw new \LogicException('운영 조립 서비스가 없습니다: ' . $serviceId);
        }

        return $service;
    }

    public function bindInto(BuildContainer $container): void
    {
        foreach ($this->services as $serviceId => $service) {
            $container->bind($serviceId, $service);
        }
    }
}
