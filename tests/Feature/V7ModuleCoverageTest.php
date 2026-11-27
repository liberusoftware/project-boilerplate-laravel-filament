<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteAction;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use JoelButcher\Socialstream\Contracts\AuthenticatesOAuthCallback;
use JoelButcher\Socialstream\Events\NewOAuthRegistration;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Two\User as SocialiteUser;
use Liberu\Foundation\Audit\Models\ActivityLogEntry;
use Liberu\Foundation\Currency\Services\CurrencyConverter;
use Liberu\Foundation\Identity\Jobs\SyncNewUserToCrm;
use Liberu\Foundation\Identity\Socialstream\Actions\VerifyTelegramAuthPayload;
use Liberu\Foundation\Identity\Socialstream\Http\Controllers\TelegramAuthController;
use Liberu\Foundation\Identity\Socialstream\Http\Responses\SpaAwareOAuthResponse;
use Liberu\Foundation\Identity\Socialstream\Listeners\SyncNewOAuthUserToCrm;
use Liberu\Foundation\Integrations\Contracts\IntegrationAdapter;
use Liberu\Foundation\Integrations\Contracts\OAuthIntegrationAdapter;
use Liberu\Foundation\Integrations\Support\IntegrationRegistry;
use Liberu\Foundation\Organizations\Actions\Jetstream\DeleteTeam;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\RolesPermissions\Support\RolePermissionCatalog;
use Liberu\Foundation\RolesPermissionsFilament\Forms\RolePermissionBuilder;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages\CreateRole;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages\EditRole;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages\ListRoles;
use Livewire\Component;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('covers ActivityLogEntry casts and morph relations', function (): void {
    $user = User::factory()->create();
    $entry = ActivityLogEntry::create([
        'log_name' => 'audit',
        'description' => 'created',
        'subject_type' => User::class,
        'subject_id' => $user->id,
        'causer_type' => User::class,
        'causer_id' => $user->id,
        'attribute_changes' => ['name' => 'Updated'],
        'properties' => ['ip' => '127.0.0.1'],
        'retain_until' => now()->addDays(30),
    ]);

    $fresh = ActivityLogEntry::findOrFail($entry->id);
    expect($fresh->attribute_changes)->toBe(['name' => 'Updated'])
        ->and($fresh->properties)->toBe(['ip' => '127.0.0.1'])
        ->and($fresh->retain_until)->not->toBeNull()
        ->and($fresh->subject()->getModel())->toBeInstanceOf(User::class)
        ->and($fresh->causer()->getModel())->toBeInstanceOf(User::class);
});

it('covers OpenExchangeRateApiProvider fallback URL when primary fails', function (): void {
    Cache::flush();
    config([
        'currency.exchange_rates_enabled' => true,
        'currency.exchange_rate_api_url' => 'https://primary-rates.example.test/{base}',
        'currency.exchange_rate_api_fallback_url' => 'https://fallback-rates.example.test/{base}',
        'currency.stale_after_seconds' => 600,
    ]);

    Http::fake([
        'https://primary-rates.example.test/USD' => Http::response(['result' => 'error'], 503),
        'https://fallback-rates.example.test/USD' => Http::response([
            'result' => 'success',
            'rates' => ['EUR' => 0.85],
            'time_last_update_unix' => 1700000000,
        ]),
    ]);

    expect(app(CurrencyConverter::class)->convertAmount(100, 'USD', 'EUR'))->toBe(85.0);
});

it('covers SyncNewUserToCrm job across unconfigured, missing user, success, backoff, and failed paths', function (): void {
    Http::fake([
        'https://crm.example.test/api/v1/crm/lead-capture' => Http::response(['ok' => true], 201),
    ]);

    $job = new SyncNewUserToCrm(999999, 'email', ['locale' => 'en'], ['ip' => '127.0.0.1']);
    expect($job->backoff())->toBe([10, 30, 60, 300, 900]);

    // Unconfigured -> no-op
    config(['services.crm.base_url' => null, 'services.crm.token' => null]);
    $job->handle();
    Http::assertNothingSent();

    // Configured, user missing -> no-op
    config([
        'services.crm.base_url' => 'https://crm.example.test',
        'services.crm.token' => 'secret-token',
        'services.crm.external_key_prefix' => 'user-',
    ]);
    $job->handle();
    Http::assertNothingSent();

    // Configured, user exists -> posts lead
    $user = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.test']);
    $realJob = new SyncNewUserToCrm($user->id, 'google', ['locale' => 'en'], ['ip' => '127.0.0.1']);
    $realJob->handle();
    Http::assertSentCount(1);

    // Failed handler logs error
    Log::shouldReceive('error')->once();
    $realJob->failed(new RuntimeException('CRM unreachable'));
});

