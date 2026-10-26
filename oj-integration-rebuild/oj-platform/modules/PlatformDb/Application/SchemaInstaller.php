<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Application;

use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\PlatformConnection;
use RuntimeException;
use Throwable;

final class SchemaInstaller
{
    public function __construct(
        private readonly PlatformConnection $connection,
        private readonly SchemaSqlCompiler $compiler
    ) {
    }

    public function execute(InstallationPlan $plan): InstallationResult
    {
        if (! $this->connection->isAvailable()) {
            throw new RuntimeException('WordPress 데이터베이스 연결을 사용할 수 없습니다.');
        }

        if (! $plan->canExecute()) {
            throw new RuntimeException(sprintf(
                '동명 테이블 충돌로 설치를 중단했습니다: %s',
                implode(', ', $plan->collisions())
            ));
        }

        $created = [];
        $updated = [];
        $skipped = [];

        foreach ($plan->steps() as $step) {
            if ($step->isAlreadyInstalled()) {
                $skipped[] = $step->schema()->key();
                continue;
            }

            if ($step->requiresUpgrade()) {
                $this->runTableOperation(
                    $step->schema()->key(),
                    '업그레이드',
                    function () use ($step, $plan): void {
                        $this->connection->synchronize(
                            $this->compiler->compileSynchronize(
                                $step->schema(),
                                $plan->installationId()
                            )
                        );
                        $this->connection->execute(
                            $this->compiler->compileOwnershipUpdate(
                                $step->schema(),
                                $plan->installationId()
                            )
                        );
                    }
                );
                $updated[] = $step->schema()->key();
                continue;
            }

            $this->runTableOperation(
                $step->schema()->key(),
                '생성',
                function () use ($step, $plan): void {
                    $this->connection->execute(
                        $this->compiler->compileCreate(
                            $step->schema(),
                            $plan->installationId()
                        )
                    );
                }
            );
            $created[] = $step->schema()->key();
        }

        return new InstallationResult(
            $plan->hash(),
            $created,
            $updated,
            $skipped,
            $plan->missingSchemas(),
            $plan->isCompleteAfterExecution()
        );
    }

    /** @param callable(): void $operation */
    private function runTableOperation(
        string $table,
        string $action,
        callable $operation
    ): void {
        try {
            $operation();
        } catch (Throwable $error) {
            throw new RuntimeException(sprintf(
                '플랫폼 테이블 [%s] %s에 실패했습니다: %s',
                $table,
                $action,
                $error->getMessage()
            ), 0, $error);
        }
    }
}
