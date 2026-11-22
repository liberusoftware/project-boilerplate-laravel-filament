<?php

declare(strict_types=1);

namespace App\Services\Sso {
    function gzinflate(string $data, int $maxLength = 0): string|false
    {
        if ($data === 'OVERFLOW_INFLATE') {
            return str_repeat('A', 1_048_577);
        }

        return \gzinflate($data, $maxLength);
    }
}

namespace OneLogin\Saml2 {
    if (! class_exists(Constants::class)) {
        class Constants
        {
            public const BINDING_HTTP_POST = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST';

            public const BINDING_HTTP_REDIRECT = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect';

            public const NAMEID_EMAIL_ADDRESS = 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress';
        }
    }

    if (! class_exists(Auth::class)) {
        class Auth
        {
            public static array $nextErrors = [];

            public static bool $nextAuthenticated = true;

            public static ?string $nextNameId = 'alice@corp.example';

            public static array $nextAttributes = [];

            public static ?string $nextSessionIndex = '_sess_stub_1';

            public function __construct(private array $settings = []) {}

            public function login(?string $returnTo = null, array $parameters = [], bool $forceAuthn = false, bool $isPassive = false, bool $stay = false): string
            {
                $ssoUrl = $this->settings['idp']['singleSignOnService']['url'] ?? 'https://idp.example.com/sso';

                return $ssoUrl.'?SAMLRequest=stub_request';
            }

            public function getLastRequestID(): string
            {
                return '_req_stub_id';
            }

            public function processResponse(?string $requestId = null): void {}

            public function getErrors(): array
            {
                return self::$nextErrors;
            }

            public function isAuthenticated(): bool
            {
                return self::$nextAuthenticated;
            }

            public function getNameId(): ?string
            {
                return self::$nextNameId;
            }

            public function getAttributes(): array
            {
                return self::$nextAttributes;
            }

            public function getSessionIndex(): ?string
            {
                return self::$nextSessionIndex;
            }

            public function logout(?string $returnTo = null, array $parameters = [], ?string $nameId = null, ?string $sessionIndex = null, bool $stay = false): string
            {
                $sloUrl = $this->settings['idp']['singleLogoutService']['url'] ?? 'https://idp.example.com/slo';

                return $sloUrl.'?SAMLRequest=stub_logout';
            }

            public function processSLO(bool $keepLocalSession = false): void {}
        }
    }
}

namespace RobRichards\XMLSecLibs {
    if (! class_exists(XMLSecurityDSig::class)) {
        class XMLSecurityDSig
        {
            public const XMLDSIGNS = 'http://www.w3.org/2000/09/xmldsig#';

            public const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

            public const C14N_COMMENTS = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315#WithComments';

            public const EXC_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';

            public const EXC_C14N_COMMENTS = 'http://www.w3.org/2001/10/xml-exc-c14n#WithComments';
        }
    }
}

namespace Firebase\JWT {
    if (! class_exists(JWK::class)) {
        class JWK
        {
            public static function parseKeySet(array $jwks): array
            {
                if (empty($jwks['keys'])) {
                    throw new \InvalidArgumentException('Empty JWKS');
                }

                return $jwks['keys'];
            }
        }
    }

    if (! class_exists(JWT::class)) {
        class JWT
        {
            public static function decode(string $jwt, mixed $keyOrKeyArray): object
            {
                if (! str_starts_with($jwt, 'valid-jwt:')) {
                    throw new \RuntimeException('Invalid JWT');
                }

                $decoded = json_decode((string) base64_decode(substr($jwt, 10), true), false);
                if (! is_object($decoded)) {
                    throw new \RuntimeException('Invalid JWT payload');
                }

                return $decoded;
            }
        }
    }
}

namespace {
    use App\Models\ConnectedAccount;
    use App\Models\MarketingPlatformSettings;
    use App\Models\SocialMediaPost;
    use App\Models\User;
    use App\Services\AuditLogService;
    use Filament\Facades\Filament;
    use Filament\Schemas\Schema as FilamentSchema;
    use Filament\Tables\Contracts\HasTable;
    use Filament\Tables\Table;
    use Firebase\JWT\JWT;
    use GuzzleHttp\Client as GuzzleClient;
    use GuzzleHttp\Handler\MockHandler;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Psr7\Response as GuzzleResponse;
    use Illuminate\Auth\Events\Logout;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Http\Client\Factory as HttpFactory;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\View;
    use Illuminate\Validation\ValidationException;
    use Laravel\Jetstream\Jetstream;
    use Laravel\Socialite\Contracts\User as SocialiteContractUser;
    use Laravel\Socialite\Facades\Socialite;
    use Laravel\Socialite\Two\AbstractProvider;
    use Laravel\Socialite\Two\User as SocialiteTwoUser;
    use Liberu\CRM\UnifiedConversations\Actions\SyncExternalConversation;
    use Liberu\Foundation\Integrations\Enums\SocialPlatform;
    use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource;
    use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages\CreateOAuthConfiguration;
    use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages\EditOAuthConfiguration;
    use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages\ListOAuthConfigurations;
    use Liberu\Foundation\Integrations\Filament\App\Resources\WebhookDeliveryResource;
    use Liberu\Foundation\Integrations\Filament\App\Resources\WebhookDeliveryResource\Pages\ListWebhookDeliveries;
    use Liberu\Foundation\Integrations\Http\Controllers\AccountingIntegrationController;
    use Liberu\Foundation\Integrations\Http\Controllers\Api\WebhookController;
    use Liberu\Foundation\Integrations\Http\Controllers\OAuthConfigurationController;
    use Liberu\Foundation\Integrations\Http\Controllers\OAuthController;
    use Liberu\Foundation\Integrations\Models\AccountingIntegration;
    use Liberu\Foundation\Integrations\Models\OAuthConfiguration;
    use Liberu\Foundation\Integrations\Models\Webhook;
    use Liberu\Foundation\Integrations\Models\WebhookDelivery;
    use Liberu\Foundation\Integrations\Services\AccountingService;
    use Liberu\Foundation\Integrations\Services\OAuth\OAuthManager;
    use Liberu\Foundation\Integrations\Services\Social\DirectSocialApiClient;
    use Liberu\Foundation\Integrations\Support\SsrfGuard;
    use Liberu\Foundation\Organizations\Models\Team;
    use Liberu\Foundation\RolesPermissions\Enums\Role;
    use Liberu\Foundation\RolesPermissions\Models\Permission;
    use Liberu\Foundation\RolesPermissions\Models\Role as SpatieRole;
    use Liberu\Foundation\RolesPermissions\Support\PermissionCatalog;
    use Liberu\Integrations\Google\Http\Controllers\GoogleCalendarController;
    use Liberu\Integrations\Meta\Filament\App\Resources\WhatsAppNumberResource;
    use Liberu\Integrations\Meta\Filament\App\Resources\WhatsAppNumberResource\Pages\CreateWhatsAppNumber;
    use Liberu\Integrations\Meta\Filament\App\Resources\WhatsAppNumberResource\Pages\EditWhatsAppNumber;
    use Liberu\Integrations\Meta\Filament\App\Resources\WhatsAppNumberResource\Pages\ListWhatsAppNumbers;
    use Liberu\Integrations\Meta\Models\WhatsAppNumber;
    use Liberu\Integrations\Sso\Actions\Sso\ProvisionSsoUser;
    use Liberu\Integrations\Sso\Exceptions\SsoException;
    use Liberu\Integrations\Sso\Filament\App\Resources\SamlConnectionResource;
    use Liberu\Integrations\Sso\Filament\App\Resources\SamlConnectionResource\Pages\CreateSamlConnection;
    use Liberu\Integrations\Sso\Filament\App\Resources\SamlConnectionResource\Pages\EditSamlConnection;
    use Liberu\Integrations\Sso\Filament\App\Resources\SamlConnectionResource\Pages\ListSamlConnections;
    use Liberu\Integrations\Sso\Filament\App\Resources\SsoConnectionResource;
    use Liberu\Integrations\Sso\Filament\App\Resources\SsoConnectionResource\Pages\CreateSsoConnection;
    use Liberu\Integrations\Sso\Filament\App\Resources\SsoConnectionResource\Pages\EditSsoConnection;
    use Liberu\Integrations\Sso\Filament\App\Resources\SsoConnectionResource\Pages\ListSsoConnections;
    use Liberu\Integrations\Sso\Http\Controllers\SamlLoginController;
    use Liberu\Integrations\Sso\Http\Controllers\SamlMetadataController;
    use Liberu\Integrations\Sso\Http\Controllers\SsoLoginController;
    use Liberu\Integrations\Sso\Http\Middleware\EnsureSsoWhenRequired;
    use Liberu\Integrations\Sso\Listeners\BuildSamlLogoutRedirect;
    use Liberu\Integrations\Sso\Listeners\BuildSsoLogoutRedirect;
    use Liberu\Integrations\Sso\Listeners\LogSsoLogout;
    use Liberu\Integrations\Sso\Models\SamlConnection;
    use Liberu\Integrations\Sso\Models\SsoConnection;
    use Liberu\Integrations\Sso\Services\Sso\OidcClient;
    use Liberu\Integrations\Sso\Services\Sso\SamlPayloadGuard;
    use Liberu\Integrations\Sso\Support\SsoEnforcement;
    use Liberu\Integrations\Sso\Support\SsoLogoutState;
    use Liberu\Integrations\Twilio\Filament\App\Resources\CallSettingResource;
    use Liberu\Integrations\Twilio\Filament\App\Resources\CallSettingResource\Pages\CreateCallSetting;
    use Liberu\Integrations\Twilio\Filament\App\Resources\CallSettingResource\Pages\EditCallSetting;
    use Liberu\Integrations\Twilio\Filament\App\Resources\CallSettingResource\Pages\ListCallSettings;
    use Liberu\Integrations\Twilio\Http\Controllers\TwilioController;
    use Liberu\Integrations\Twilio\Http\Middleware\VerifyTwilioRequest;
    use Liberu\Integrations\Twilio\Models\CallSetting;
    use Liberu\Integrations\Twilio\Services\OAuth\TwilioProvider;
    use Liberu\Integrations\Twilio\Services\TwilioService;
    use Liberu\Integrations\Zernio\Http\Controllers\ZernioWebhookController;
    use Liberu\Integrations\Zernio\Services\Zernio\ZernioAdvertisingService;
    use Liberu\Integrations\Zernio\Services\Zernio\ZernioClient;
    use Liberu\Integrations\Zernio\Services\Zernio\ZernioException;
    use Liberu\Integrations\Zernio\Services\Zernio\ZernioPublisher;
    use Liberu\Integrations\Zernio\Services\Zernio\ZernioTenantService;
    use Livewire\Livewire;
    use OneLogin\Saml2\Auth as SamlAuth;
    use RobRichards\XMLSecLibs\XMLSecurityDSig;
    use Symfony\Component\HttpKernel\Exception\HttpException;

