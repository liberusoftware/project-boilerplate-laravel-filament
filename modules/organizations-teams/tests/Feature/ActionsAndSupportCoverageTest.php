<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Events\AddingTeam;
use Laravel\Jetstream\Events\AddingTeamMember;
use Laravel\Jetstream\Events\InvitingTeamMember;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;
use Liberu\Foundation\Organizations\Actions\AcceptInvitation;
use Liberu\Foundation\Organizations\Actions\InviteMember;
use Liberu\Foundation\Organizations\Actions\Jetstream\AddTeamMember;
use Liberu\Foundation\Organizations\Actions\Jetstream\CreateTeam;
use Liberu\Foundation\Organizations\Actions\Jetstream\DeleteTeam;
use Liberu\Foundation\Organizations\Actions\Jetstream\InviteTeamMember;
use Liberu\Foundation\Organizations\Actions\Jetstream\RemoveTeamMember;
use Liberu\Foundation\Organizations\Actions\Jetstream\UpdateTeamName;
use Liberu\Foundation\Organizations\Actions\TransferOwnership;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamInvitation;
use Liberu\Foundation\Organizations\Support\AccessContext;
use Liberu\Foundation\Organizations\Support\MembershipRolePolicy;
use Liberu\Foundation\Organizations\Support\PortalCustomer;
use Liberu\Foundation\Organizations\Tests\Fixtures\OrgTestTerritory;
use Liberu\Foundation\Organizations\Tests\Fixtures\OrgTestUser;
use Liberu\Foundation\RolesPermissions\Enums\Role;
use Spatie\Permission\Models\Role as SpatieRole;

function createActionUser(array $overrides = []): OrgTestUser
{
    static $seq = 0;
    $seq++;

    return OrgTestUser::forceCreate(array_merge([
        'name' => "Action User {$seq}",
        'email' => "action-user-{$seq}-".uniqid().'@example.test',
        'password' => bcrypt('Password123!@#'),
    ], $overrides));
}

function createActionTeam(OrgTestUser $owner, array $overrides = []): Team
{
    return Team::forceCreate(array_merge([
        'user_id' => $owner->id,
        'name' => 'Action Team '.uniqid(),
        'personal_team' => false,
    ], $overrides));
}

beforeEach(function () {
    AccessContext::clear();
});

afterEach(function () {
    AccessContext::clear();
    Auth::logout();
});

it('covers MembershipRolePolicy normalize and allows', function () {
    $policy = new MembershipRolePolicy();

    expect($policy->normalize('  Manager  '))->toBe('manager')
        ->and($policy->normalize('   '))->toBeNull()
        ->and($policy->allows('manager'))->toBeTrue()
        ->and($policy->allows('SALES_REP'))->toBeTrue()
        ->and($policy->allows('super_admin'))->toBeFalse()
        ->and($policy->allows('customer'))->toBeFalse()
        ->and($policy->allows('nonexistent'))->toBeFalse()
        ->and($policy->allows(''))->toBeFalse();
});

it('covers AccessContext restrictToOwnerId, restrictedTerritoryIds, and shouldMaskFields', function () {
    // Guest context
    expect(AccessContext::restrictToOwnerId())->toBeNull()
        ->and(AccessContext::restrictedTerritoryIds())->toBeNull()
        ->and(AccessContext::shouldMaskFields())->toBeFalse();

    // Explicit override
    AccessContext::restrictTo(42);
    expect(AccessContext::restrictToOwnerId())->toBe(42);
    AccessContext::clear();
    expect(AccessContext::restrictToOwnerId())->toBeNull();

    $user = createActionUser();
    $team = createActionTeam($user);
    setPermissionsTeamId($team->id);
    Auth::login($user);

    // Roleless user sees all and is unmasked
    expect(AccessContext::restrictToOwnerId())->toBeNull()
        ->and(AccessContext::restrictedTerritoryIds())->toBeNull()
        ->and(AccessContext::shouldMaskFields())->toBeFalse();

    // SalesRep is restricted to own records and assigned territories, but not masked
    $salesRole = SpatieRole::firstOrCreate(['name' => Role::SalesRep->value, 'guard_name' => 'web', 'team_id' => null]);
    $user->assignRole($salesRole);

    $territory = OrgTestTerritory::create(['team_id' => $team->id, 'name' => 'EMEA']);
    $territory->users()->attach($user->id);

    expect(AccessContext::restrictToOwnerId())->toBe($user->id)
        ->and(AccessContext::restrictedTerritoryIds())->toBe([$territory->id])
        ->and(AccessContext::shouldMaskFields())->toBeFalse();

    // Free role masks fields
    $freeRole = SpatieRole::firstOrCreate(['name' => Role::Free->value, 'guard_name' => 'web', 'team_id' => null]);
    $user->assignRole($freeRole);
    expect(AccessContext::shouldMaskFields())->toBeTrue();
});

