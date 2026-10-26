<?php
declare(strict_types=1);
namespace OjPrimm\Application;

interface ActorRevalidationAccess
{
    /** Refresh request-local WordPress role/user state after a long AI call. */
    public function refreshActor(): void;
}
