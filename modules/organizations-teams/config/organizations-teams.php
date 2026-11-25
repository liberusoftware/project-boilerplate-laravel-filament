<?php

return [
    'assignable_roles' => [
        'member',
        'admin',
        'manager',
        'sales_rep',
        'free',
    ],

    'default_team_name' => 'Default Team',

    /*
    |--------------------------------------------------------------------------
    | Team Template Cloning
    |--------------------------------------------------------------------------
    |
    | Declare which team-scoped Eloquent models or table names should be copied
    | when cloning a team as a template. Declared database foreign keys between
    | cloned tables are remapped automatically; use `fk_edges` to declare any
    | additional undeclared foreign-key columns:
    |
    |   'fk_edges' => [
    |       App\Models\ChildModel::class => ['parent_id' => App\Models\ParentModel::class],
    |   ],
    */
    'clone' => [
        'models' => [],
        'fk_edges' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    |
    | Optional host-supplied audit logger service (must expose a `record(string
    | $action, string $description, ?Model $auditable = null)` method) and model
    | class used by the team role log resource. When null, the module falls back
    | to Spatie Activitylog.
    */
    'audit_logger' => null,
    'audit_log_model' => null,
];
