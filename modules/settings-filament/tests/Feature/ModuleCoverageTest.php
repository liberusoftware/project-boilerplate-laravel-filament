<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Liberu\Foundation\SettingsFilament\Pages\ManageSiteSettings;
use Liberu\Foundation\SettingsFilament\SettingsFilamentPlugin;
use Liberu\Foundation\SettingsFilament\SettingsFilamentServiceProvider;

it('covers SettingsFilamentPlugin, service provider, and ManageSiteSettings title, navigation, and form schema', function (): void {
    $provider = $this->app->register(SettingsFilamentServiceProvider::class, force: true);
    expect($provider)->toBeInstanceOf(SettingsFilamentServiceProvider::class);

    $plugin = SettingsFilamentPlugin::make();
    expect($plugin->getId())->toBe('liberu-settings');

    $panel = Panel::make()->id('test-settings-panel');
    $plugin->register($panel);
    $plugin->boot($panel);

    expect($panel->getPages())->toContain(ManageSiteSettings::class)
        ->and(ManageSiteSettings::getNavigationLabel())->toBeString();

    $page = new ManageSiteSettings();
    expect($page->getTitle())->toBeString();

    $schema = $page->form(Schema::make($page));
    expect($schema->getComponents())->toHaveCount(4);
});
