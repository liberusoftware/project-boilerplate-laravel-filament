<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Liberu\Foundation\Audit\AuditServiceProvider;
use Liberu\Foundation\Audit\Contracts\AuditRecorder;
use Liberu\Foundation\Audit\Models\ActivityLogEntry;
use Liberu\Foundation\Audit\Support\AuditContext;
use Liberu\Foundation\Audit\Support\DatabaseAuditRecorder;
use Liberu\Foundation\Audit\Support\ModelAuditListener;

it('boots audit service provider and records hash-chained entries in activity_log', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('audit.retention_days', 365);

    $provider = $this->app->register(AuditServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();

    $recorder = app(AuditRecorder::class);
    expect($recorder)->toBeInstanceOf(DatabaseAuditRecorder::class);

    $recorder->record(
        'invoice_created',
        'Invoice',
        101,
        [],
        ['status' => 'draft'],
        new AuditContext(5, 'User', 'tenant-a', 'req-1', 'corr-1', 'creation'),
    );

    $recorder->record(
        'invoice_updated',
        'Invoice',
        101,
        ['status' => 'draft'],
        ['status' => 'posted'],
        new AuditContext(5, 'User', 'tenant-a', 'req-2', 'corr-2'),
    );

    $rows = DB::table('activity_log')->where('log_name', 'audit')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->previous_hash)->toBeNull()
        ->and($rows[0]->record_hash)->not->toBeEmpty()
        ->and($rows[1]->previous_hash)->toBe($rows[0]->record_hash)
        ->and($rows[1]->tenant_ref)->toBe('tenant-a')
        ->and($rows[1]->correlation_id)->toBe('corr-2');
});

it('handles model audit listener across all branches and ActivityLogEntry casts and relations', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    $listener = new ModelAuditListener();

    // Non-model and non-genealogy model are ignored
    $listener->handle('eloquent.created: stdClass', [new stdClass()]);
    $otherModel = new class() extends Model
    {
        protected $table = 'users';
    };
    $listener->handle('eloquent.created: User', [$otherModel]);

    $genealogy = new class() extends Model
    {
        protected $table = 'genealogy_people';

        protected $guarded = [];
    };
    $genealogy->setRawAttributes(['id' => 12, 'team_id' => 3, 'name' => 'Before'], true);
    $genealogy->setAttribute('name', 'After');

    // Unhandled operation ignored
    $listener->handle('eloquent.retrieved: Person', [$genealogy]);
    expect(DB::table('activity_log')->where('log_name', 'audit')->count())->toBe(0);

    // Unbound AuditRecorder ignored
    $originalBinding = app(AuditRecorder::class);
    app()->offsetUnset(AuditRecorder::class);
    $listener->handle('eloquent.created: Person', [$genealogy]);
    expect(DB::table('activity_log')->where('log_name', 'audit')->count())->toBe(0);

    // Rebind and test created, updated, deleted with authenticated actor and request headers
    app()->instance(AuditRecorder::class, $originalBinding);

    $actor = new class() extends Authenticatable
    {
        protected $guarded = [];
    };
    $actor->forceFill(['id' => 99]);
    auth()->setUser($actor);

    $request = Request::create('/audit-test', 'POST');
    $request->headers->set('X-Request-ID', 'req-123');
    app()->instance('request', $request);

    $listener->handle('eloquent.created: Person', [$genealogy]);
    $listener->handle('eloquent.updated: Person', [$genealogy]);
    $listener->handle('eloquent.deleted: Person', [$genealogy]);

    $events = DB::table('activity_log')->where('log_name', 'audit')->orderBy('id')->pluck('event')->all();
    expect($events)->toBe(['genealogy_created', 'genealogy_updated', 'genealogy_deleted']);

    $entry = ActivityLogEntry::query()->where('log_name', 'audit')->firstOrFail();
    expect($entry->attribute_changes)->toBeArray()
        ->and($entry->properties)->toBeArray()
        ->and($entry->retain_until)->toBeInstanceOf(Carbon::class)
        ->and($entry->subject())->toBeInstanceOf(MorphTo::class)
        ->and($entry->causer())->toBeInstanceOf(MorphTo::class);
});
