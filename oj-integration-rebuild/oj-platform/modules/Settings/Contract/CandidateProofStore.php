<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface CandidateProofStore
{
    /**
     * On successful return the store owns the proof payload reference.
     * On failure ownership remains with the caller.
     */
    public function remember(string $scope, CandidateProof $proof): void;

    public function find(string $scope, int $actorUserId): ?CandidateProof;

    /**
     * Removes a proof and transfers payload ownership to the caller.
     */
    public function consume(string $scope, int $actorUserId): ?CandidateProof;

    /**
     * Removes a proof and destroys its owned payload.
     */
    public function forget(string $scope, int $actorUserId): void;
}