it('covers PortalCustomer forEmail and existsForEmail', function () {
    expect(PortalCustomer::forEmail(null))->toBeNull()
        ->and(PortalCustomer::forEmail(''))->toBeNull()
        ->and(PortalCustomer::forEmail('missing@example.test'))->toBeNull()
        ->and(PortalCustomer::existsForEmail('missing@example.test'))->toBeFalse();

    $user = createActionUser(['email' => 'portal-cust@example.test']);
    expect(PortalCustomer::forEmail($user->email))->toBeNull()
        ->and(PortalCustomer::existsForEmail($user->email))->toBeFalse();

    $customerRole = SpatieRole::firstOrCreate(['name' => Role::Customer->value, 'guard_name' => 'web', 'team_id' => null]);
    $tables = config('permission.table_names');
    DB::table($tables['model_has_roles'])->insert([
        'role_id' => $customerRole->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'team_id' => null,
    ]);

    expect(PortalCustomer::forEmail($user->email)?->id)->toBe($user->id)
        ->and(PortalCustomer::existsForEmail($user->email))->toBeTrue();
});

it('covers InviteMember and AcceptInvitation actions including all failure branches', function () {
    $owner = createActionUser();
    $invitee = createActionUser(['email' => 'newmember@example.test']);
    $team = createActionTeam($owner);

    $inviter = new InviteMember();
    $accepter = new AcceptInvitation();

    // Disallowed role
    expect(fn () => $inviter->handle($team->id, $invitee->email, 'super_admin', $owner->id))
        ->toThrow(InvalidArgumentException::class);

    // Valid invite & accept
    $token = $inviter->handle($team->id, '  NEWMEMBER@example.test ', 'Manager', $owner->id, new DateTimeImmutable('+2 days'));
    expect(strlen($token))->toBe(64);

    $accepter->handle($token, $invitee, 'newmember@example.test');

    expect(DB::table('team_user')->where('team_id', $team->id)->where('user_id', $invitee->id)->where('role', 'manager')->exists())->toBeTrue();

    // Already accepted token fails
    expect(fn () => $accepter->handle($token, $invitee, $invitee->email))
        ->toThrow(RuntimeException::class, 'Invitation is invalid or expired.');

    // Invalid token fails
    expect(fn () => $accepter->handle('wrong-token', $invitee, $invitee->email))
        ->toThrow(RuntimeException::class);

    // Expired invitation fails
    $expiredToken = $inviter->handle($team->id, 'expired@example.test', 'member', $owner->id, new DateTimeImmutable('-1 hour'));
    $expiredUser = createActionUser(['email' => 'expired@example.test']);
    expect(fn () => $accepter->handle($expiredToken, $expiredUser, $expiredUser->email))
        ->toThrow(RuntimeException::class);

    // Mismatched email fails
    $validToken2 = $inviter->handle($team->id, 'other@example.test', 'member', $owner->id);
    expect(fn () => $accepter->handle($validToken2, $invitee, 'other@example.test'))
        ->toThrow(RuntimeException::class);
});

it('covers TransferOwnership action for valid and invalid cases', function () {
    $owner = createActionUser();
    $successor = createActionUser();
    $outsider = createActionUser();
    $team = createActionTeam($owner);
    $team->users()->attach($successor, ['role' => 'member', 'status' => 'active']);

    $action = new TransferOwnership();

    // Not recently authenticated
    expect(fn () => $action->handle($team, $owner->id, $successor->id, false))
        ->toThrow(RuntimeException::class, 'Recent owner authentication is required.');

    // Wrong current owner
    expect(fn () => $action->handle($team, $outsider->id, $successor->id, true))
        ->toThrow(RuntimeException::class, 'Recent owner authentication is required.');

    // Successor not active member
    expect(fn () => $action->handle($team, $owner->id, $outsider->id, true))
        ->toThrow(RuntimeException::class, 'The successor must be an active member.');

    // Valid transfer
    $action->handle($team, $owner->id, $successor->id, true);
    expect($team->fresh()->user_id)->toBe($successor->id);
});

