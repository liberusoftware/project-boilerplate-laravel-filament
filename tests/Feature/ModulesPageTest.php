<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Liberu\Foundation\ModuleManagerFilament\Pages\ModuleManagement;

it('serves the Modules page in the admin panel under Settings', function () {
    expect(Route::has('filament.admin.pages.modules'))->toBeTrue()
        ->and(Filament::getPanel('admin')->getPages())->toContain(ModuleManagement::class)
        ->and(ModuleManagement::getNavigationGroup())->toBe('Settings');
});

it('lets administrators manage modules and nobody else', function () {
    config(['app.admin_emails' => ['ops@example.com']]);

    expect(Gate::forUser(User::factory()->create(['email' => 'ops@example.com']))->allows(ModuleManagement::ABILITY))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows(ModuleManagement::ABILITY))->toBeFalse();
});
