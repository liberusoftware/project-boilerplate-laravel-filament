<?php

namespace Liberu\Foundation\Organizations\Models;

use App\Models\Contact;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;
use Liberu\Foundation\Organizations\Database\Factories\TeamFactory;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** @property string|null $stripe_id */
class Team extends JetstreamTeam
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    use LogsActivity;

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }

    /**
     * Hide archived teams from every default query. Removable so the admin
     * panel and restore paths can still see them (->withArchived()). This one
     * gate cascades: allTeams()/the team switcher, the User::currentTeam
     * relation, and route-model binding all stop resolving archived teams.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('archived', function (Builder $builder): void {
            $builder->whereNull($builder->getModel()->qualifyColumn('archived_at'));
        });
    }

    public function scopeWithArchived(Builder $query): void
    {
        $query->withoutGlobalScope('archived');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'personal_team', 'user_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'personal_team',
        'organization_id',
        'status',
        'setup_completed_at',
        'zernio_profile_id',
        'user_id',
        'portal_brand_name',
        'portal_logo_url',
        'portal_logo_path',
    ];

    /** @var list<string> */
    protected $hidden = ['settings'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'personal_team' => 'boolean',
        'archived_at' => 'datetime',
        'setup_completed_at' => 'datetime',
        'marketing_preferences' => 'array',
        'settings' => 'encrypted:array',
    ];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * Get the team's invitations.
     */
    #[\Override]
    public function teamInvitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get the team's subscription.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(TeamSubscription::class)->latest();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Freeze the team: hide it everywhere, keep the data. Any member whose
     * active team was this one falls back to their personal team so no session
     * is stranded. Personal teams are a user's home and cannot be archived.
     */
    public function archive(): void
    {
        if ($this->personal_team) {
            throw new DomainException('Personal teams cannot be archived.');
        }

        if ($this->isArchived()) {
            return;
        }

        $this->archived_at = now();
        $this->save();

        foreach (User::query()->where('current_team_id', $this->id)->get() as $user) {
            $user->forceFill(['current_team_id' => $user->personalTeam()->id])->save();
        }
    }

    public function restore(): void
    {
        $this->archived_at = null;
        $this->save();
    }

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function hasActiveSubscription(): bool
    {
        if (! config('saas.enabled', false)) {
            return true;
        }

        return $this->subscription && ($this->subscription->isActive() || $this->subscription->onTrial());
    }

    public function canAddMoreUsers(): bool
    {
        $maxUsers = config('services.stripe.max_team_users', 5);

        return $this->allUsers()->count() < $maxUsers;
    }
}