it('covers VerifyTelegramAuthPayload across valid and invalid payloads', function (): void {
    $botToken = '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11';
    $verifier = new VerifyTelegramAuthPayload($botToken);

    // Non-scalar value rejected
    expect($verifier->verify(Request::create('/cb', 'GET', ['id' => ['nested'], 'auth_date' => (string) now()->timestamp, 'hash' => 'abc'])))->toBeNull();

    // Missing required fields or empty token rejected
    expect($verifier->verify(Request::create('/cb', 'GET', ['id' => '42'])))->toBeNull();
    expect((new VerifyTelegramAuthPayload(''))->verify(Request::create('/cb', 'GET', ['id' => '42', 'auth_date' => (string) now()->timestamp, 'hash' => 'abc'])))->toBeNull();

    // Helper to sign Telegram payload
    $sign = function (array $fields) use ($botToken): array {
        ksort($fields);
        $checkString = collect($fields)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $secretKey = hash('sha256', $botToken, true);
        $fields['hash'] = hash_hmac('sha256', $checkString, $secretKey);

        return $fields;
    };

    // Wrong hash rejected
    expect($verifier->verify(Request::create('/cb', 'GET', ['id' => '42', 'first_name' => 'Ada', 'auth_date' => (string) now()->timestamp, 'hash' => 'forged'])))->toBeNull();

    // Stale auth_date rejected
    $stale = $sign(['id' => '42', 'first_name' => 'Ada', 'auth_date' => (string) (now()->timestamp - 90000)]);
    expect($verifier->verify(Request::create('/cb', 'GET', $stale)))->toBeNull();

    // Valid fresh payload accepted
    $valid = $sign(['id' => '42', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'username' => 'ada', 'auth_date' => (string) now()->timestamp]);
    $verified = $verifier->verify(Request::create('/cb', 'GET', $valid));
    expect($verified)->toBeArray()->and($verified['id'])->toBe('42')->and($verified['username'])->toBe('ada');
});

it('covers TelegramAuthController invalid and valid callback flows', function (): void {
    $botToken = '123456:telegram-test-token';
    config(['services.telegram.bot_token' => $botToken, 'app.url' => 'https://example.test']);

    $controller = new TelegramAuthController();
    $authenticator = new class() implements AuthenticatesOAuthCallback
    {
        public ?string $provider = null;

        public ?SocialiteUserContract $user = null;

        public function authenticate(string $provider, SocialiteUserContract $providerAccount): RedirectResponse
        {
            $this->provider = $provider;
            $this->user = $providerAccount;

            return new RedirectResponse('/dashboard');
        }
    };

    // Invalid payload aborts 403
    expect(fn () => $controller->callback(Request::create('/oauth/telegram/callback', 'GET', ['hash' => 'bad']), $authenticator))
        ->toThrow(HttpException::class);

    // Valid payload authenticates and synthesizes email/name
    $fields = ['id' => '777', 'username' => 'tguser', 'auth_date' => (string) now()->timestamp];
    ksort($fields);
    $checkString = collect($fields)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
    $fields['hash'] = hash_hmac('sha256', $checkString, hash('sha256', $botToken, true));
    $fields['spa'] = '1';

    $response = $controller->callback(Request::create('/oauth/telegram/callback', 'GET', $fields), $authenticator);
    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($authenticator->provider)->toBe('telegram')
        ->and($authenticator->user?->getName())->toBe('Telegram User 777')
        ->and($authenticator->user?->getEmail())->toBe('telegram-777@users.example.test')
        ->and(session('socialstream.spa_redirect'))->toBeTrue();
});

it('covers SpaAwareOAuthResponse for both session and SPA flows', function (): void {
    $default = new class() implements Responsable
    {
        public function toResponse($request): Response
        {
            return new Response('default-session-response', 200);
        }
    };

    $responder = new SpaAwareOAuthResponse($default);

    // Non-SPA delegates to default response
    session()->forget('socialstream.spa_redirect');
    $req = Request::create('/oauth/google/callback', 'GET');
    expect($responder->toResponse($req)->getContent())->toBe('default-session-response');

    // SPA flow mints Sanctum token and redirects with fragment
    $user = User::factory()->create(['name' => 'SPA User', 'email' => 'spa@example.test']);
    $req->setUserResolver(fn () => $user);
    session(['socialstream.spa_redirect' => true]);
    config(['services.frontend.oauth_callback_url' => '/account/oauth-callback']);

    $redirect = $responder->toResponse($req);
    expect($redirect)->toBeInstanceOf(RedirectResponse::class)
        ->and($redirect->getTargetUrl())->toContain('/account/oauth-callback#token=')
        ->and($redirect->getTargetUrl())->toContain('email=spa%40example.test');
});

