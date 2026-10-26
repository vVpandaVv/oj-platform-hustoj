<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Registry;

use CodingPanda\OjPlatform\Kernel\Exception\KernelException;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDefinition;
use CodingPanda\OjPlatform\Modules\Jobs\Registry\JobHandlerRegistry;

final class JobHandlerContractValidator implements RegistrySetValidator
{
    /**
     * @param DefinitionRegistry<JobDefinition> $definitions
     * @param list<string> $completedOwnerModules
     */
    public function __construct(
        private readonly DefinitionRegistry $definitions,
        private readonly JobHandlerRegistry $handlers,
        private readonly array $completedOwnerModules
    ) {
    }

    public function validate(): void
    {
        foreach ($this->handlers->keys() as $jobType) {
            if (! $this->definitions->find($jobType) instanceof JobDefinition) {
                throw KernelException::invalidRegistry(sprintf(
                    '정의되지 않은 Jobs Handler입니다: %s',
                    $jobType
                ));
            }
        }

        $completedOwners = array_fill_keys(
            $this->completedOwnerModules,
            true
        );
        foreach ($this->definitions->all() as $definition) {
            if (
                isset($completedOwners[$definition->owner()->value()])
                && $this->handlers->find($definition->key()) === null
            ) {
                throw KernelException::invalidRegistry(sprintf(
                    '완료 모듈의 Jobs Handler가 없습니다: %s (%s)',
                    $definition->key(),
                    $definition->owner()->value()
                ));
            }
        }
    }
}
