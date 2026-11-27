<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Jetstream;
use Liberu\Foundation\Organizations\Contracts\OrganizationActor;
use Liberu\Foundation\Organizations\Exceptions\TeamImportException;
use Liberu\Foundation\Organizations\Exceptions\TeamNotEmptyException;
use Liberu\Foundation\Organizations\Exceptions\TeamRestoreException;
use Liberu\Foundation\Organizations\Models\Membership;
use Liberu\Foundation\Organizations\Models\Organization;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;
use Liberu\Foundation\Organizations\Models\TeamInvitation;
use Liberu\Foundation\Organizations\Models\TeamOnboarding;
use Liberu\Foundation\Organizations\Models\TeamSubscription;
use Liberu\Foundation\Organizations\OrganizationsServiceProvider;
use Liberu\Foundation\Organizations\Policies\TeamPolicy;
use Liberu\Foundation\Organizations\Support\TenantContext;
use Liberu\Foundation\Organizations\Tests\Fixtures\OrgTestTenantItem;
use Liberu\Foundation\Organizations\Tests\Fixtures\OrgTestUser;

function makeOrgUser(array $overrides = []): OrgTestUser
{
    static $seq = 0;
    $seq++;

    return OrgTestUser::forceCreate(array_merge([
        'name' => "Org User {$seq}",
        'email' => "org-user-{$seq}-".uniqid().'@example.test',
        'password' => bcrypt('Password123!@#'),
    ], $overrides));
}

function makeOrgTeam(OrgTestUser $owner, array $overrides = []): Team
{
    return Team::forceCreate(array_merge([
        'user_id' => $owner->id,
        'name' => 'Team '.uniqid(),
        'personal_team' => false,
    ], $overrides));
}

it('registers and boots OrganizationsServiceProvider', function () {
    $provider = new OrganizationsServiceProvider(app());
    $provider->register();
    $provider->boot();

    expect(config('organizations-teams.assignable_roles'))->toContain('member', 'admin', 'manager', 'sales_rep', 'free')
        ->and(Jetstream::teamModel())->toBe(Team::class)
        ->and(Jetstream::membershipModel())->toBe(Membership::class)
        ->and(Jetstream::teamInvitationModel())->toBe(TeamInvitation::class)
        ->and(Gate::getPolicyFor(Team::class))->toBeInstanceOf(TeamPolicy::class);
});

it('covers Membership, Organization, TeamOnboarding, TeamBackup, TeamInvitation models and relationships', function () {
    $membership = new Membership();
    expect($membership->incrementing)->toBeTrue();

    $owner = makeOrgUser();
    $org = Organization::create([
        'name' => 'Acme Holdings',
        'slug' => 'acme-holdings-'.uniqid(),
        'owner_id' => $owner->id,
        'status' => 'active',
        'archived_at' => now(),
    ]);

    expect($org->archived_at)->not->toBeNull()
        ->and($org->teams())->toBeInstanceOf(HasMany::class);

    $team = makeOrgTeam($owner, ['organization_id' => $org->id]);
    expect($org->teams()->pluck('id')->all())->toContain($team->id);

    $onboarding = TeamOnboarding::create([
        'team_id' => $team->id,
        'completed_at' => now(),
    ]);
    expect($onboarding->completed_at)->not->toBeNull();

    $backup = TeamBackup::create([
        'team_id' => $team->id,
        'disk' => 'local',
        'path' => 'backups/test.zip',
        'size_bytes' => '1024',
        'status' => 'completed',
        'created_by' => $owner->id,
    ]);
    expect($backup->size_bytes)->toBe(1024)
        ->and($backup->team())->toBeInstanceOf(BelongsTo::class)
        ->and($backup->team->id)->toBe($team->id)
        ->and($backup->creator())->toBeInstanceOf(BelongsTo::class)
        ->and($backup->creator->id)->toBe($owner->id);

    $invitation = TeamInvitation::forceCreate([
        'team_id' => $team->id,
        'email' => 'invitee@example.test',
        'role' => 'sales_rep',
        'token_hash' => hash('sha256', 'secret-token'),
        'invited_by' => $owner->id,
        'expires_at' => now()->addDays(3),
        'accepted_at' => null,
        'revoked_at' => null,
    ]);
    expect($invitation->team())->toBeInstanceOf(BelongsTo::class)
        ->and($invitation->team->id)->toBe($team->id)
        ->and($invitation->toArray())->not->toHaveKey('token_hash')
        ->and($invitation->expires_at)->not->toBeNull();
});

