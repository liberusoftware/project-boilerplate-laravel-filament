<?php

declare(strict_types=1);

namespace App\Models {
    use Liberu\PackageTestbench\TestUser;

    if (! class_exists(User::class)) {
        class User extends TestUser {}
    }
}

namespace Liberu\Foundation\Organizations\Tests\Fixtures {
    use App\Models\User;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use Illuminate\Database\Eloquent\Relations\BelongsToMany;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use Laravel\Jetstream\HasTeams;
    use Liberu\Foundation\Organizations\Contracts\OrganizationActor;
    use Liberu\Foundation\Organizations\Contracts\TeamAuditLogger;
    use Liberu\Foundation\Organizations\Contracts\TeamJobNotifier;
    use Liberu\Foundation\Organizations\Models\Team;
    use Liberu\Foundation\Organizations\Traits\IsTenantModel;
    use Spatie\Permission\Traits\HasRoles;

    class OrgTestTerritory extends Model
    {
        protected $table = 'territories';

        protected $guarded = [];

        public function users(): BelongsToMany
        {
            return $this->belongsToMany(OrgTestUser::class, 'territory_user', 'territory_id', 'user_id');
        }
    }

    class OrgTestUser extends User implements OrganizationActor
    {
        use HasRoles;
        use HasTeams;

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

        public function territories(): BelongsToMany
        {
            return $this->belongsToMany(OrgTestTerritory::class, 'territory_user', 'user_id', 'territory_id');
        }
    }

    class OrgTestTenantItem extends Model
    {
        use IsTenantModel;

        protected $table = 'org_test_tenant_items';

        protected $guarded = [];
    }

    class OrgTestMissingTableItem extends Model
    {
        use IsTenantModel;

        protected $table = 'org_test_missing_table_items';

        protected $guarded = [];
    }

    class OrgTestSimpleItem extends Model
    {
        use IsTenantModel;

        protected $table = 'org_test_simple_items';

        protected $guarded = [];
    }

    class OrgTestPipeline extends Model
    {
        protected $table = 'org_test_pipelines';

        protected $guarded = [];
    }

    class OrgTestStage extends Model
    {
        protected $table = 'org_test_stages';

        protected $guarded = [];
    }

    class OrgTestMenu extends Model
    {
        protected $table = 'org_test_menus';

        protected $guarded = [];
    }

    class RecordingTeamAuditLogger implements TeamAuditLogger
    {
        /** @var list<array{action: string, description: string, subject: mixed}> */
        public array $recorded = [];

        public function record(string $action, string $description, mixed $subject = null): void
        {
            $this->recorded[] = [
                'action' => $action,
                'description' => $description,
                'subject' => $subject,
            ];
        }
    }

    class RecordingTeamJobNotifier implements TeamJobNotifier
    {
        /** @var list<array{user: Model, title: string, body: string, success: bool}> */
        public array $notifications = [];

        public function notify(Model $user, string $title, string $body, bool $success): void
        {
            $this->notifications[] = [
                'user' => $user,
                'title' => $title,
                'body' => $body,
                'success' => $success,
            ];
        }
    }
}