it('covers SyncNewOAuthUserToCrm listener', function (): void {
    Queue::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $socialiteUser = (new SocialiteUser())->map([
        'id' => 'oauth-123',
        'nickname' => 'jdoe',
        'name' => $user->name,
        'email' => $user->email,
    ]);

    $event = new NewOAuthRegistration($user, 'google', $socialiteUser);
    (new SyncNewOAuthUserToCrm())->handle($event);

    Queue::assertPushed(SyncNewUserToCrm::class);
});

it('covers IntegrationRegistry oauth lookup for both OAuth and non-OAuth adapters', function (): void {
    $registry = new IntegrationRegistry();

    $plain = new class() implements IntegrationAdapter
    {
        public function name(): string
        {
            return 'plain';
        }

        public function capabilities(): array
        {
            return ['sync'];
        }

        public function test(array $credentials): bool
        {
            return true;
        }
    };

    $oauth = new class() implements OAuthIntegrationAdapter
    {
        public function name(): string
        {
            return 'oauth-provider';
        }

        public function capabilities(): array
        {
            return ['oauth'];
        }

        public function test(array $credentials): bool
        {
            return true;
        }

        public function authorizationUrl(string $state): string
        {
            return 'https://oauth.example.test/authorize?state='.$state;
        }

        public function exchangeAuthorizationCode(string $code): array
        {
            return ['access_token' => $code];
        }

        public function refreshAccessToken(string $refreshToken): array
        {
            return ['access_token' => $refreshToken];
        }
    };

    $registry->register($plain);
    $registry->register($oauth);

    expect($registry->oauth('oauth-provider'))->toBe($oauth);
    expect(fn () => $registry->oauth('plain'))->toThrow(InvalidArgumentException::class, 'does not support OAuth');
});

it('covers Jetstream DeleteTeam action', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id, 'personal_team' => false]);

    (new DeleteTeam())->delete($team);

    expect(Team::withArchived()->find($team->id))->toBeNull();
});