it('covers Team factory, global archived scope, archive/restore lifecycle, relations, subscription, and user limits', function () {
    $owner = makeOrgUser();
    $personal = makeOrgTeam($owner, ['personal_team' => true, 'name' => 'Personal']);
    $shared = Team::factory()->create(['user_id' => $owner->id, 'personal_team' => false]);

    expect($shared->getActivitylogOptions()->logAttributes)->toContain('name', 'personal_team', 'user_id')
        ->and($shared->teamInvitations())->toBeInstanceOf(HasMany::class)
        ->and($shared->subscription())->toBeInstanceOf(HasOne::class)
        ->and($shared->isArchived())->toBeFalse();

    // Personal team cannot be archived
    expect(fn () => $personal->archive())->toThrow(DomainException::class, 'Personal teams cannot be archived.');

    // Stranded member falls back to personal team when shared team is archived
    $owner->forceFill(['current_team_id' => $shared->id])->save();
    $shared->archive();

    expect($shared->isArchived())->toBeTrue()
        ->and($owner->fresh()->current_team_id)->toBe($personal->id)
        ->and(Team::query()->whereKey($shared->id)->exists())->toBeFalse()
        ->and(Team::query()->withArchived()->whereKey($shared->id)->exists())->toBeTrue();

    // Idempotent archive
    $archivedAt = $shared->archived_at;
    $shared->archive();
    expect($shared->fresh()->archived_at->equalTo($archivedAt))->toBeTrue();

    // Restore
    $shared->restore();
    expect($shared->fresh()->isArchived())->toBeFalse()
        ->and(Team::query()->whereKey($shared->id)->exists())->toBeTrue();

    // hasActiveSubscription branches
    config(['saas.enabled' => false]);
    expect($shared->hasActiveSubscription())->toBeTrue();

    config(['saas.enabled' => true]);
    expect($shared->fresh()->hasActiveSubscription())->toBeFalse();

    $sub = TeamSubscription::create([
        'team_id' => $shared->id,
        'stripe_id' => 'sub_'.uniqid(),
        'stripe_status' => 'active',
        'quantity' => 1,
        'trial_ends_at' => now()->addDays(7),
        'ends_at' => now()->addMonth(),
    ]);
    expect($shared->fresh()->hasActiveSubscription())->toBeTrue();

    // Trialing only (canceled status but future trial)
    $sub->update(['stripe_status' => 'canceled', 'ends_at' => now()->subDay(), 'trial_ends_at' => now()->addDays(5)]);
    expect($shared->fresh()->hasActiveSubscription())->toBeTrue();

    // Expired & not on trial
    $sub->update(['trial_ends_at' => now()->subDay()]);
    expect($shared->fresh()->hasActiveSubscription())->toBeFalse();

    // canAddMoreUsers branches
    config(['services.stripe.max_team_users' => 2]);
    expect($shared->canAddMoreUsers())->toBeTrue();

    $member = makeOrgUser();
    $shared->users()->attach($member, ['role' => 'member']);
    expect($shared->fresh()->canAddMoreUsers())->toBeFalse();
});

