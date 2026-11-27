<?php

declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Foundation\Search\Registry\SearcherRegistry;
use Liberu\Foundation\SearchApi\SearchApiServiceProvider;

it('boots search-api service provider and requires sanctum authentication', function (): void {
    $provider = $this->app->register(SearchApiServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(SearchApiServiceProvider::class);

    $this->getJson('/api/search/users')->assertUnauthorized();
    $this->getJson('/api/search/all')->assertUnauthorized();
});

it('searches and projects users without exposing email and validates filters', function (): void {
    $actor = User::factory()->create([
        'name' => 'Alice Accountant',
        'email' => 'alice@example.test',
    ]);
    User::factory()->unverified()->create([
        'name' => 'Bob Bookkeeper',
        'email' => 'bob@example.test',
    ]);

    Sanctum::actingAs($actor);

    $response = $this->getJson('/api/search/users?'.http_build_query([
        'query' => 'Alice',
        'verified' => 1,
        'created_from' => now()->subDay()->toDateString(),
        'created_to' => now()->addDay()->toDateString(),
        'order_by' => 'name',
        'order_direction' => 'asc',
        'per_page' => 10,
    ]));

    $response->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $actor->id)
        ->assertJsonPath('data.0.name', 'Alice Accountant')
        ->assertJsonStructure([
            'data' => [
                ['id', 'name', 'profile_photo_url'],
            ],
        ]);

    expect($response->json('data.0'))->not->toHaveKey('email')
        ->and($response->json('data.0'))->not->toHaveKey('email_verified_at');

    // Validation errors on users endpoint
    $this->getJson('/api/search/users?'.http_build_query([
        'order_by' => 'password',
        'order_direction' => 'sideways',
        'per_page' => 500,
        'created_from' => 'not-a-date',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['order_by', 'order_direction', 'per_page', 'created_from']);
});

it('searches all registered types, projects users when present, and skips projection when absent', function (): void {
    $actor = User::factory()->create([
        'name' => 'Clara Controller',
        'email' => 'clara@example.test',
    ]);

    app(SearcherRegistry::class)->register(
        'notes',
        fn (array $filters): LengthAwarePaginator => new LengthAwarePaginator(
            items: [['id' => 1, 'title' => 'Audit note']],
            total: 1,
            perPage: (int) ($filters['per_page'] ?? 5),
        ),
    );

    Sanctum::actingAs($actor);

    // Search all types (includes users -> hits user projection branch)
    $allResponse = $this->getJson('/api/search/all?'.http_build_query([
        'query' => 'Clara',
        'per_page' => 5,
    ]));

    $allResponse->assertOk()
        ->assertJsonPath('users.data.0.id', $actor->id)
        ->assertJsonPath('users.data.0.name', 'Clara Controller')
        ->assertJsonPath('notes.data.0.title', 'Audit note');

    expect($allResponse->json('users.data.0'))->not->toHaveKey('email');

    // Search only non-user type (skips users projection branch)
    $notesOnly = $this->getJson('/api/search/all?'.http_build_query([
        'types' => ['notes'],
        'per_page' => 5,
    ]));

    $notesOnly->assertOk()
        ->assertJsonPath('notes.data.0.title', 'Audit note');

    expect($notesOnly->json())->not->toHaveKey('users');

    // Validation errors on all endpoint
    $this->getJson('/api/search/all?'.http_build_query([
        'types' => ['nonexistent_type'],
        'per_page' => 0,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['types.0', 'per_page']);
});
