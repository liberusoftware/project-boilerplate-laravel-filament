<?php

namespace Liberu\Foundation\Identity\Socialstream\Tests;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User;
use JoelButcher\Socialstream\Socialstream;
use Laravel\Jetstream\Jetstream;
use Liberu\Foundation\Identity\Socialstream\Contracts\ConnectedAccountOwner;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\PackageTestbench\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('auth.providers.users.model', SocialstreamActor::class);
        $app['config']->set('activitylog.enabled', false);
        $app['config']->set('socialstream.features', []);
        $app['config']->set('jetstream.features', []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Socialstream::useUserModel(SocialstreamActor::class);
        Jetstream::useUserModel(SocialstreamActor::class);
    }
}

class SocialstreamActor extends User implements ConnectedAccountOwner
{
    protected $table = 'users';

    protected $guarded = [];

    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'user_id');
    }

    public function ownsConnectedAccount($account): bool
    {
        return (int) $account->user_id === (int) $this->getKey();
    }

    public function setProfilePhotoFromUrl(string $url): void
    {
        $this->forceFill(['profile_photo_path' => $url])->save();
    }
}
