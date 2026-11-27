<?php

return [
    'assignable_roles' => [
        'member',
        'admin',
        'manager',
        'sales_rep',
        'free',
    ],

    'default_member_role' => 'sales_rep',

    'restricted_roles' => [
        'sales_rep',
        'free',
    ],

    'masked_roles' => [
        'free',
    ],

    /**
     * Config models cloned by TeamCloneService when duplicating a team template.
     * Host applications register their own structural model classes here.
     *
     * @var list<class-string>
     */
    'cloneable_models' => [],

    /**
     * Intra-config foreign-key edges to remap during team cloning:
     * ModelClass::class => ['foreign_key_column' => ReferencedModelClass::class]
     *
     * @var array<class-string, array<string, class-string>>
     */
    'clone_fk_edges' => [],

    /**
     * Optional audit log model class displayed by the Filament TeamRoleLogResource.
     *
     * @var class-string|null
     */
    'audit_log_model' => null,

    /**
     * Named route redirected to after creating a team via the Livewire component.
     */
    'tenant_profile_route' => 'filament.app.tenant.profile',
];
