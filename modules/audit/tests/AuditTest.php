<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Liberu\Foundation\Audit\Contracts\AuditRecorder;
use Liberu\Foundation\Audit\Models\ActivityLogEntry;
use Liberu\Foundation\Audit\Support\AuditContext;
use Liberu\Foundation\Audit\Support\DatabaseAuditRecorder;
use Liberu\Foundation\Audit\Support\ModelAuditListener;

uses(RefreshDatabase::class);

it('records tamper-evident audit entries in the activity_log table', function () {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    $recorder = app(AuditRecorder::class);
    expect($recorder)->toBeInstanceOf(DatabaseAuditRecorder::class);

    $recorder->record(
        'genealogy_created',
        'Person',
        1,
        [],
        ['name' => 'Ada'],
        new AuditContext(10, 'User', 'tenant-1', 'req-1', 'corr-1', 'initial create'),
    );

    $recorder->record(
        'genealogy_updated',
        'Person',
        1,
        ['name' => 'Ada'],
        ['name' => 'Ada Lovelace'],
        new AuditContext(10, 'User', 'tenant-1', 'req-2', 'corr-2'),
    );

    $rows = DB::table('activity_log')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->previous_hash)->toBeNull()
        ->and($rows[0]->record_hash)->not->toBeEmpty()
        ->and($rows[1]->previous_hash)->toBe($rows[0]->record_hash)
        ->and($rows[1]->tenant_ref)->toBe('tenant-1')
        ->and($rows[1]->correlation_id)->toBe('corr-2');
});

it('handles genealogy model lifecycle events and ignores unrelated events', function () {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    $listener = new ModelAuditListener();

    // Non-model payload and non-genealogy model are ignored.
    $listener->handle('eloquent.created: stdClass', [new stdClass()]);
    $nonGenealogy = new class() extends Model
    {
        protected $table = 'users';
    };
    $listener->handle('eloquent.created: User', [$nonGenealogy]);

    $genealogyModel = new class() extends Model
    {
        protected $table = 'genealogy_people';

        protected $guarded = [];
    };
    $genealogyModel->setRawAttributes(['id' => 5, 'team_id' => 9, 'name' => 'Original'], true);
    $genealogyModel->setAttribute('name', 'Updated');

    // Non-audited event operation is ignored.
    $listener->handle('eloquent.retrieved: Person', [$genealogyModel]);
    expect(DB::table('activity_log')->count())->toBe(0);

    // Missing AuditRecorder binding returns early.
    app()->offsetUnset(AuditRecorder::class);
    $listener->handle('eloquent.created: Person', [$genealogyModel]);
    expect(DB::table('activity_log')->count())->toBe(0);

    // Rebind AuditRecorder and exercise created, updated, deleted with actor and request headers.
    app()->bind(AuditRecorder::class, DatabaseAuditRecorder::class);

    $actor = new class() extends Authenticatable
    {
        protected $guarded = [];
    };
    $actor->forceFill(['id' => 77]);
    auth()->setUser($actor);

    $request = Request::create('/test', 'POST');
    $request->headers->set('X-Request-ID', 'req-xyz');
    app()->instance('request', $request);

    $listener->handle('eloquent.created: Person', [$genealogyModel]);
    $listener->handle('eloquent.updated: Person', [$genealogyModel]);
    $listener->handle('eloquent.deleted: Person', [$genealogyModel]);

    $events = DB::table('activity_log')->orderBy('id')->pluck('event')->all();

    expect($events)->toBe(['genealogy_created', 'genealogy_updated', 'genealogy_deleted'])
        ->and(DB::table('activity_log')->first()->correlation_id)->toBe('req-xyz')
        ->and(DB::table('activity_log')->first()->causer_id)->toBe(77);
});

it('casts attributes and exposes subject and causer morph relations on ActivityLogEntry', function () {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    $retainUntil = now()->addYear()->startOfSecond();

    $entry = ActivityLogEntry::create([
        'log_name' => 'audit',
        'description' => 'Created person',
        'subject_type' => ActivityLogEntry::class,
        'subject_id' => 42,
        'event' => 'created',
        'causer_type' => ActivityLogEntry::class,
        'causer_id' => 7,
        'attribute_changes' => ['before' => [], 'after' => ['name' => 'Ada']],
        'properties' => ['reason' => 'import'],
        'retain_until' => $retainUntil,
    ]);

    $fresh = ActivityLogEntry::findOrFail($entry->id);

    expect($fresh->attribute_changes)->toBe(['before' => [], 'after' => ['name' => 'Ada']])
        ->and($fresh->properties)->toBe(['reason' => 'import'])
        ->and($fresh->retain_until)->toBeInstanceOf(Carbon::class)
        ->and($fresh->retain_until->equalTo($retainUntil))->toBeTrue()
        ->and($fresh->subject())->toBeInstanceOf(MorphTo::class)
        ->and($fresh->causer())->toBeInstanceOf(MorphTo::class);
});
