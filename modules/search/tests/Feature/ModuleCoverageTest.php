<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Liberu\Foundation\Search\Concerns\Searchable;
use Liberu\Foundation\Search\Contracts\SearchIndexer;
use Liberu\Foundation\Search\Registry\IndexableRegistry;
use Liberu\Foundation\Search\Registry\SearcherRegistry;
use Liberu\Foundation\Search\SearchServiceProvider;
use Liberu\Foundation\Search\Services\LocalSearchIndexer;
use Liberu\Foundation\Search\Services\SearchService;

it('covers IndexableRegistry, SearcherRegistry, LocalSearchIndexer, and SearchServiceProvider', function (): void {
    $provider = $this->app->register(SearchServiceProvider::class, force: true);
    $provider->boot();

    $indexables = new IndexableRegistry();
    $indexables->register('users', User::class);
    expect($indexables->all())->toBe(['users' => User::class]);
    expect(fn () => $indexables->register('users', User::class))
        ->toThrow(InvalidArgumentException::class, 'Search type [users] already exists.');

    $searchers = new SearcherRegistry();
    $stubSearcher = static fn (array $filters): LengthAwarePaginator => new LengthAwarePaginator([], 0, 10);
    $searchers->register('docs', $stubSearcher);
    expect($searchers->types())->toBe(['docs'])
        ->and($searchers->all())->toHaveKey('docs');
    expect(fn () => $searchers->register('docs', $stubSearcher))
        ->toThrow(InvalidArgumentException::class, 'Search type [docs] already exists.');

    $localIndexer = new LocalSearchIndexer();
    $localIndexer->index('docs', (object) ['id' => 1]);
    $localIndexer->remove('docs', 1);
    $localIndexer->flush('docs');
});

it('covers ReindexCommand failure and success paths', function (): void {
    $registry = new IndexableRegistry();
    $this->app->instance(IndexableRegistry::class, $registry);

    $indexed = [];
    $flushed = [];
    $this->app->instance(SearchIndexer::class, new class($indexed, $flushed) implements SearchIndexer
    {
        public function __construct(public array &$indexed, public array &$flushed) {}

        public function index(string $type, object $model): void
        {
            $this->indexed[] = [$type, $model->getKey()];
        }

        public function remove(string $type, int|string $id): void {}

        public function flush(string $type): void
        {
            $this->flushed[] = $type;
        }
    });

    // Empty registry -> failure
    $this->artisan('search:reindex')
        ->expectsOutput('No matching indexable search type.')
        ->assertExitCode(1);

    // Register User model and seed a record
    $user = User::factory()->create();
    $registry->register('users', User::class);

    $this->artisan('search:reindex', ['type' => 'nonexistent'])
        ->expectsOutput('No matching indexable search type.')
        ->assertExitCode(1);

    $this->artisan('search:reindex', ['type' => 'users'])
        ->expectsOutput('Reindexed users.')
        ->assertExitCode(0);

    expect($flushed)->toBe(['users'])
        ->and($indexed)->toContain(['users', $user->getKey()]);
});

it('covers SearchService role, date range, verification, ordering, coercion, and searchAll branches', function (): void {
    $modelClass = new class() extends Model
    {
        use Searchable;

        protected $table = 'users';

        protected $guarded = [];

        /** @var list<string> */
        protected array $searchable = ['name', 'email'];

        public function scopeRole(Builder $query, string $role): Builder
        {
            return $query->where('name', 'like', '%'.$role.'%');
        }
    };

    User::factory()->create([
        'name' => 'Admin Alice',
        'email' => 'alice@example.com',
        'email_verified_at' => now(),
        'created_at' => now()->subDays(2),
    ]);
    User::factory()->create([
        'name' => 'Guest Bob',
        'email' => 'bob@example.com',
        'email_verified_at' => null,
        'created_at' => now()->subDays(10),
    ]);

    config()->set('search.models.user', $modelClass::class);

    $searchers = new SearcherRegistry();
    $service = new SearchService($searchers);
    $searchers->register('users', fn (array $filters): LengthAwarePaginator => $service->searchUsers($filters));
    $searchers->register('other', fn (array $filters): LengthAwarePaginator => new LengthAwarePaginator([], 0, 5));

    $verifiedResult = $service->searchUsers([
        'query' => 'Alice',
        'role' => 'Admin',
        'verified' => true,
        'created_from' => now()->subDays(5)->toDateTimeString(),
        'created_to' => now()->addDay()->toDateTimeString(),
        'order_by' => 'name',
        'order_direction' => 'asc',
        'per_page' => 10,
    ]);
    expect($verifiedResult->total())->toBe(1);

    $unverifiedResult = $service->searchUsers([
        'verified' => false,
        'order_direction' => 'desc',
        'per_page' => 5,
    ]);
    expect($unverifiedResult->total())->toBe(1);

    $all = $service->searchAll(['query' => 'Alice']);
    expect(array_keys($all))->toBe(['users', 'other']);

    $filtered = $service->searchAll(['types' => ['users'], 'query' => 'Alice']);
    expect(array_keys($filtered))->toBe(['users']);
});
