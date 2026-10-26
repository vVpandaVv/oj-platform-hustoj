<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Boot;

enum BootPhase: string
{
    case Configuration = 'configuration';
    case ModuleGraph = 'module_graph';
    case ContractRegistration = 'contract_registration';
    case RegistryFreeze = 'registry_freeze';
    case AdapterConnection = 'adapter_connection';
    case RuntimeReady = 'runtime_ready';
}