it('covers RolesPermissionsFilament RolePermissionBuilder, RoleResource, and its Pages', function (): void {
    if (! Illuminate\Support\Facades\Schema::hasTable('audit_logs')) {
        Illuminate\Support\Facades\Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('action')->nullable();
            $table->string('event')->nullable();
            $table->text('description')->nullable();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('changes')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }
    $superAdminRole = Role::firstOrCreate([
        'name' => Liberu\Foundation\RolesPermissions\Enums\Role::SuperAdmin->value,
        'guard_name' => 'web',
        'team_id' => null,
    ]);
    $user = User::factory()->withPersonalTeam()->create();
    setPermissionsTeamId($user->currentTeam->id);
    $user->assignRole($superAdminRole);

    // Unauthenticated / non-super-admin denials
    auth()->logout();
    $globalRole = Role::create(['name' => 'custom_global', 'guard_name' => 'web', 'team_id' => null]);
    expect(RoleResource::canAccess())->toBeFalse()
        ->and(RoleResource::canViewAny())->toBeFalse()
        ->and(RoleResource::getViewAnyAuthorizationResponse()->allowed())->toBeFalse()
        ->and(RoleResource::canCreate())->toBeFalse()
        ->and(RoleResource::getCreateAuthorizationResponse()->allowed())->toBeFalse()
        ->and(RoleResource::canView($globalRole))->toBeFalse()
        ->and(RoleResource::getViewAuthorizationResponse($globalRole)->allowed())->toBeFalse()
        ->and(RoleResource::canEdit($globalRole))->toBeFalse()
        ->and(RoleResource::getEditAuthorizationResponse($globalRole)->allowed())->toBeFalse()
        ->and(RoleResource::canDelete($globalRole))->toBeFalse()
        ->and(RoleResource::getDeleteAuthorizationResponse($globalRole)->allowed())->toBeFalse()
        ->and(RoleResource::canDeleteAny())->toBeFalse()
        ->and(RoleResource::getDeleteAnyAuthorizationResponse()->allowed())->toBeFalse();

    // Super admin allowances
    $this->actingAs($user);
    expect(RoleResource::canAccess())->toBeTrue()
        ->and(RoleResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
        ->and(RoleResource::getCreateAuthorizationResponse()->allowed())->toBeTrue()
        ->and(RoleResource::getViewAuthorizationResponse($globalRole)->allowed())->toBeTrue()
        ->and(RoleResource::getEditAuthorizationResponse($globalRole)->allowed())->toBeTrue()
        ->and(RoleResource::getDeleteAuthorizationResponse($globalRole)->allowed())->toBeTrue()
        ->and(RoleResource::getEloquentQuery()->count())->toBeGreaterThanOrEqual(2)
        ->and(RoleResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    // RolePermissionBuilder components + closures
    $schema = Schema::make(new class() extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = ['permissions' => ['delete_user', 'view_user']];
    })->statePath('data');
    $components = RoleResource::form($schema)->getComponents();
    expect($components)->toHaveCount(2);

    // Exercise RolePermissionBuilder callbacks directly via reflection on schema components
    $section1Children = $components[0]->getChildSchema()->getComponents();
    $nameField = $section1Children[0];
    $presetField = $section1Children[1];
    $presets = array_keys(RolePermissionCatalog::presets());
    $presetField->state(null)->callAfterStateUpdated();
    if ($presets !== []) {
        $presetField->state($presets[0])->callAfterStateUpdated();
    }
    $refReadOnly = new ReflectionProperty($nameField, 'isReadOnly');
    $roCb = $refReadOnly->getValue($nameField);
    if ($roCb instanceof Closure) {
        expect($roCb($superAdminRole))->toBeTrue()
            ->and($roCb($globalRole))->toBeFalse();
    }
    $section2Children = $components[1]->getChildSchema()->getComponents();
    $placeholder = $section2Children[0];
    expect((string) $placeholder->getContent())->toContain('permissions selected');

    // Table definition + scope column + delete action closure
    $livewireTable = new class() extends Component implements HasActions, HasSchemas, HasTable
    {
        use InteractsWithActions;
        use InteractsWithSchemas;
        use InteractsWithTable;
    };
    $table = RoleResource::table(Table::make($livewireTable));
    $scopeCol = $table->getColumn('scope');
    expect($scopeCol)->not->toBeNull();
    $refState = new ReflectionProperty($scopeCol, 'getStateUsing');
    expect(($refState->getValue($scopeCol))())->toBe('Global');

    $deletableRole = Role::create(['name' => 'temp_delete_me', 'guard_name' => 'web', 'team_id' => null]);
    $deleteAction = collect($table->getRecordActions())->first(fn ($a) => $a instanceof DeleteAction);
    $refUsing = new ReflectionProperty($deleteAction, 'using');
    expect(($refUsing->getValue($deleteAction))($deletableRole))->toBeTrue();

    // ListRoles page methods
    $listPage = new ListRoles();
    expect($listPage->getSubheading())->toContain('Global definitions');
    $refHeader = new ReflectionMethod($listPage, 'getHeaderActions');
    expect($refHeader->invoke($listPage))->toHaveCount(1);

    // CreateRole page: success & validation error paths
    $createPage = new CreateRole();
    $refCreate = new ReflectionMethod($createPage, 'handleRecordCreation');
    $created = $refCreate->invoke($createPage, ['name' => 'created_global_role', 'permissions' => []]);
    expect($created)->toBeInstanceOf(Role::class);
    expect(fn () => $refCreate->invoke($createPage, ['name' => 'created_global_role', 'permissions' => []]))
        ->toThrow(ValidationException::class);

    // EditRole page: mutateFormDataBeforeFill, handleRecordUpdate success & validation error
    $editPage = new EditRole();
    $editPage->record = $created;
    $refMutate = new ReflectionMethod($editPage, 'mutateFormDataBeforeFill');
    $filled = $refMutate->invoke($editPage, ['name' => $created->name]);
    expect($filled)->toHaveKey('permissions');

    $refUpdate = new ReflectionMethod($editPage, 'handleRecordUpdate');
    $updated = $refUpdate->invoke($editPage, $created, ['name' => 'renamed_global_role', 'permissions' => []]);
    expect($updated->name)->toBe('renamed_global_role');
    expect(fn () => $refUpdate->invoke($editPage, $created, ['name' => '', 'permissions' => []]))
        ->toThrow(ValidationException::class);
});
