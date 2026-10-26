<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Application;

use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderClient;
use LogicException;

final class AiProviderClientRegistry
{
    /**
     * @var array<string, AiProviderClient>
     */
    private array $clients = [];

    /**
     * @param list<AiProviderClient> $clients
     */
    public function __construct(array $clients = [])
    {
        foreach ($clients as $client) {
            $this->register($client);
        }
    }

    public function register(AiProviderClient $client): void
    {
        $key = $client->adapterKey();
        if (isset($this->clients[$key])) {
            throw new LogicException('AI Provider Adapter가 중복되었습니다: ' . $key);
        }
        $this->clients[$key] = $client;
    }

    public function get(string $adapterKey): AiProviderClient
    {
        $client = $this->clients[$adapterKey] ?? null;
        if (! $client instanceof AiProviderClient) {
            throw new LogicException('AI Provider Adapter가 없습니다: ' . $adapterKey);
        }

        return $client;
    }
}