it('covers TeamSubscription helper methods and soft deletes', function () {
    $owner = makeOrgUser();
    $team = makeOrgTeam($owner);

    $sub = TeamSubscription::create([
        'team_id' => $team->id,
        'stripe_id' => 'sub_'.uniqid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
        'plan_key' => 'pro',
        'quantity' => 3,
        'trial_ends_at' => now()->addDays(10),
        'current_period_ends_at' => now()->addMonth(),
        'ends_at' => now()->addDays(15),
        'cancelled_at' => null,
    ]);

    expect($sub->team())->toBeInstanceOf(BelongsTo::class)
        ->and($sub->team->id)->toBe($team->id)
        ->and($sub->onTrial())->toBeTrue()
        ->and($sub->hasExpired())->toBeFalse()
        ->and($sub->isActive())->toBeTrue()
        ->and($sub->cancelAt())->toBeTrue();

    $sub->update([
        'stripe_status' => 'trialing',
        'trial_ends_at' => now()->subDay(),
        'ends_at' => now()->subHour(),
    ]);

    expect($sub->onTrial())->toBeFalse()
        ->and($sub->hasExpired())->toBeTrue()
        ->and($sub->isActive())->toBeFalse()
        ->and($sub->cancelAt())->toBeFalse();

    $sub->delete();
    expect(TeamSubscription::find($sub->id))->toBeNull()
        ->and(TeamSubscription::withTrashed()->find($sub->id))->not->toBeNull();
});

it('covers TeamPolicy for owner, member, and outsider', function () {
    $owner = makeOrgUser();
    $member = makeOrgUser();
    $outsider = makeOrgUser();

    $team = makeOrgTeam($owner);
    $team->users()->attach($member, ['role' => 'member']);

    $invitation = TeamInvitation::forceCreate([
        'team_id' => $team->id,
        'email' => 'pending@example.test',
        'role' => 'sales_rep',
    ]);

    $policy = new TeamPolicy();

    expect($owner)->toBeInstanceOf(OrganizationActor::class)
        ->and($policy->viewAny($owner))->toBeTrue()
        ->and($policy->create($owner))->toBeTrue()
        ->and($policy->view($owner, $team))->toBeTrue()
        ->and($policy->view($member, $team))->toBeTrue()
        ->and($policy->view($outsider, $team))->toBeFalse()
        ->and($policy->update($owner, $team))->toBeTrue()
        ->and($policy->update($member, $team))->toBeFalse()
        ->and($policy->addTeamMember($owner, $team))->toBeTrue()
        ->and($policy->addTeamMember($member, $team))->toBeFalse()
        ->and($policy->updateTeamMember($owner, $team))->toBeTrue()
        ->and($policy->updateTeamMember($member, $team))->toBeFalse()
        ->and($policy->removeTeamMember($owner, $team))->toBeTrue()
        ->and($policy->removeTeamMember($member, $team))->toBeFalse()
        ->and($policy->delete($owner, $team))->toBeTrue()
        ->and($policy->delete($member, $team))->toBeFalse()
        ->and($policy->deleteTeamInvitation($owner, $invitation))->toBeTrue()
        ->and($policy->deleteTeamInvitation($member, $invitation))->toBeFalse();
});

it('covers IsTenantModel trait and module exceptions', function () {
    Schema::create('org_test_tenant_items', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('team_id')->nullable();
        $table->string('name');
        $table->timestamps();
    });

    try {
        $owner = makeOrgUser();
        $teamA = makeOrgTeam($owner);
        $teamB = makeOrgTeam($owner);

        TenantContext::set($teamA->id);
        $itemA = OrgTestTenantItem::create(['name' => 'Alpha']);
        expect($itemA->team_id)->toBe($teamA->id);

        TenantContext::set($teamB->id);
        OrgTestTenantItem::create(['name' => 'Beta']);

        expect(OrgTestTenantItem::query()->pluck('name')->all())->toBe(['Beta']);
        TenantContext::clear();
    } finally {
        Schema::dropIfExists('org_test_tenant_items');
        TenantContext::clear();
    }

    $importEx = new TeamImportException('bad import');
    $restoreEx = new TeamRestoreException('bad restore');
    $notEmptyEx = new TeamNotEmptyException('not empty');

    expect($importEx)->toBeInstanceOf(RuntimeException::class)
        ->and($importEx->getMessage())->toBe('bad import')
        ->and($restoreEx)->toBeInstanceOf(RuntimeException::class)
        ->and($notEmptyEx)->toBeInstanceOf(TeamRestoreException::class);
});
