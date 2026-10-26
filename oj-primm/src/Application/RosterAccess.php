<?php
declare(strict_types=1);
namespace OjPrimm\Application;

interface RosterAccess
{
    /** Full roster in stable membership order, including learners without an attempt. */
    public function roster(string $type, string $id): array;
}
