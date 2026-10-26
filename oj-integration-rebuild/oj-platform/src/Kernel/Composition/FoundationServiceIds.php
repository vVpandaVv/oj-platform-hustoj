<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

final class FoundationServiceIds
{
    public const CAPABILITIES = 'registry.capabilities';
    public const ROLES = 'registry.roles';
    public const PERMISSION_PRESETS = 'registry.permission_presets';
    public const FEATURES = 'registry.features';
    public const SETTINGS = 'registry.settings';
    public const TARGET_TABLES = 'registry.target_tables';
    public const TABLE_SCHEMAS = 'registry.table_schemas';
    public const JUDGE_PROFILES = 'registry.judge_profiles';
    public const JOBS = 'registry.jobs';
    public const ROUTES = 'registry.routes';
    public const ACTIONS = 'registry.actions';
    public const MENUS = 'registry.menus';

    private function __construct()
    {
    }
}