    beforeEach(function (): void {
        Jetstream::useTeamModel(Team::class);
        PermissionCatalog::sync();

        if (! Schema::hasColumn('whats_app_numbers', 'team_id')) {
            Schema::table('whats_app_numbers', function (Blueprint $table): void {
                $table->unsignedBigInteger('team_id')->nullable();
            });
        }

        if (! Schema::hasColumn('call_settings', 'team_id')) {
            Schema::table('call_settings', function (Blueprint $table): void {
                $table->unsignedBigInteger('team_id')->nullable();
            });
        }

        Schema::dropIfExists('oauth_configurations');
        Schema::create('oauth_configurations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('service_name');
            $table->string('account_name')->nullable();
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->json('additional_settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('action');
                $table->text('description')->nullable();
                $table->string('ip_address')->nullable();
                $table->string('auditable_type')->nullable();
                $table->unsignedBigInteger('auditable_id')->nullable();
                $table->json('changes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('call_logs')) {
            Schema::create('call_logs', function (Blueprint $table): void {
                $table->id();
                $table->string('call_sid')->nullable();
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->string('direction')->nullable();
                $table->integer('duration')->nullable();
                $table->string('status')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('social_media_posts')) {
            Schema::create('social_media_posts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->text('content')->nullable();
                $table->json('platforms')->nullable();
                $table->string('image_path')->nullable();
                $table->string('video_url')->nullable();
                $table->string('link')->nullable();
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->string('status')->nullable();
                $table->timestamps();
            });
        }

        Schema::dropIfExists('connected_accounts');
        Schema::create('connected_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider');
            $table->string('provider_id')->nullable();
            $table->string('name')->nullable();
            $table->string('nickname')->nullable();
            $table->string('email')->nullable();
            $table->string('telephone')->nullable();
            $table->text('avatar_path')->nullable();
            $table->text('token')->nullable();
            $table->text('secret')->nullable();
            $table->text('token_secret')->nullable();
            $table->text('refresh_token')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });

        $viewDir = '/tmp/test-views-sub3/oauth/configurations';
        if (! is_dir($viewDir)) {
            mkdir($viewDir, 0777, true);
        }
        file_put_contents($viewDir.'/index.blade.php', '<div>OAuth Index {{ count($configurations ?? []) }}</div>');
        file_put_contents($viewDir.'/create.blade.php', '<div>OAuth Create facebook twitter instagram linkedin youtube</div>');
        View::addLocation('/tmp/test-views-sub3');

        $appPanel = Filament::getPanel('app');
        $resources = [
            CallSettingResource::class,
            OAuthConfigurationResource::class,
            SamlConnectionResource::class,
            SsoConnectionResource::class,
            WebhookDeliveryResource::class,
            WhatsAppNumberResource::class,
        ];
        $appPanel->resources($resources);

        Route::middleware('web')->group(function () use ($resources): void {
            foreach ($resources as $resClass) {
                $slug = $resClass::getSlug();
                Route::get("/app/{$slug}", fn () => 'index')->name("filament.app.resources.{$slug}.index");
                Route::get("/app/{$slug}/create", fn () => 'create')->name("filament.app.resources.{$slug}.create");
                Route::get("/app/{$slug}/{record}/edit", fn () => 'edit')->name("filament.app.resources.{$slug}.edit");
            }
            Route::get('/sso/{team}/redirect', [SsoLoginController::class, 'redirect'])->name('sso.redirect');
            Route::get('/sso/{team}/callback', [SsoLoginController::class, 'callback'])->name('sso.callback');
            Route::get('/saml/{team}/login', [SamlLoginController::class, 'redirect'])->name('saml.login');
            Route::post('/saml/{team}/acs', [SamlLoginController::class, 'acs'])->name('saml.acs');
            Route::get('/saml/{team}/sls', [SamlLoginController::class, 'sls'])->name('saml.sls');
            Route::get('/saml/{team}/metadata', SamlMetadataController::class)->name('saml.metadata');
            Route::get('/google/callback', [GoogleCalendarController::class, 'handleGoogleCallback'])->name('google.callback');
            Route::get('/tasks', fn () => 'tasks')->name('tasks.index');
            Route::get('/oauth/configurations', [OAuthConfigurationController::class, 'index'])->name('oauth.configurations.index');
            Route::get('/oauth/configurations/create', [OAuthConfigurationController::class, 'create'])->name('oauth.configurations.create');
            Route::post('/oauth/configurations', [OAuthConfigurationController::class, 'store'])->name('oauth.configurations.store');
            Route::get('/oauth/configurations/{service}/{configId}/authenticate', [OAuthConfigurationController::class, 'authenticate'])->name('oauth.authenticate');
            Route::get('/oauth/configurations/{service}/callback', [OAuthConfigurationController::class, 'callback'])->name('oauth.configurations.callback');
            Route::delete('/oauth/configurations/{configuration}', [OAuthConfigurationController::class, 'destroy'])->name('oauth.configurations.destroy');
            Route::get('/oauth/{provider}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
            Route::post('/twilio/twiml/outbound', [TwilioController::class, 'handleOutboundCall'])->name('twilio.twiml.outbound');
            Route::post('/twilio/recording/callback', [TwilioController::class, 'handleRecordingCallback'])->name('twilio.recording.callback');
        });
        Route::getRoutes()->refreshNameLookups();
    });

    function generateSamlKeypair(): array
    {
        $pkey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $csr = openssl_csr_new(['commonName' => 'idp.example.com'], $pkey, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $pkey, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($pkey, $privPem);

        return [(string) $privPem, (string) $certPem];
    }

    it('covers models, enums, exceptions, support, actions, listeners, and middleware', function (): void {
        // 1. Models
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $accounting = AccountingIntegration::create([
            'user_id' => $user->id,
            'platform' => 'quickbooks',
            'connection_details' => ['token' => 'abc'],
            'last_synced' => now(),
        ]);
        expect($accounting->user->is($user))->toBeTrue();

        $callSetting = (new CallSetting([
            'name' => 'record_calls',
            'value' => 'true',
            'description' => 'Record all calls',
        ]))->forceFill(['team_id' => $team->id]);
        $callSetting->save();
        expect($callSetting->name)->toBe('record_calls');

        $oauthConfig = (new OAuthConfiguration([
            'user_id' => $user->id,
            'service_name' => 'facebook',
            'client_id' => 'cid',
            'client_secret' => 'csec',
            'is_active' => true,
        ]))->forceFill(['team_id' => $team->id]);
        $oauthConfig->save();
        expect(OAuthConfiguration::getConfig('facebook')?->id)->toBe($oauthConfig->id);

        $samlConn = SamlConnection::create([
            'team_id' => $team->id,
            'idp_entity_id' => 'https://idp.example.com/entity',
            'idp_sso_url' => 'https://idp.example.com/sso',
            'idp_slo_url' => 'https://idp.example.com/slo',
            'idp_x509_cert' => 'cert',
            'enabled' => true,
            'allow_jit' => true,
            'require_sso' => true,
            'role_mappings' => ['eng' => Role::Manager->value, 'root' => Role::SuperAdmin->value],
        ]);
        expect($samlConn->roleForGroups(['eng']))->toBe(Role::Manager)
            ->and($samlConn->roleForGroups(['root']))->toBeNull()
            ->and($samlConn->roleForGroups(['other']))->toBeNull();

        $ssoConn = SsoConnection::create([
            'team_id' => $team->id,
            'provider' => 'oidc',
            'client_id' => 'cid',
            'client_secret' => 'csec',
            'issuer_url' => 'https://idp.example.com',
            'enabled' => true,
            'allow_jit' => true,
            'require_sso' => true,
            'role_mappings' => ['sales' => Role::SalesRep->value, 'root' => Role::SuperAdmin->value],
        ]);
        expect($ssoConn->roleForGroups(['sales']))->toBe(Role::SalesRep)
            ->and($ssoConn->roleForGroups(['root']))->toBeNull()
            ->and($ssoConn->roleForGroups(['other']))->toBeNull();

        $webhook = Webhook::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => 'Test Hook',
            'url' => 'https://example.com/hook',
            'events' => ['contact.created'],
            'secret' => 'sec',
        ]);
        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'team_id' => $team->id,
            'event' => 'contact.created',
            'success' => true,
            'status_code' => 200,
        ]);
        expect($webhook->deliveries)->toHaveCount(1)
            ->and($delivery->webhook->is($webhook))->toBeTrue();

        $whatsApp = (new WhatsAppNumber([
            'number' => '+15551234567',
            'display_name' => 'Support',
            'is_active' => true,
        ]))->forceFill(['team_id' => $team->id]);
        $whatsApp->save();
        expect($whatsApp->is_active)->toBeTrue();

        // 2. SocialPlatform Enum
        foreach (SocialPlatform::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
        }
        expect(SocialPlatform::options())->toHaveCount(16)
            ->and(SocialPlatform::values())->toHaveCount(16);

        // 3. SsoException
        $ssoEx = new SsoException('test sso error');
        expect($ssoEx->getMessage())->toBe('test sso error');

        // 4. Support: SsoEnforcement, SsoLogoutState, SsrfGuard
        $teamlessUser = User::factory()->create();
        expect(SsoEnforcement::enforcingTeamFor($teamlessUser))->toBeNull();
        expect(SsoEnforcement::enforcingTeamFor($user)?->is($team))->toBeTrue();
        expect(SsoEnforcement::loginRouteFor($team))->toBe('saml.login');

        $ssoConn->update(['require_sso' => false]);
        expect(SsoEnforcement::enforcingTeamFor($user)?->is($team))->toBeTrue();

        $samlConn->update(['enabled' => false, 'require_sso' => false]);
        expect(SsoEnforcement::enforcingTeamFor($user))->toBeNull();
        expect(SsoEnforcement::loginRouteFor($team))->toBe('sso.redirect');

        $logoutState = new SsoLogoutState();
        expect($logoutState->url())->toBeNull();
        $logoutState->set('https://idp.example.com/logout');
        expect($logoutState->url())->toBe('https://idp.example.com/logout');

        // SsrfGuard
        SsrfGuard::assertPublicHttps('https://8.8.8.8/idp');
        SsrfGuard::assertPublicHttps('https://ns1.liberuhosting.com/idp');
        SsrfGuard::assertPublicHttps('https://unresolvable.example.invalid/idp');

        foreach ([
            'http://example.com',
            'https://',
            'https://localhost/idp',
            'https://sub.localhost/idp',
            'https://127.0.0.1/idp',
            'https://localhost.localdomain/idp',
        ] as $badUrl) {
            $thrown = false;
            try {
                SsrfGuard::assertPublicHttps($badUrl);
            } catch (InvalidArgumentException) {
                $thrown = true;
            }
            expect($thrown)->toBeTrue("Expected SsrfGuard to reject {$badUrl}");
        }

        // 5. ProvisionSsoUser
        $provisioner = app(ProvisionSsoUser::class);
        $jitUser = $provisioner($team, 'jituser@example.com', null);
        expect($jitUser->name)->toBe('jituser')
            ->and($jitUser->fresh()->belongsToTeam($team))->toBeTrue();
        // Second call is idempotent (already belongs to team and has role)
        $jitUserAgain = $provisioner($team, 'jituser@example.com', 'JIT User');
        expect($jitUserAgain->id)->toBe($jitUser->id);

        // 6. Listeners
        $samlLogoutListener = new BuildSamlLogoutRedirect($logoutState);
        session()->forget(['sso_saml_nameid', 'sso_saml_session_index', 'sso_team']);
        $samlLogoutListener->handle(new Logout('web', $user));

        session(['sso_saml_nameid' => 'alice@example.com', 'sso_team' => 999999]);
        $samlLogoutListener->handle(new Logout('web', $user));

        session(['sso_saml_nameid' => 'alice@example.com', 'sso_team' => $team->id]);
        $samlLogoutListener->handle(new Logout('web', $user));

        [, $certPem] = generateSamlKeypair();
        $samlConn->update(['enabled' => true, 'idp_x509_cert' => $certPem, 'idp_slo_url' => 'https://idp.example.com/slo']);
        session(['sso_saml_nameid' => 'alice@example.com', 'sso_saml_session_index' => '_idx1', 'sso_team' => $team->id]);
        $samlLogoutListener->handle(new Logout('web', $user));
        expect($logoutState->url())->toStartWith('https://idp.example.com/slo');

        $ssoLogoutListener = new BuildSsoLogoutRedirect($logoutState);
        session()->forget(['sso_id_token', 'sso_end_session_endpoint']);
        $ssoLogoutListener->handle(new Logout('web', $user));

        session(['sso_id_token' => 'id-tok-1', 'sso_end_session_endpoint' => 'https://idp.example.com/end']);
        $ssoLogoutListener->handle(new Logout('web', $user));
        expect($logoutState->url())->toStartWith('https://idp.example.com/end?id_token_hint=id-tok-1');

        $logSsoLogout = new LogSsoLogout(app(AuditLogService::class));
        session()->forget('sso_authenticated');
        $logSsoLogout->handle(new Logout('web', $user));

        session(['sso_authenticated' => true]);
        $logSsoLogout->handle(new Logout('web', null));

        $this->actingAs($user);
        session(['sso_authenticated' => true]);
        $logSsoLogout->handle(new Logout('web', $user));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.sso_logout']);

        // 7. Middleware: EnsureSsoWhenRequired & VerifyTwilioRequest
        $middleware = new EnsureSsoWhenRequired();
        $samlConn->update(['enabled' => true, 'require_sso' => true]);
        $req = Request::create('/app', 'GET');
        $req->setUserResolver(fn () => $user);
        $req->setLaravelSession($this->app['session']->driver());
        $req->session()->forget('sso_authenticated');
        $res = $middleware->handle($req, fn () => response('ok'));
        expect($res->isRedirect())->toBeTrue();

        $req2 = Request::create('/app', 'GET');
        $req2->setUserResolver(fn () => null);
        $req2->setLaravelSession($this->app['session']->driver());
        $res2 = $middleware->handle($req2, fn () => response('ok'));
        expect($res2->getContent())->toBe('ok');

        $twilioMiddleware = new VerifyTwilioRequest();
        config(['services.twilio.auth_token' => null]);
        $tReq = Request::create('/twilio/webhook', 'POST');
        expect($twilioMiddleware->handle($tReq, fn () => response('passed'))->getContent())->toBe('passed');

        config(['services.twilio.auth_token' => 'secret-token']);
        try {
            $twilioMiddleware->handle($tReq, fn () => response('passed'));
            $this->fail('Expected missing signature to abort 403');
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(403);
        }

        $tReqInvalid = Request::create('/twilio/webhook', 'POST');
        $tReqInvalid->headers->set('X-Twilio-Signature', 'bad-signature');
        try {
            $twilioMiddleware->handle($tReqInvalid, fn () => response('passed'));
            $this->fail('Expected invalid signature to abort 403');
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(403);
        }

        $tReqValid = Request::create('/twilio/webhook', 'POST');
        $tReqValid->headers->set('X-Twilio-Signature', 'valid-signature');
        expect($twilioMiddleware->handle($tReqValid, fn () => response('passed'))->getContent())->toBe('passed');
    });

    it('covers AccountingIntegration, GoogleCalendar, Twilio, ZernioWebhook, and Api Webhook controllers', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        // AccountingIntegrationController
        $mockAccounting = Mockery::mock(AccountingService::class);
        $mockAccounting->shouldReceive('connectPlatform')
            ->once()
            ->with('quickbooks', ['code' => '123'])
            ->andReturn(['access_token' => 'qb-token']);
        $mockAccounting->shouldReceive('connectPlatform')
            ->once()
            ->with('xero', ['code' => 'bad'])
            ->andThrow(new Exception('Xero failed'));

        $accountingController = new AccountingIntegrationController($mockAccounting);
        $connectRes = $accountingController->connect(Request::create('/accounting/connect', 'POST', [
            'platform' => 'quickbooks',
            'credentials' => ['code' => '123'],
        ]));
        expect($connectRes->getStatusCode())->toBe(200);

        $failRes = $accountingController->connect(Request::create('/accounting/connect', 'POST', [
            'platform' => 'xero',
            'credentials' => ['code' => 'bad'],
        ]));
        expect($failRes->getStatusCode())->toBe(400);

        $integration = AccountingIntegration::first();
        $statusRes = $accountingController->status($integration);
        expect($statusRes->getData(true)['platform'])->toBe('quickbooks');

        $disconnectRes = $accountingController->disconnect($integration);
        expect($disconnectRes->getStatusCode())->toBe(200);

        // GoogleCalendarController
        $gcalController = new GoogleCalendarController();
        $redirectRes = $gcalController->redirectToGoogle();
        expect($redirectRes->isRedirect())->toBeTrue();
        $state = session('google_calendar_oauth_state');

        $cbReqWithCode = Request::create('/google/callback', 'GET', ['state' => $state, 'code' => 'auth-code']);
        $cbReqWithCode->setLaravelSession($this->app['session']->driver());
        $cbRes1 = $gcalController->handleGoogleCallback($cbReqWithCode);
        expect($cbRes1->isRedirect())->toBeTrue();

        session(['google_calendar_oauth_state' => $state]);
        $cbReqNoCode = Request::create('/google/callback', 'GET', ['state' => $state]);
        $cbReqNoCode->setLaravelSession($this->app['session']->driver());
        $cbRes2 = $gcalController->handleGoogleCallback($cbReqNoCode);
        expect($cbRes2->isRedirect())->toBeTrue();

        // TwilioController
        $mockTwilio = Mockery::mock(TwilioService::class);
        $mockTwilio->shouldReceive('initiateCall')->with('+15550001')->andReturn((object) ['sid' => 'CA111']);
        $mockTwilio->shouldReceive('logCall')->once()->with('CA111', 10, 'outbound', null, 'initiated');
        $mockTwilio->shouldReceive('initiateCall')->with('+15550002')->andReturn(null);
        $mockTwilio->shouldReceive('startCallRecording')->with('CA111')->andReturn((object) ['sid' => 'RE111']);
        $mockTwilio->shouldReceive('startCallRecording')->with('CA222')->andReturn(null);
        $mockTwilio->shouldReceive('stopCallRecording')->with('CA111')->andReturn(true);
        $mockTwilio->shouldReceive('stopCallRecording')->with('CA222')->andReturn(false);

        $twilioController = new TwilioController($mockTwilio);
        expect($twilioController->initiateCall(Request::create('/twilio/call', 'POST', ['to' => '+15550001', 'contact_id' => 10]))->getStatusCode())->toBe(200);
        expect($twilioController->initiateCall(Request::create('/twilio/call', 'POST', ['to' => '+15550002']))->getStatusCode())->toBe(500);
        expect($twilioController->handleOutboundCall(Request::create('/twilio/out', 'POST', ['To' => '+15550001']))->getStatusCode())->toBe(200);
        expect($twilioController->handleInboundCall(Request::create('/twilio/in', 'POST', ['To' => '+15550001']))->getStatusCode())->toBe(200);
        expect($twilioController->handleRecordingCallback(Request::create('/twilio/rec', 'POST', ['CallSid' => 'CA111', 'RecordingUrl' => 'https://example.com/r', 'RecordingDuration' => 12]))->getStatusCode())->toBe(200);
        expect($twilioController->startRecording(Request::create('/twilio/start', 'POST', ['call_sid' => 'CA111']))->getStatusCode())->toBe(200);
        expect($twilioController->startRecording(Request::create('/twilio/start', 'POST', ['call_sid' => 'CA222']))->getStatusCode())->toBe(500);
        expect($twilioController->stopRecording(Request::create('/twilio/stop', 'POST', ['call_sid' => 'CA111']))->getStatusCode())->toBe(200);
        expect($twilioController->stopRecording(Request::create('/twilio/stop', 'POST', ['call_sid' => 'CA222']))->getStatusCode())->toBe(500);

        // ZernioWebhookController
        config(['services.zernio.webhook_secret' => 'zernio-sec']);
        $team->forceFill(['zernio_profile_id' => 'prof_123'])->save();
        $payload = json_encode([
            'profileId' => 'prof_123',
            'conversation' => [
                'id' => 'conv_1',
                'platform' => 'twitter',
                'participantName' => 'Alice',
                'participantId' => 'p_1',
                'messageId' => 'm_1',
                'message' => 'Hello!',
            ],
        ]);
        $sig = 'sha256='.hash_hmac('sha256', (string) $payload, 'zernio-sec');
        $zReq = Request::create('/zernio/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ZERNIO_SIGNATURE_256' => $sig,
        ], (string) $payload);
        $syncStub = new class() extends SyncExternalConversation
        {
            public array $synced = [];

            public function execute(int $teamId, array $data): void
            {
                $this->synced[] = [$teamId, $data];
            }
        };
        $zController = new ZernioWebhookController();
        $zRes = $zController($zReq, $team, $syncStub);
        expect($zRes->getStatusCode())->toBe(200)
            ->and($syncStub->synced)->toHaveCount(1);

        // Api\WebhookController
        $apiWebhookController = app(WebhookController::class);

        $storeReq = Request::create('/api/v1/webhooks', 'POST', [
            'name' => 'API Hook',
            'url' => 'https://8.8.8.8/webhook',
            'events' => ['contact.created'],
        ]);
        $storeReq->setUserResolver(fn () => $user);
        $createdRes = $apiWebhookController->store($storeReq);
        expect($createdRes->getStatusCode())->toBe(201);
        $webhookModel = Webhook::findOrFail($createdRes->getData(true)['id']);

        $indexReq = Request::create('/api/v1/webhooks', 'GET');
        $indexReq->setUserResolver(fn () => $user);
        expect($apiWebhookController->index($indexReq)->getStatusCode())->toBe(200);

        $noTeamUser = User::factory()->create(['current_team_id' => null]);
        $noTeamReq = Request::create('/api/v1/webhooks', 'GET');
        $noTeamReq->setUserResolver(fn () => $noTeamUser);
        expect($apiWebhookController->index($noTeamReq)->getStatusCode())->toBe(200);

        expect($apiWebhookController->show($indexReq, $webhookModel)->getStatusCode())->toBe(200);

        $updateReq = Request::create('/api/v1/webhooks/'.$webhookModel->id, 'PUT', ['name' => 'Updated Hook']);
        $updateReq->setUserResolver(fn () => $user);
        expect($apiWebhookController->update($updateReq, $webhookModel)->getData(true)['name'])->toBe('Updated Hook');

        expect($apiWebhookController->regenerateSecret($indexReq, $webhookModel)->getStatusCode())->toBe(200);
        expect($apiWebhookController->events()->getStatusCode())->toBe(200);

        $origEnv = app()->environment();
        app()->detectEnvironment(fn () => 'production');
        try {
            $prodValidReq = Request::create('/api/v1/webhooks/'.$webhookModel->id, 'PUT', [
                'url' => 'https://8.8.8.8/webhook',
            ]);
            $prodValidReq->setUserResolver(fn () => $user);
            expect($apiWebhookController->update($prodValidReq, $webhookModel)->getStatusCode())->toBe(200);

            $prodBadReq = Request::create('/api/v1/webhooks/'.$webhookModel->id, 'PUT', [
                'url' => 'http://127.0.0.1/webhook',
            ]);
            $prodBadReq->setUserResolver(fn () => $user);
            $thrownVal = false;
            try {
                $apiWebhookController->update($prodBadReq, $webhookModel);
            } catch (ValidationException) {
                $thrownVal = true;
            }
            expect($thrownVal)->toBeTrue();
        } finally {
            app()->detectEnvironment(fn () => $origEnv);
        }

        expect($apiWebhookController->destroy($indexReq, $webhookModel)->getStatusCode())->toBe(204);
    });

    it('covers OAuthConfigurationController and OAuthController', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        MarketingPlatformSettings::query()->updateOrCreate(['id' => 1], [
            'provider' => 'direct',
            'credentials' => [
                'google_client_id' => 'g-id',
                'google_client_secret' => 'g-sec',
                'facebook_client_id' => 'fb-id',
                'facebook_client_secret' => 'fb-sec',
                'microsoft_client_id' => 'ms-id',
                'microsoft_client_secret' => 'ms-sec',
                'whatsapp_client_id' => 'wa-id',
                'whatsapp_client_secret' => 'wa-sec',
            ],
        ]);

        $controller = app(OAuthConfigurationController::class);
        expect($controller->index()->render())->toContain('OAuth Index');
        expect($controller->create()->render())->toContain('OAuth Create');

        $storeRes = $this->post(route('oauth.configurations.store'), [
            'service_name' => 'youtube',
            'account_name' => 'My YT',
            'additional_settings' => ['region' => 'US'],
        ]);
        $ytConfig = OAuthConfiguration::where('service_name', 'youtube')->firstOrFail();
        $storeRes->assertRedirect(route('oauth.authenticate', ['service' => 'youtube', 'configId' => $ytConfig->id]));

        // Authenticate success for youtube
        $authRes = $controller->authenticate('youtube', $ytConfig->id);
        expect($authRes->isRedirect())->toBeTrue();

        // Authenticate for instagram, outlook, whatsapp (using forceFill for team_id!)
        $igConfig = (new OAuthConfiguration([
            'user_id' => $user->id,
            'service_name' => 'instagram',
            'account_name' => 'IG',
            'client_id' => 'c',
            'client_secret' => 's',
        ]))->forceFill(['team_id' => $team->id]);
        $igConfig->save();
        expect($controller->authenticate('instagram', $igConfig->id)->isRedirect())->toBeTrue();

        $outlookConfig = (new OAuthConfiguration([
            'user_id' => $user->id,
            'service_name' => 'outlook',
            'account_name' => 'Outlook',
            'client_id' => 'c',
            'client_secret' => 's',
        ]))->forceFill(['team_id' => $team->id]);
        $outlookConfig->save();
        $failAuthRes = $controller->authenticate('outlook', $outlookConfig->id);
        expect($failAuthRes->isRedirect())->toBeTrue();

        $waConfig = (new OAuthConfiguration([
            'user_id' => $user->id,
            'service_name' => 'whatsapp',
            'account_name' => 'WA',
            'client_id' => 'c',
            'client_secret' => 's',
        ]))->forceFill(['team_id' => $team->id]);
        $waConfig->save();
        expect($controller->authenticate('whatsapp', $waConfig->id)->isRedirect())->toBeTrue();

        // Callback success with SocialiteTwoUser
        $twoUser = (new SocialiteTwoUser())->map([
            'id' => 'prov-123',
            'name' => 'Test User',
            'nickname' => 'testnick',
            'email' => 'yt@example.com',
            'avatar' => 'https://example.com/a.png',
        ]);
        $twoUser->token = 'access-tok-123';
        $twoUser->refreshToken = 'refresh-tok-123';
        $twoUser->expiresIn = 3600;

        Socialite::extend('google', function () use ($twoUser) {
            $mock = Mockery::mock(AbstractProvider::class);
            $mock->shouldReceive('user')->andReturn($twoUser);
            $mock->shouldReceive('scopes')->andReturnSelf();
            $mock->shouldReceive('with')->andReturnSelf();
            $mock->shouldReceive('redirect')->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth'));

            return $mock;
        });

        session(['oauth_config_id' => $ytConfig->id, 'oauth_config_service' => 'youtube']);
        $cbSuccessRes = $controller->callback('youtube');
        expect($cbSuccessRes->isRedirect())->toBeTrue()
            ->and($ytConfig->fresh()->is_active)->toBeTrue()
            ->and($ytConfig->fresh()->access_token)->toBe('access-tok-123');

        $twoUserNoExpiry = (new SocialiteTwoUser())->map(['id' => 'ig-1', 'name' => 'IG', 'email' => 'ig@example.com', 'avatar' => null]);
        $twoUserNoExpiry->token = 'ig-tok';
        $twoUserNoExpiry->refreshToken = null;
        $twoUserNoExpiry->expiresIn = null;

        Socialite::extend('facebook', function () use ($twoUserNoExpiry) {
            $mock = Mockery::mock(AbstractProvider::class);
            $mock->shouldReceive('user')->andReturn($twoUserNoExpiry);
            $mock->shouldReceive('scopes')->andReturnSelf();
            $mock->shouldReceive('redirect')->andReturn(new RedirectResponse('https://facebook.com/oauth'));

            return $mock;
        });
        session(['oauth_config_id' => $igConfig->id, 'oauth_config_service' => 'instagram']);
        expect($controller->callback('instagram')->isRedirect())->toBeTrue();

        Socialite::extend('microsoft', function () {
            $mock = Mockery::mock(AbstractProvider::class);
            $mock->shouldReceive('user')->andReturn(Mockery::mock(SocialiteContractUser::class));

            return $mock;
        });
        session(['oauth_config_id' => $outlookConfig->id, 'oauth_config_service' => 'outlook']);
        expect($controller->callback('outlook')->isRedirect())->toBeTrue();

        Socialite::extend('whatsapp', function () {
            $mock = Mockery::mock(AbstractProvider::class);
            $mock->shouldReceive('user')->andReturn(Mockery::mock(SocialiteContractUser::class));

            return $mock;
        });
        session(['oauth_config_id' => $waConfig->id, 'oauth_config_service' => 'whatsapp']);
        expect($controller->callback('whatsapp')->isRedirect())->toBeTrue();

        $destroyRes = $controller->destroy($waConfig);
        expect($destroyRes->isRedirect())->toBeTrue();

        // OAuthController
        $oauthController = new OAuthController();
        expect($oauthController->redirect('unconfigured-provider')->isRedirect())->toBeTrue();
        expect($oauthController->callback('unconfigured-provider')->isRedirect())->toBeTrue();

        foreach (['google', 'facebook', 'linkedin-openid'] as $prov) {
            (new OAuthConfiguration([
                'user_id' => $user->id,
                'service_name' => $prov,
                'client_id' => 'cid',
                'client_secret' => 'csec',
            ]))->forceFill(['team_id' => $team->id])->save();
        }

        Socialite::extend('linkedin-openid', function () use ($twoUserNoExpiry) {
            $mock = Mockery::mock(AbstractProvider::class);
            $mock->shouldReceive('user')->andReturn($twoUserNoExpiry);
            $mock->shouldReceive('scopes')->andReturnSelf();
            $mock->shouldReceive('redirect')->andReturn(new RedirectResponse('https://linkedin.com/oauth'));

            return $mock;
        });

        expect($oauthController->redirect('google')->isRedirect())->toBeTrue();

        Http::swap(new HttpFactory());
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['data' => [['id' => 'p1', 'name' => 'Page 1']]]),
            'https://api.linkedin.com/*' => Http::response(['elements' => [['organizationalTarget' => 'urn:li:organization:1']]]),
            'https://www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['id' => 'ch1']]]),
        ]);
        expect($oauthController->callback('facebook')->isRedirect())->toBeTrue();
        expect($oauthController->callback('linkedin-openid')->isRedirect())->toBeTrue();
        expect($oauthController->callback('google')->isRedirect())->toBeTrue();

        Http::swap(new HttpFactory());
        Http::fake(fn () => throw new Exception('HTTP network failure'));
        expect($oauthController->callback('facebook')->isRedirect())->toBeTrue();
        expect($oauthController->callback('linkedin-openid')->isRedirect())->toBeTrue();
        expect($oauthController->callback('google')->isRedirect())->toBeTrue();

        Socialite::extend('google', function () {
            $mock = Mockery::mock(AbstractProvider::class);
            $mock->shouldReceive('user')->andThrow(new Exception('Socialite error'));

            return $mock;
        });
        Socialite::forgetDrivers();
        expect($oauthController->callback('google')->isRedirect())->toBeTrue();
    });

    it('covers SamlLoginController, SamlMetadataController, SsoLoginController, OidcClient, SamlPayloadGuard, and SamlSettings', function (): void {
        [, $certPem] = generateSamlKeypair();
        $owner = User::factory()->withPersonalTeam()->create(['email' => 'owner@corp.example']);
        $team = $owner->currentTeam;

        // SamlMetadataController 404 before connection
        $this->get(route('saml.metadata', $team))->assertNotFound();
        // SamlLoginController 404 before connection
        $this->get(route('saml.login', $team))->assertNotFound();

        $samlConn = SamlConnection::create([
            'team_id' => $team->id,
            'idp_entity_id' => 'https://idp.example.com/entity',
            'idp_sso_url' => 'https://idp.example.com/sso',
            'idp_slo_url' => 'https://idp.example.com/slo',
            'idp_x509_cert' => $certPem,
            'enabled' => true,
            'allow_jit' => false,
            'allowed_domain' => null,
            'role_mappings' => ['managers' => Role::Manager->value],
        ]);

        // SamlMetadataController 200
        $this->get(route('saml.metadata', $team))->assertOk();
        // SamlLoginController redirect
        $this->get(route('saml.login', $team))->assertRedirect();

        $validGuardXml = '<Response xmlns:ds="'.XMLSecurityDSig::XMLDSIGNS.'"><ds:Signature><ds:SignedInfo><ds:Reference URI="#assertion"><ds:Transforms><ds:Transform Algorithm="'.XMLSecurityDSig::EXC_C14N.'"/></ds:Transforms></ds:Reference></ds:SignedInfo></ds:Signature></Response>';
        $encodedSaml = base64_encode($validGuardXml);

        // ACS: non-member denied when allow_jit is false
        SamlAuth::$nextErrors = [];
        SamlAuth::$nextAuthenticated = true;
        SamlAuth::$nextNameId = 'stranger@corp.example';
        SamlAuth::$nextAttributes = [];
        $this->withSession(['saml_request_id' => '_req1', 'saml_team' => $team->id])
            ->post(route('saml.acs', $team), ['SAMLResponse' => $encodedSaml, 'RelayState' => '/app'])
            ->assertForbidden();

        // ACS: allow_jit = true, allowed_domain = null -> provisions user & maps role
        $samlConn->update(['allow_jit' => true, 'allowed_domain' => null]);
        SamlAuth::$nextNameId = 'newjit@any.example';
        SamlAuth::$nextAttributes = ['groups' => ['managers'], 'name' => ['New JIT']];
        $this->withSession(['saml_request_id' => '_req2', 'saml_team' => $team->id])
            ->post(route('saml.acs', $team), ['SAMLResponse' => $encodedSaml])
            ->assertRedirect('/app');

        // ACS: allow_jit = true, allowed_domain = 'corp.example'
        $samlConn->update(['allowed_domain' => 'corp.example']);
        SamlAuth::$nextNameId = 'domainjit@corp.example';
        SamlAuth::$nextAttributes = [];
        $this->withSession(['saml_request_id' => '_req3', 'saml_team' => $team->id])
            ->post(route('saml.acs', $team), ['SAMLResponse' => $encodedSaml])
            ->assertRedirect('/app');

        // ACS: owner login with group mapping triggers catch (InvalidArgumentException) on provisionTeamRole
        SamlAuth::$nextNameId = 'owner@corp.example';
        SamlAuth::$nextAttributes = ['groups' => ['managers']];
        $this->withSession(['saml_request_id' => '_req4', 'saml_team' => $team->id])
            ->post(route('saml.acs', $team), ['SAMLResponse' => $encodedSaml])
            ->assertRedirect('/app');

        // SLS: valid LogoutResponse
        SamlAuth::$nextErrors = [];
        $encodedLogoutResp = base64_encode((string) gzdeflate($validGuardXml));
        $this->get('/saml/'.$team->id.'/sls?SAMLResponse='.urlencode($encodedLogoutResp))
            ->assertRedirect('/login');

        // SLS: with SAMLRequest + Signature + SigAlg and errors
        unset($_GET['SAMLResponse']);
        SamlAuth::$nextErrors = ['invalid_logout'];
        $this->get('/saml/'.$team->id.'/sls?SAMLRequest='.urlencode($encodedLogoutResp).'&Signature=sig&SigAlg=alg')
            ->assertForbidden();
        SamlAuth::$nextErrors = [];
        unset($_GET['SAMLRequest'], $_GET['Signature'], $_GET['SigAlg']);

        // SamlPayloadGuard full branch coverage
        expect(SamlPayloadGuard::allows(base64_encode($validGuardXml)))->toBeTrue()
            ->and(SamlPayloadGuard::allows(base64_encode((string) gzdeflate($validGuardXml)), deflated: true))->toBeTrue()
            ->and(SamlPayloadGuard::allows(base64_encode('OVERFLOW_INFLATE'), deflated: true))->toBeFalse()
            ->and(SamlPayloadGuard::allows(''))->toBeFalse()
            ->and(SamlPayloadGuard::allows('%%%not-base64%%%'))->toBeFalse()
            ->and(SamlPayloadGuard::allows(base64_encode((string) gzdeflate('<Response>'.str_repeat('x', 2_097_152).'</Response>')), deflated: true))->toBeFalse()
            ->and(SamlPayloadGuard::allows(base64_encode('<broken')))->toBeFalse()
            ->and(SamlPayloadGuard::allows(base64_encode('<Response xmlns:ds="'.XMLSecurityDSig::XMLDSIGNS.'">'.str_repeat('<ds:Signature/>', 3).'</Response>')))->toBeFalse()
            ->and(SamlPayloadGuard::allows(base64_encode('<Response xmlns:ds="'.XMLSecurityDSig::XMLDSIGNS.'"><ds:Reference URI="http://evil"/></Response>')))->toBeFalse()
            ->and(SamlPayloadGuard::allows(base64_encode('<Response xmlns:ds="'.XMLSecurityDSig::XMLDSIGNS.'"><ds:Transform Algorithm="http://evil"/></Response>')))->toBeFalse();

        // OidcClient & SsoLoginController
        $ssoTeam = Team::factory()->create(['user_id' => $owner->id]);
        $this->get(route('sso.redirect', $ssoTeam))->assertNotFound();

        $rsaKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rsaKey, $oidcPrivPem);
        $rsaDetails = openssl_pkey_get_details($rsaKey);
        $b64Url = static fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        $jwks = ['keys' => [[
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => 'k1',
            'n' => $b64Url($rsaDetails['rsa']['n']),
            'e' => $b64Url($rsaDetails['rsa']['e']),
        ]]];

        $ssoConn = SsoConnection::create([
            'team_id' => $ssoTeam->id,
            'provider' => 'oidc',
            'client_id' => 'client-1',
            'client_secret' => 'sec-1',
            'issuer_url' => 'https://8.8.8.8',
            'enabled' => true,
            'allow_jit' => false,
            'allowed_domain' => null,
            'token_auth_method' => 'client_secret_basic',
            'role_mappings' => ['managers' => Role::Manager->value],
        ]);

        $oidc = app(OidcClient::class);

        // OidcClient SSRF and error branches
        expect(fn () => $oidc->discover(new SsoConnection(['issuer_url' => 'http://example.com'])))->toThrow(SsoException::class);

        Cache::flush();
        Http::swap(new HttpFactory());
        Http::fake(['https://8.8.8.8/.well-known/openid-configuration' => Http::response('err', 500)]);
        expect(fn () => $oidc->discover($ssoConn))->toThrow(SsoException::class);

        Cache::flush();
        Http::swap(new HttpFactory());
        Http::fake(['https://8.8.8.8/.well-known/openid-configuration' => Http::response(['authorization_endpoint' => 'https://8.8.8.8/auth'])]);
        expect(fn () => $oidc->discover($ssoConn))->toThrow(SsoException::class);

        // Missing jwks_uri
        Cache::flush();
        Http::swap(new HttpFactory());
        Http::fake([
            'https://8.8.8.8/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://8.8.8.8/authorize',
                'token_endpoint' => 'https://8.8.8.8/token',
                'userinfo_endpoint' => 'https://8.8.8.8/userinfo',
            ]),
        ]);
        expect(fn () => $oidc->jwks($ssoConn))->toThrow(SsoException::class);

        // Failed jwks HTTP response & failed token exchange & failed userinfo
        Cache::flush();
        Http::swap(new HttpFactory());
        Http::fake([
            'https://8.8.8.8/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://8.8.8.8/authorize',
                'token_endpoint' => 'https://8.8.8.8/token',
                'userinfo_endpoint' => 'https://8.8.8.8/userinfo',
                'jwks_uri' => 'https://8.8.8.8/jwks',
                'end_session_endpoint' => 'https://8.8.8.8/logout',
            ]),
            'https://8.8.8.8/jwks' => Http::response('err', 500),
            'https://8.8.8.8/token' => Http::response('err', 400),
            'https://8.8.8.8/userinfo' => Http::response('err', 401),
        ]);
        expect(fn () => $oidc->jwks($ssoConn))->toThrow(SsoException::class);
        expect(fn () => $oidc->exchangeCode($ssoConn, 'c', 'https://app.test/cb', 'v'))->toThrow(SsoException::class);
        expect(fn () => $oidc->userinfo($ssoConn, 'bad-tok'))->toThrow(SsoException::class);

        // SsoLoginController callback when exchangeCode throws SsoException -> 403
        $this->withSession(['sso_state' => 'st1', 'sso_nonce' => 'n1', 'sso_verifier' => 'v1', 'sso_team' => $ssoTeam->id])
            ->get(route('sso.callback', $ssoTeam).'?code=bad&state=st1')
            ->assertForbidden();

        // Valid JWKS for validateIdToken error branches
        Cache::flush();
        Http::swap(new HttpFactory());
        Http::fake([
            'https://8.8.8.8/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://8.8.8.8/authorize',
                'token_endpoint' => 'https://8.8.8.8/token',
                'userinfo_endpoint' => 'https://8.8.8.8/userinfo',
                'jwks_uri' => 'https://8.8.8.8/jwks',
                'end_session_endpoint' => 'https://8.8.8.8/logout',
            ]),
            'https://8.8.8.8/jwks' => Http::response($jwks),
        ]);

        // Invalid token string
        expect(fn () => $oidc->validateIdToken($ssoConn, 'not-a-jwt', 'n1'))->toThrow(SsoException::class);

        $makeJwt = static fn (array $overrides): string => JWT::encode(array_merge([
            'iss' => 'https://8.8.8.8',
            'aud' => 'client-1',
            'exp' => time() + 3600,
            'iat' => time(),
            'nonce' => 'n1',
            'sub' => 'sub-1',
            'email' => 'owner@corp.example',
        ], $overrides), (string) $oidcPrivPem, 'RS256', 'k1');

        // Issuer mismatch
        expect(fn () => $oidc->validateIdToken($ssoConn, $makeJwt(['iss' => 'https://other.example.com']), 'n1'))->toThrow(SsoException::class);
        // Audience mismatch
        expect(fn () => $oidc->validateIdToken($ssoConn, $makeJwt(['aud' => 'wrong-client']), 'n1'))->toThrow(SsoException::class);
        // Nonce mismatch
        expect(fn () => $oidc->validateIdToken($ssoConn, $makeJwt(['nonce' => 'wrong-nonce']), 'n1'))->toThrow(SsoException::class);

        // SsoLoginController redirect
        $this->get(route('sso.redirect', $ssoTeam))->assertRedirect();

        // SsoLoginController callback with id_token + end_session_endpoint + owner group mapping catch
        $validIdToken = $makeJwt(['email' => 'owner@corp.example', 'groups' => ['managers']]);
        Cache::flush();
        Http::swap(new HttpFactory());
        Http::fake([
            'https://8.8.8.8/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://8.8.8.8/authorize',
                'token_endpoint' => 'https://8.8.8.8/token',
                'userinfo_endpoint' => 'https://8.8.8.8/userinfo',
                'jwks_uri' => 'https://8.8.8.8/jwks',
                'end_session_endpoint' => 'https://8.8.8.8/logout',
            ]),
            'https://8.8.8.8/jwks' => Http::response($jwks),
            'https://8.8.8.8/token' => Http::response(['access_token' => 'at-1', 'id_token' => $validIdToken]),
        ]);
        $this->withSession(['sso_state' => 'st1', 'sso_nonce' => 'n1', 'sso_verifier' => 'v1', 'sso_team' => $ssoTeam->id])
            ->get(route('sso.callback', $ssoTeam).'?code=ok&state=st1')
            ->assertRedirect('/app');

        // SsoLoginController callback with userinfo fallback (no id_token) + client_secret_post + JIT variations
        $ssoConn->update(['token_auth_method' => 'client_secret_post', 'allow_jit' => false]);
        Cache::flush();
        Http::swap(new HttpFactory());
        Http::fake([
            'https://8.8.8.8/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://8.8.8.8/authorize',
                'token_endpoint' => 'https://8.8.8.8/token',
                'userinfo_endpoint' => 'https://8.8.8.8/userinfo',
            ]),
            'https://8.8.8.8/token' => Http::response(['access_token' => 'at-2']),
            'https://8.8.8.8/userinfo' => Http::response(['email' => 'oidc-jit@corp.example', 'name' => 'OIDC JIT', 'groups' => ['managers']]),
        ]);
        $this->withSession(['sso_state' => 'st2', 'sso_nonce' => 'n2', 'sso_verifier' => 'v2', 'sso_team' => $ssoTeam->id])
            ->get(route('sso.callback', $ssoTeam).'?code=ok&state=st2')
            ->assertForbidden();

        $ssoConn->update(['allow_jit' => true, 'allowed_domain' => null]);
        $this->withSession(['sso_state' => 'st3', 'sso_nonce' => 'n3', 'sso_verifier' => 'v3', 'sso_team' => $ssoTeam->id])
            ->get(route('sso.callback', $ssoTeam).'?code=ok&state=st3')
            ->assertRedirect('/app');

        $ssoConn->update(['allowed_domain' => 'corp.example']);
        Cache::flush();
        Http::swap(new HttpFactory());
        Http::fake([
            'https://8.8.8.8/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://8.8.8.8/authorize',
                'token_endpoint' => 'https://8.8.8.8/token',
                'userinfo_endpoint' => 'https://8.8.8.8/userinfo',
            ]),
            'https://8.8.8.8/token' => Http::response(['access_token' => 'at-3']),
            'https://8.8.8.8/userinfo' => Http::response(['email' => 'oidc-jit2@corp.example', 'name' => 'OIDC JIT 2']),
        ]);
        $this->withSession(['sso_state' => 'st4', 'sso_nonce' => 'n4', 'sso_verifier' => 'v4', 'sso_team' => $ssoTeam->id])
            ->get(route('sso.callback', $ssoTeam).'?code=ok&state=st4')
            ->assertRedirect('/app');
    });

    it('covers OAuthManager, TwilioProvider, DirectSocialApiClient, and Zernio services', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        // OAuthManager
        $manager = new OAuthManager();
        expect($manager->getSupportedProviders())->toContain('google', 'mailchimp', 'twitter')
            ->and($manager->isProviderSupported('google'))->toBeTrue()
            ->and($manager->isProviderSupported('unknown'))->toBeFalse();

        expect(fn () => $manager->getAuthorizationUrl('unknown'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $manager->getAuthorizationUrl('google'))->toThrow(RuntimeException::class);
        expect(fn () => $manager->exchangeCodeForToken('unknown', 'code'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $manager->exchangeCodeForToken('google', 'code'))->toThrow(RuntimeException::class);

        foreach (['google', 'mailchimp', 'twitter'] as $prov) {
            (new OAuthConfiguration([
                'user_id' => $user->id,
                'service_name' => $prov,
                'client_id' => $prov.'-id',
                'client_secret' => $prov.'-sec',
            ]))->forceFill(['team_id' => $team->id])->save();
        }

        expect($manager->getAuthorizationUrl('google'))->toContain('access_type=offline')
            ->and($manager->getAuthorizationUrl('mailchimp'))->not->toContain('scope=')
            ->and($manager->getAuthorizationUrl('twitter'))->toContain('code_challenge=challenge');

        $mockHandler = new MockHandler([
            new GuzzleResponse(200, [], (string) json_encode(['access_token' => 'mc-tok', 'expires_in' => 3600])),
            new GuzzleResponse(200, [], (string) json_encode(['user_id' => 'mc-u1', 'accountname' => 'MC Acc', 'login' => ['email' => 'mc@example.com']])),
            new GuzzleResponse(200, [], (string) json_encode(['access_token' => 'mc-tok-2'])),
            new GuzzleResponse(500, [], 'Server Error'),
            new GuzzleResponse(400, [], 'Bad Request'),
            new GuzzleResponse(200, [], (string) json_encode(['access_token' => 'new-tok', 'refresh_token' => 'new-ref', 'expires_in' => 3600])),
            new GuzzleResponse(500, [], 'Error'),
        ]);
        $refClient = new ReflectionProperty(OAuthManager::class, 'client');
        $refClient->setAccessible(true);
        $refClient->setValue($manager, new GuzzleClient(['handler' => HandlerStack::create($mockHandler)]));

        $mcTokens = $manager->exchangeCodeForToken('mailchimp', 'code-1');
        expect($mcTokens['metadata']['user_id'])->toBe('mc-u1');

        $mcTokens2 = $manager->exchangeCodeForToken('mailchimp', 'code-2');
        expect($mcTokens2['metadata'])->toBe([]);

        expect(fn () => $manager->exchangeCodeForToken('google', 'bad-code'))->toThrow(RuntimeException::class);

        $connected = $manager->saveConnectedAccount('mailchimp', $mcTokens, $user->id);
        expect($connected->provider_id)->toBe('mc-u1');

        $connectedNoExp = ConnectedAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'g1',
            'token' => 't',
            'refresh_token' => null,
            'expires_at' => null,
        ]);
        expect($manager->needsRefresh($connectedNoExp))->toBeFalse();
        expect($manager->refreshToken($connectedNoExp))->toBeFalse();

        $connectedUnsupported = ConnectedAccount::create([
            'user_id' => $user->id,
            'provider' => 'unsupported',
            'provider_id' => 'u1',
            'token' => 't',
            'refresh_token' => 'r',
        ]);
        expect(fn () => $manager->refreshToken($connectedUnsupported))->toThrow(InvalidArgumentException::class);

        $connectedUnconfigured = ConnectedAccount::create([
            'user_id' => $user->id,
            'provider' => 'zoom',
            'provider_id' => 'z1',
            'token' => 't',
            'refresh_token' => 'r',
        ]);
        expect(fn () => $manager->refreshToken($connectedUnconfigured))->toThrow(RuntimeException::class);

        $connectedGoogle = ConnectedAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'g2',
            'token' => 't',
            'refresh_token' => 'r',
            'expires_at' => now()->addMinutes(2),
        ]);
        expect($manager->needsRefresh($connectedGoogle))->toBeTrue();
        expect($manager->refreshToken($connectedGoogle))->toBeTrue();
        expect($manager->refreshToken($connectedGoogle))->toBeFalse();

        // TwilioProvider
        $twilioProvider = new TwilioProvider(
            Request::create('/oauth/twilio', 'GET'),
            'twilio-cid',
            'twilio-csec',
            'https://app.test/oauth/twilio/callback'
        );
        $twilioMock = new MockHandler([
            new GuzzleResponse(200, [], (string) json_encode([
                'sub' => 'tw-123',
                'name' => 'Twilio User',
                'email' => 'tw@example.com',
                'account_sid' => 'AC123',
            ])),
        ]);
        $twilioProvider->setHttpClient(new GuzzleClient(['handler' => HandlerStack::create($twilioMock)]));

        $callProtected = static function (object $obj, string $method, array $args = []): mixed {
            $ref = new ReflectionMethod($obj, $method);
            $ref->setAccessible(true);

            return $ref->invokeArgs($obj, $args);
        };

        expect($callProtected($twilioProvider, 'getAuthUrl', ['state-1']))->toContain('accounts.twilio.com/oauth2/v1/authorize')
            ->and($callProtected($twilioProvider, 'getTokenUrl'))->toBe('https://accounts.twilio.com/oauth2/v1/token')
            ->and($callProtected($twilioProvider, 'getTokenFields', ['code-1'])['grant_type'])->toBe('authorization_code');

        $rawTwilioUser = $callProtected($twilioProvider, 'getUserByToken', ['tok-1']);
        $mappedTwilioUser = $callProtected($twilioProvider, 'mapUserToObject', [$rawTwilioUser]);
        expect($mappedTwilioUser->getId())->toBe('tw-123');

        // DirectSocialApiClient
        MarketingPlatformSettings::query()->updateOrCreate(['id' => 1], [
            'provider' => 'direct',
            'credentials' => [],
        ]);
        config([
            'services.zernio.direct_api_enabled' => true,
            'services.social.direct_base_urls.facebook' => 'https://graph.facebook.com/v18.0',
        ]);
        $fbOAuth = (new OAuthConfiguration([
            'user_id' => $user->id,
            'service_name' => 'facebook',
            'client_id' => 'c',
            'client_secret' => 's',
            'access_token' => 'fb-direct-token',
        ]))->forceFill(['team_id' => $team->id]);
        $fbOAuth->save();
        $directClient = new DirectSocialApiClient();
        Http::swap(new HttpFactory());
        Http::fake([
            'https://graph.facebook.com/v18.0/me' => Http::sequence()
                ->push(['id' => 'fb-me'], 200)
                ->push('error', 500),
        ]);
        expect($directClient->request($fbOAuth, 'get', '/me'))->toBe(['id' => 'fb-me']);
        expect(fn () => $directClient->request($fbOAuth, 'get', '/me'))->toThrow(RuntimeException::class);

        // ZernioClient, ZernioAdvertisingService, ZernioPublisher, ZernioTenantService
        config([
            'services.zernio.base_url' => 'https://zernio.com/api/v1',
            'services.zernio.api_key' => '',
        ]);
        $zernioClient = app(ZernioClient::class);
        expect(fn () => $zernioClient->request())->toThrow(ZernioException::class);

        $zPublisher = app(ZernioPublisher::class);
        expect($zPublisher->canPublish([['platform' => 'twitter', 'account_id' => 'acc_1']]))->toBeFalse();

        $zTenant = app(ZernioTenantService::class);
        $zTenant->provisionIfConfigured($team);

        config(['services.zernio.api_key' => 'sk_test_123']);
        MarketingPlatformSettings::query()->updateOrCreate(['id' => 1], [
            'provider' => 'zernio',
            'credentials' => ['zernio_api_key' => 'sk_test_123'],
        ]);

        Http::swap(new HttpFactory());
        Http::fake([
            'https://zernio.com/api/v1/profiles' => Http::sequence()
                ->push([], 200)
                ->push(['profile' => ['_id' => 'prof_new']], 201)
                ->push(['error' => 'Profile failed'], 422),
            'https://zernio.com/api/v1/posts/error-test' => Http::response(['error' => 'Bad request'], 400),
            'https://zernio.com/api/v1/*' => Http::response(['ok' => true], 200),
        ]);

        $teamNoProf = Team::factory()->create(['zernio_profile_id' => null]);
        expect(fn () => $zTenant->ensureProfile($teamNoProf))->toThrow(ZernioException::class);
        expect($zTenant->ensureProfile($teamNoProf))->toBe('prof_new');
        expect($zTenant->ensureProfile($teamNoProf))->toBe('prof_new');

        $teamFailProf = Team::factory()->create(['zernio_profile_id' => null]);
        $zTenant->provisionIfConfigured($teamFailProf);

        expect(fn () => $zTenant->connectUrl($teamNoProf, 'invalid-platform'))->toThrow(ZernioException::class);
        $zTenant->connectUrl($teamNoProf, 'twitter', 'https://app.test/cb');
        $zTenant->accounts($teamNoProf);
        $zTenant->analytics($teamNoProf);
        $zTenant->inbox($teamNoProf);
        $zTenant->comments($teamNoProf);
        $zTenant->reviews($teamNoProf);
        $zTenant->inboxAnalytics($teamNoProf);
        $zTenant->replyToComment($teamNoProf, 'c1', 'a1', 'hello');

        $zAds = app(ZernioAdvertisingService::class);
        $zAds->listAds($teamNoProf);
        $zAds->campaignAnalytics($teamNoProf, 'camp1');
        $zAds->adAnalytics($teamNoProf, 'ad1');
        $zAds->createCampaign($teamNoProf, ['name' => 'C1']);
        $zAds->updateCampaign($teamNoProf, 'camp1', ['name' => 'C1-up']);

        $zernioClient->createPost(['content' => 'hi']);
        $zernioClient->getPost('p1');
        $zernioClient->listPosts();
        $zernioClient->updatePost('p1', ['content' => 'up']);
        $zernioClient->validatePost(['content' => 'hi']);
        $zernioClient->createCampaign(['name' => 'C2'], 'idem-1');
        $zernioClient->listCampaigns();
        $zernioClient->campaignStatus('camp1', ['status' => 'PAUSED']);
        $zernioClient->deleteCampaign('camp1', []);
        $zernioClient->deletePost('p1');
        $zernioClient->getConversation('conv1', ['profileId' => 'prof_new']);
        $zernioClient->sendInboxMessage('conv1', 'acc1', 'msg', 'prof_new');

        expect(fn () => $zernioClient->getPost('error-test'))->toThrow(ZernioException::class);

        // ZernioPublisher all branches
        $postNoTeam = SocialMediaPost::create([
            'team_id' => null,
            'content' => 'Post content',
            'platforms' => ['twitter'],
            'image_path' => 'img.png',
            'video_url' => 'https://example.com/v.mp4',
            'link' => 'https://example.com',
            'scheduled_at' => now()->addHour(),
        ]);

        expect(fn () => $zPublisher->publish($postNoTeam, ['unsupported']))->toThrow(ZernioException::class);
        expect(fn () => $zPublisher->publish($postNoTeam, ['twitter']))->toThrow(ZernioException::class);

        expect($zPublisher->canPublishForPost($postNoTeam))->toBeFalse();
        config(['services.zernio.account_ids' => ['twitter' => 'acc_tw_1']]);
        expect($zPublisher->canPublishForPost($postNoTeam))->toBeTrue()
            ->and($zPublisher->canPublish([['platform' => 'twitter', 'account_id' => 'acc_tw_1']]))->toBeTrue()
            ->and($zPublisher->canPublish([['platform' => 'twitter', 'account_id' => '']]))->toBeFalse();

        Http::swap(new HttpFactory());
        Http::fake(['https://zernio.com/api/v1/posts' => Http::response(['post' => ['_id' => 'p_sched']], 201)]);
        expect($zPublisher->publish($postNoTeam, ['twitter']))->toHaveKey('post');

        $postWithTeam = SocialMediaPost::create([
            'team_id' => $teamNoProf->id,
            'content' => 'Team post',
            'platforms' => [['platform' => 'twitter', 'account_id' => 'acc_team_1']],
            'scheduled_at' => null,
        ]);

        Http::swap(new HttpFactory());
        Http::fake([
            'https://zernio.com/api/v1/accounts*' => Http::sequence()
                ->push(['accounts' => 'not-an-array'], 200)
                ->push(['accounts' => [['invalid' => true], ['platform' => 'twitter', '_id' => 'acc_team_1']]], 200)
                ->push(['accounts' => [['platform' => 'twitter', '_id' => 'acc_team_1']]], 200)
                ->push(['accounts' => [['platform' => 'twitter', '_id' => 'acc_team_1']]], 200)
                ->push(['accounts' => [['platform' => 'twitter', '_id' => 'acc_team_1']]], 200),
            'https://zernio.com/api/v1/posts' => Http::response(['post' => ['_id' => 'p_team']], 201),
        ]);

        expect($zPublisher->canPublishForPost($postWithTeam))->toBeFalse();
        expect($zPublisher->canPublishForPost($postWithTeam))->toBeTrue();
        $postWithTeam->platforms = [['platform' => 'twitter', 'account_id' => 'wrong_acc']];
        expect($zPublisher->canPublishForPost($postWithTeam))->toBeFalse();
        expect(fn () => $zPublisher->publish($postWithTeam, [['platform' => 'twitter', 'account_id' => 'wrong_acc']]))->toThrow(ZernioException::class);
        expect($zPublisher->publish($postWithTeam, [['platform' => 'twitter', 'account_id' => 'acc_team_1']]))->toHaveKey('post');
    });

    it('covers all 6 Filament resources and 17 resource pages', function (): void {
        $superAdmin = User::factory()->withPersonalTeam()->create(['email_verified_at' => now()]);
        $team = $superAdmin->currentTeam;
        setPermissionsTeamId($team->id);
        $superAdminRole = SpatieRole::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]);
        $adminRole = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'web', 'team_id' => null]);
        $superAdmin->assignRole($superAdminRole);
        $superAdmin->assignRole($adminRole);

        foreach (['call_setting', 'oauth_configuration', 'saml_connection', 'sso_connection', 'whatsapp_number'] as $resName) {
            foreach (['view', 'create', 'update', 'delete'] as $act) {
                Permission::firstOrCreate(['name' => "{$act}_{$resName}", 'guard_name' => 'web']);
            }
        }

        $regularUser = User::factory()->withPersonalTeam()->create(['email_verified_at' => now()]);

        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);

        $makeTable = static fn (): Table => Table::make(Mockery::mock(HasTable::class));

        // 1. CallSettingResource + Pages
        expect(CallSettingResource::form(FilamentSchema::make())->getComponents())->not->toBeEmpty()
            ->and(CallSettingResource::table($makeTable())->getColumns())->not->toBeEmpty()
            ->and(CallSettingResource::getRelations())->toBe([])
            ->and(CallSettingResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

        $callSetting = (new CallSetting(['name' => 'cs1', 'value' => 'v1', 'description' => 'd1']))->forceFill(['team_id' => $team->id]);
        $callSetting->save();
        Livewire::test(ListCallSettings::class)->assertOk();
        Livewire::test(CreateCallSetting::class)->assertOk();
        Livewire::test(EditCallSetting::class, ['record' => $callSetting->getRouteKey()])->assertOk();

        // 2. OAuthConfigurationResource + Pages
        $oauthRecord = (new OAuthConfiguration([
            'user_id' => $superAdmin->id,
            'service_name' => 'google',
            'client_id' => 'cid',
            'client_secret' => 'csec',
            'is_active' => true,
        ]))->forceFill(['team_id' => $team->id]);
        $oauthRecord->save();

        $this->actingAs($superAdmin);
        expect(OAuthConfigurationResource::canViewAny())->toBeTrue()
            ->and(OAuthConfigurationResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
            ->and(OAuthConfigurationResource::canCreate())->toBeTrue()
            ->and(OAuthConfigurationResource::getCreateAuthorizationResponse()->allowed())->toBeTrue()
            ->and(OAuthConfigurationResource::canEdit($oauthRecord))->toBeTrue()
            ->and(OAuthConfigurationResource::getEditAuthorizationResponse($oauthRecord)->allowed())->toBeTrue()
            ->and(OAuthConfigurationResource::canView($oauthRecord))->toBeTrue()
            ->and(OAuthConfigurationResource::getViewAuthorizationResponse($oauthRecord)->allowed())->toBeTrue()
            ->and(OAuthConfigurationResource::canDelete($oauthRecord))->toBeTrue()
            ->and(OAuthConfigurationResource::getDeleteAuthorizationResponse($oauthRecord)->allowed())->toBeTrue()
            ->and(OAuthConfigurationResource::canDeleteAny())->toBeTrue()
            ->and(OAuthConfigurationResource::getDeleteAnyAuthorizationResponse()->allowed())->toBeTrue()
            ->and(OAuthConfigurationResource::permissionResource())->toBe('oauth_configuration')
            ->and(OAuthConfigurationResource::form(FilamentSchema::make())->getComponents())->not->toBeEmpty()
            ->and(OAuthConfigurationResource::table($makeTable())->getColumns())->not->toBeEmpty()
            ->and(OAuthConfigurationResource::getRelations())->toBe([])
            ->and(OAuthConfigurationResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

        Livewire::test(ListOAuthConfigurations::class)->assertOk();
        Livewire::test(CreateOAuthConfiguration::class)
            ->fillForm([
                'service_name' => 'facebook',
                'account_name' => 'FB',
                'client_id' => 'fb-id',
                'client_secret' => 'fb-sec',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        Livewire::test(EditOAuthConfiguration::class, ['record' => $oauthRecord->getRouteKey()])
            ->fillForm(['account_name' => 'Updated Google'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->actingAs($regularUser);
        expect(OAuthConfigurationResource::canViewAny())->toBeFalse()
            ->and(OAuthConfigurationResource::getViewAnyAuthorizationResponse()->allowed())->toBeFalse()
            ->and(OAuthConfigurationResource::getCreateAuthorizationResponse()->allowed())->toBeFalse()
            ->and(OAuthConfigurationResource::getEditAuthorizationResponse($oauthRecord)->allowed())->toBeFalse()
            ->and(OAuthConfigurationResource::getViewAuthorizationResponse($oauthRecord)->allowed())->toBeFalse()
            ->and(OAuthConfigurationResource::getDeleteAuthorizationResponse($oauthRecord)->allowed())->toBeFalse()
            ->and(OAuthConfigurationResource::getDeleteAnyAuthorizationResponse()->allowed())->toBeFalse();

        // 3. SamlConnectionResource + Pages
        $this->actingAs($superAdmin);
        Filament::setTenant($team);
        SamlConnection::query()->delete();
        expect(SamlConnectionResource::canCreate())->toBeTrue()
            ->and(SamlConnectionResource::getCreateAuthorizationResponse()->allowed())->toBeTrue()
            ->and(SamlConnectionResource::form(FilamentSchema::make())->getComponents())->not->toBeEmpty()
            ->and(SamlConnectionResource::table($makeTable())->getColumns())->not->toBeEmpty()
            ->and(SamlConnectionResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

        Livewire::test(CreateSamlConnection::class)->assertOk();

        [, $validCert] = generateSamlKeypair();
        $samlValid = SamlConnection::create([
            'team_id' => $team->id,
            'idp_entity_id' => 'https://idp.example.com/entity',
            'idp_sso_url' => 'https://idp.example.com/sso',
            'idp_x509_cert' => $validCert,
            'enabled' => true,
        ]);
        expect(SamlConnectionResource::canCreate())->toBeFalse()
            ->and(SamlConnectionResource::getCreateAuthorizationResponse()->allowed())->toBeFalse();

        Livewire::test(EditSamlConnection::class, ['record' => $samlValid->getRouteKey()])->assertOk();
        Livewire::test(ListSamlConnections::class)
            ->callTableAction('validate', $samlValid)
            ->assertNotified('SAML configuration looks valid');

        $samlValid->update([
            'idp_entity_id' => '',
            'idp_sso_url' => 'http://insecure.example.com',
            'idp_x509_cert' => 'bad-raw-base64-cert',
            'enabled' => false,
        ]);
        Livewire::test(ListSamlConnections::class)
            ->callTableAction('validate', $samlValid)
            ->assertNotified('SAML configuration has problems');

        // 4. SsoConnectionResource + Pages
        SsoConnection::query()->delete();
        expect(SsoConnectionResource::canCreate())->toBeTrue()
            ->and(SsoConnectionResource::getCreateAuthorizationResponse()->allowed())->toBeTrue()
            ->and(SsoConnectionResource::form(FilamentSchema::make())->getComponents())->not->toBeEmpty()
            ->and(SsoConnectionResource::table($makeTable())->getColumns())->not->toBeEmpty()
            ->and(SsoConnectionResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

        Livewire::test(CreateSsoConnection::class)
            ->fillForm([
                'provider' => 'oidc',
                'client_id' => 'sso-cid',
                'client_secret' => 'sso-csec',
                'issuer_url' => 'https://8.8.8.8',
                'token_auth_method' => 'client_secret_post',
                'enabled' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ssoRecord = SsoConnection::firstOrFail();
        expect(SsoConnectionResource::canCreate())->toBeFalse()
            ->and(SsoConnectionResource::getCreateAuthorizationResponse()->allowed())->toBeFalse();

        Livewire::test(EditSsoConnection::class, ['record' => $ssoRecord->getRouteKey()])
            ->fillForm(['client_id' => 'sso-cid-updated'])
            ->call('save')
            ->assertHasNoFormErrors();

        Cache::flush();
        Http::swap(new HttpFactory());
        Http::fake([
            'https://8.8.8.8/.well-known/openid-configuration' => Http::sequence()
                ->push([
                    'authorization_endpoint' => 'https://8.8.8.8/authorize',
                    'token_endpoint' => 'https://8.8.8.8/token',
                    'userinfo_endpoint' => 'https://8.8.8.8/userinfo',
                ], 200)
                ->push('error', 500),
        ]);
        Livewire::test(ListSsoConnections::class)
            ->callTableAction('testConnection', $ssoRecord)
            ->assertNotified('Connection OK');

        Cache::flush();
        Livewire::test(ListSsoConnections::class)
            ->callTableAction('testConnection', $ssoRecord)
            ->assertNotified('Connection failed');

        // 5. WebhookDeliveryResource + Pages
        $wh = Webhook::create([
            'team_id' => $team->id,
            'user_id' => $superAdmin->id,
            'name' => 'WH',
            'url' => 'https://8.8.8.8/wh',
            'events' => ['contact.created'],
        ]);
        $whDelivery = WebhookDelivery::create([
            'webhook_id' => $wh->id,
            'team_id' => $team->id,
            'event' => 'contact.created',
            'success' => true,
            'status_code' => 200,
        ]);
        expect(WebhookDeliveryResource::canAccess())->toBeTrue()
            ->and(WebhookDeliveryResource::canCreate())->toBeFalse()
            ->and(WebhookDeliveryResource::getCreateAuthorizationResponse()->allowed())->toBeFalse()
            ->and(WebhookDeliveryResource::canEdit($whDelivery))->toBeFalse()
            ->and(WebhookDeliveryResource::getEditAuthorizationResponse($whDelivery)->allowed())->toBeFalse()
            ->and(WebhookDeliveryResource::canDelete($whDelivery))->toBeFalse()
            ->and(WebhookDeliveryResource::getDeleteAuthorizationResponse($whDelivery)->allowed())->toBeFalse()
            ->and(WebhookDeliveryResource::getEloquentQuery()->count())->toBe(1)
            ->and(WebhookDeliveryResource::table($makeTable())->getColumns())->not->toBeEmpty()
            ->and(WebhookDeliveryResource::getPages())->toHaveKey('index');

        $allowSub = new class() extends WebhookDeliveryResource
        {
            public static function canCreate(): bool
            {
                return true;
            }

            public static function canEdit(Model $record): bool
            {
                return true;
            }

            public static function canDelete(Model $record): bool
            {
                return true;
            }
        };
        expect($allowSub::getCreateAuthorizationResponse()->allowed())->toBeTrue()
            ->and($allowSub::getEditAuthorizationResponse($whDelivery)->allowed())->toBeTrue()
            ->and($allowSub::getDeleteAuthorizationResponse($whDelivery)->allowed())->toBeTrue();

        Livewire::test(ListWebhookDeliveries::class)->assertOk();

        // 6. WhatsAppNumberResource + Pages
        expect(WhatsAppNumberResource::permissionResource())->toBe('whatsapp_number')
            ->and(WhatsAppNumberResource::form(FilamentSchema::make())->getComponents())->not->toBeEmpty()
            ->and(WhatsAppNumberResource::table($makeTable())->getColumns())->not->toBeEmpty()
            ->and(WhatsAppNumberResource::getRelations())->toBe([])
            ->and(WhatsAppNumberResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

        $waNum = (new WhatsAppNumber([
            'number' => '+15559998888',
            'display_name' => 'Main WA',
            'is_active' => true,
        ]))->forceFill(['team_id' => $team->id]);
        $waNum->save();
        Livewire::test(ListWhatsAppNumbers::class)->assertOk();
        Livewire::test(CreateWhatsAppNumber::class)->assertOk();
        Livewire::test(EditWhatsAppNumber::class, ['record' => $waNum->getRouteKey()])->assertOk();
    });
}
