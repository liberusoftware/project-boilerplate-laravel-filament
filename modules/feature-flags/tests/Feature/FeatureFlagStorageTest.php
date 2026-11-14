<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('registers reversible migrations and persists scoped flag rules', function () {
    Artisan::call('migrate', ['--force' => true]);
    expect(Schema::hasTable('feature_flags'))->toBeTrue();
    DB::table('feature_flags')->insert(['key' => 'release', 'owner' => 'release-team', 'enabled' => true, 'percentage' => 35, 'targeting' => json_encode(['actors' => [7]], JSON_THROW_ON_ERROR), 'expires_at' => '2999-01-01 00:00:00']);
    $stored = DB::table('feature_flags')->where('key', 'release')->first();
    expect($stored->owner)->toBe('release-team')->and((bool) $stored->enabled)->toBeTrue()
        ->and($stored->percentage)->toBe(35)->and(json_decode($stored->targeting, true, flags: JSON_THROW_ON_ERROR))->toBe(['actors' => [7]]);
    Artisan::call('migrate:rollback', ['--force' => true]);
    expect(Schema::hasTable('feature_flags'))->toBeFalse();
});