it('covers Jetstream CreateTeam, UpdateTeamName, and DeleteTeam actions', function () {
    Event::fake([AddingTeam::class]);

    $owner = createActionUser();
    $outsider = createActionUser();

    $createAction = new CreateTeam();
    $team = $createAction->create($owner, ['name' => 'Alpha Workspace']);

    Event::assertDispatched(AddingTeam::class);
    expect($team->name)->toBe('Alpha Workspace')
        ->and($owner->fresh()->current_team_id)->toBe($team->id);

    // Validation failure on CreateTeam
    expect(fn () => $createAction->create($owner, ['name' => '']))
        ->toThrow(ValidationException::class);

    // UpdateTeamName
    $updateAction = new UpdateTeamName();
    $updateAction->update($owner, $team, ['name' => 'Renamed Workspace']);
    expect($team->fresh()->name)->toBe('Renamed Workspace');

    // Unauthorized update
    expect(fn () => $updateAction->update($outsider, $team, ['name' => 'Hack']))
        ->toThrow(AuthorizationException::class);

    // DeleteTeam
    (new DeleteTeam())->delete($team);
    expect(Team::withArchived()->find($team->id))->toBeNull();
});

it('covers Jetstream AddTeamMember, InviteTeamMember, and RemoveTeamMember actions', function () {
    Mail::fake();
    Event::fake([AddingTeamMember::class, TeamMemberAdded::class, InvitingTeamMember::class, TeamMemberRemoved::class]);

    $owner = createActionUser();
    $member = createActionUser();
    $invitedUser = createActionUser();
    $outsider = createActionUser();
    $team = createActionTeam($owner);

    // InviteTeamMember
    $inviter = new InviteTeamMember();
    $inviter->invite($owner, $team, $invitedUser->email, 'editor');
    Event::assertDispatched(InvitingTeamMember::class);
    Mail::assertSent(TeamInvitationMail::class);

    // Duplicate invite fails validation
    expect(fn () => $inviter->invite($owner, $team, $invitedUser->email, 'editor'))
        ->toThrow(ValidationException::class);

    // AddTeamMember consumes pending invitation and attaches user
    $adder = new AddTeamMember();
    $adder->add($owner, $team, $invitedUser->email, 'admin');
    Event::assertDispatched(AddingTeamMember::class);
    Event::assertDispatched(TeamMemberAdded::class);
    expect($team->fresh()->hasUserWithEmail($invitedUser->email))->toBeTrue()
        ->and(TeamInvitation::where('email', $invitedUser->email)->exists())->toBeFalse();

    // Adding already-existing member fails validation
    expect(fn () => $adder->add($owner, $team->fresh(), $invitedUser->email, 'admin'))
        ->toThrow(ValidationException::class);

    // Inviting already-existing member fails validation
    expect(fn () => $inviter->invite($owner, $team->fresh(), $invitedUser->email, 'editor'))
        ->toThrow(ValidationException::class);

    // Add a second member directly (no pending invitation)
    $adder->add($owner, $team, $member->email, 'editor');
    expect($team->fresh()->hasUserWithEmail($member->email))->toBeTrue();

    // RemoveTeamMember
    $remover = new RemoveTeamMember();

    // Unauthorized outsider cannot remove another member
    expect(fn () => $remover->remove($outsider, $team, $member))
        ->toThrow(AuthorizationException::class);

    // Cannot remove team owner
    expect(fn () => $remover->remove($owner, $team, $owner))
        ->toThrow(ValidationException::class);

    // Owner removes member
    $remover->remove($owner, $team, $member);
    Event::assertDispatched(TeamMemberRemoved::class);
    expect($team->fresh()->hasUserWithEmail($member->email))->toBeFalse();

    // Member can remove themselves
    $remover->remove($invitedUser, $team, $invitedUser);
    expect($team->fresh()->hasUserWithEmail($invitedUser->email))->toBeFalse();
});
