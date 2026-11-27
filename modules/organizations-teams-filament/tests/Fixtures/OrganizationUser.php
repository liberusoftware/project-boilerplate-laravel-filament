<?php

declare(strict_types=1);

namespace App\Models {
    use Liberu\PackageTestbench\TestUser;

    if (! class_exists(User::class)) {
        class User extends TestUser {}
    }
}

namespace Liberu\Foundation\OrganizationsFilament\Tests\Fixtures {
    use App\Models\User;
    use Filament\Panel;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use Illuminate\Database\Eloquent\Relations\BelongsToMany;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use Illuminate\Notifications\Notifiable;
    use Illuminate\Support\Collection;
    use Laravel\Jetstream\HasTeams;
    use Liberu\Foundation\Organizations\Contracts\OrganizationActor;
    use Liberu\Foundation\Organizations\Models\Team;
    use Spatie\Permission\Traits\HasRoles;

    final class OrganizationUser extends User implements OrganizationActor
    {
        use HasRoles;
        use HasTeams;
        use Notifiable;

        protected $table = 'users';

        protected string $guard_name = 'web';

        public function getForeignKey(): string
        {
            return 'user_id';
        }

        public function getMorphClass(): string
        {
            return User::class;
        }

        public function isSuperAdmin(): bool
        {
            return $this->hasRole('super_admin');
        }

        public function ownedTeams(): HasMany
        {
            return $this->hasMany(Team::class, 'user_id');
        }

        public function currentTeam(): BelongsTo
        {
            return $this->belongsTo(Team::class, 'current_team_id');
        }

        public function teams(): BelongsToMany
        {
            return $this->belongsToMany(Team::class, 'team_user', 'user_id', 'team_id')
                ->withPivot('role')
                ->withTimestamps()
                ->as('membership');
        }

        public function getTenants(Panel $panel): Collection
        {
            return $this->allTeams();
        }

        public function canAccessTenant(Model $tenant): bool
        {
            return $this->belongsToTeam($tenant);
        }

        public function getDefaultTenant(Panel $panel): ?Model
        {
            return $this->currentTeam ?? $this->allTeams()->first();
        }
    }

    final class TestAuditLog extends Model
    {
        protected $table = 'audit_logs';

        protected $guarded = [];

        public function user(): BelongsTo
        {
            return $this->belongsTo(OrganizationUser::class, 'user_id');
        }
    }
}
