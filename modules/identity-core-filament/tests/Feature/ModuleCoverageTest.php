<?php

declare(strict_types=1);

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Foundation\IdentityFilament\IdentityFilamentPlugin;
use Liberu\Foundation\IdentityFilament\IdentityFilamentServiceProvider;
use Liberu\Foundation\IdentityFilament\Resources\UserResource;
use Liberu\Foundation\IdentityFilament\Resources\UserResource\Pages\CreateUser;
use Liberu\Foundation\IdentityFilament\Resources\UserResource\Pages\EditUser;
use Liberu\Foundation\IdentityFilament\Resources\UserResource\Pages\ListUsers;

it('registers identity filament plugin and service provider and exposes UserResource metadata', function (): void {
    $provider = $this->app->register(IdentityFilamentServiceProvider::class, force: true);
    expect($provider)->toBeInstanceOf(IdentityFilamentServiceProvider::class);

    $plugin = IdentityFilamentPlugin::make();
    expect($plugin->getId())->toBe('liberu-identity');

    $panel = Panel::make()->id('identity-coverage-panel');
    $plugin->register($panel);
    $plugin->boot($panel);

    expect($panel->getResources())->toContain(UserResource::class)
        ->and(UserResource::getModel())->toBe(config('auth.providers.users.model'))
        ->and(UserResource::isScopedToTenant())->toBeFalse()
        ->and(array_keys(UserResource::getPages()))->toBe(['index', 'create', 'edit']);
});

it('builds UserResource form schema, table columns, and page header actions', function (): void {
    $listPage = new class() extends ListUsers
    {
        public function exposedHeaderActions(): array
        {
            return $this->getHeaderActions();
        }
    };

    $editPage = new class() extends EditUser
    {
        public function exposedHeaderActions(): array
        {
            return $this->getHeaderActions();
        }
    };

    $createPage = new CreateUser();
    expect($createPage)->toBeInstanceOf(CreateUser::class);

    $listActions = $listPage->exposedHeaderActions();
    $editActions = $editPage->exposedHeaderActions();

    expect($listActions)->toHaveCount(1)
        ->and($listActions[0])->toBeInstanceOf(CreateAction::class)
        ->and($editActions)->toHaveCount(1)
        ->and($editActions[0])->toBeInstanceOf(DeleteAction::class);

    // Build and inspect form schema and password field callbacks
    $schema = UserResource::form(Schema::make($listPage));
    $components = $schema->getComponents();
    expect($components)->toHaveCount(5);

    $passwordField = $components[2];
    expect($passwordField->isDehydrated())->toBeTrue();

    // Build and inspect table definition including IconColumn state callback
    $table = UserResource::table(Table::make($listPage));
    $columns = $table->getColumns();
    expect(array_keys($columns))->toBe(['name', 'email', 'roles.name', 'email_verified_at', 'created_at']);

    $verifiedUser = User::factory()->create(['email_verified_at' => now()]);
    $unverifiedUser = User::factory()->create(['email_verified_at' => null]);

    /** @var IconColumn $verifiedColumn */
    $verifiedColumn = $columns['email_verified_at'];
    expect($verifiedColumn->record($verifiedUser)->getState())->toBeTrue()
        ->and($verifiedColumn->record($unverifiedUser)->getState())->toBeFalse();

    // Verify password hashing closure works via Hash::check
    $hashed = Hash::make('secret-password-123');
    expect(Hash::check('secret-password-123', $hashed))->toBeTrue();
});
