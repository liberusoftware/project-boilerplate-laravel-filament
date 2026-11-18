<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Services {
    class Pop3SocketMock
    {
        public static bool $failConnect = false;

        /** @var array<int, string> */
        public static array $readQueue = [];

        /** @var array<int, string> */
        public static array $written = [];

        public static bool $forceFopenFalse = false;

        public static ?string $fakeHostIp = null;
    }

    if (! function_exists(__NAMESPACE__.'\\fsockopen')) {
        function fsockopen(string $hostname, int $port = -1, &$error_code = null, &$error_message = null, ?float $timeout = null)
        {
            if (Pop3SocketMock::$failConnect) {
                $error_code = 111;
                $error_message = 'Connection refused';

                return false;
            }

            return \fopen('php://memory', 'r+');
        }
    }

    if (! function_exists(__NAMESPACE__.'\\fwrite')) {
        function fwrite($stream, string $data, ?int $length = null): int|false
        {
            Pop3SocketMock::$written[] = $data;

            return strlen($data);
        }
    }

    if (! function_exists(__NAMESPACE__.'\\fgets')) {
        function fgets($stream, ?int $length = null): string|false
        {
            if (Pop3SocketMock::$readQueue !== []) {
                return array_shift(Pop3SocketMock::$readQueue);
            }

            return ".\r\n";
        }
    }

    if (! function_exists(__NAMESPACE__.'\\substr')) {
        function substr(string $string, int $offset, ?int $length = null): string
        {
            if ($offset === 0 && $length === 3 && str_starts_with($string, '-ERR')) {
                return '-ERR';
            }

            return $length !== null ? \substr($string, $offset, $length) : \substr($string, $offset);
        }
    }

    if (! function_exists(__NAMESPACE__.'\\fclose')) {
        function fclose($stream): bool
        {
            if (is_resource($stream)) {
                return \fclose($stream);
            }

            return true;
        }
    }

    if (! function_exists(__NAMESPACE__.'\\fopen')) {
        function fopen(string $filename, string $mode, bool $use_include_path = false, $context = null)
        {
            if (Pop3SocketMock::$forceFopenFalse) {
                return false;
            }

            return $context !== null
                ? \fopen($filename, $mode, $use_include_path, $context)
                : \fopen($filename, $mode, $use_include_path);
        }
    }

    if (! function_exists(__NAMESPACE__.'\\gethostbyname')) {
        function gethostbyname(string $hostname): string
        {
            if (Pop3SocketMock::$fakeHostIp !== null) {
                return Pop3SocketMock::$fakeHostIp;
            }

            return \gethostbyname($hostname);
        }
    }

    if (! class_exists(QuickBooksService::class)) {
        class QuickBooksService
        {
            public function syncInvoice($integration, $invoice): array
            {
                return ['platform' => 'quickbooks', 'type' => 'invoice', 'data' => $invoice];
            }

            public function syncPayment($integration, $payment): array
            {
                return ['platform' => 'quickbooks', 'type' => 'payment', 'data' => $payment];
            }

            public function connect(array $credentials): array
            {
                return ['platform' => 'quickbooks', 'connected' => true, 'credentials' => $credentials];
            }
        }
    }

    if (! class_exists(XeroService::class)) {
        class XeroService
        {
            public function syncInvoice($integration, $invoice): array
            {
                return ['platform' => 'xero', 'type' => 'invoice', 'data' => $invoice];
            }

            public function syncPayment($integration, $payment): array
            {
                return ['platform' => 'xero', 'type' => 'payment', 'data' => $payment];
            }

            public function connect(array $credentials): array
            {
                return ['platform' => 'xero', 'connected' => true, 'credentials' => $credentials];
            }
        }
    }
}

namespace Tests\Feature {
    use Abraham\TwitterOAuth\TwitterOAuth;
    use App\Exceptions\AccountingIntegrationException;
    use App\Models\AdvertisingAccount;
    use App\Models\CallLog;
    use App\Models\ConnectedAccount;
    use App\Models\Task;
    use App\Models\User;
    use Carbon\Carbon;
    use Facebook\Exceptions\FacebookResponseException;
    use Facebook\Exceptions\FacebookSDKException;
    use Facebook\Facebook;
    use FacebookAds\Exception\FacebookException;
    use Google\Ads\GoogleAds\Lib\V14\GoogleAdsClientBuilder;
    use Google\Ads\GoogleAds\V14\Services\CampaignOperation;
    use Google\ApiCore\ApiException;
    use GuzzleHttp\Client as GuzzleClient;
    use GuzzleHttp\Exception\GuzzleException;
    use GuzzleHttp\Handler\MockHandler;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Psr7\Response as GuzzleResponse;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Http\Client\Factory;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Mail;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Facades\Schema;
    use Liberu\Foundation\Integrations\Models\AccountingIntegration;
    use Liberu\Foundation\Integrations\Models\OAuthConfiguration;
    use Liberu\Foundation\Integrations\Models\Webhook;
    use Liberu\Foundation\Integrations\Models\WebhookDelivery;
    use Liberu\Foundation\Integrations\Services\AccountingService;
    use Liberu\Foundation\Integrations\Services\FacebookAdsService;
    use Liberu\Foundation\Integrations\Services\FacebookMessengerService;
    use Liberu\Foundation\Integrations\Services\FacebookService;
    use Liberu\Foundation\Integrations\Services\GmailService;
    use Liberu\Foundation\Integrations\Services\GoogleAdsService;
    use Liberu\Foundation\Integrations\Services\GoogleCalendarService;
    use Liberu\Foundation\Integrations\Services\ImapService;
    use Liberu\Foundation\Integrations\Services\InstagramService;
    use Liberu\Foundation\Integrations\Services\LinkedInAdsService;
    use Liberu\Foundation\Integrations\Services\LinkedInService;
    use Liberu\Foundation\Integrations\Services\MailChimpService;
    use Liberu\Foundation\Integrations\Services\MessageService;
    use Liberu\Foundation\Integrations\Services\OutlookCalendarService;
    use Liberu\Foundation\Integrations\Services\OutlookService;
    use Liberu\Foundation\Integrations\Services\Pop3Service;
    use Liberu\Foundation\Integrations\Services\Pop3SocketMock;
    use Liberu\Foundation\Integrations\Services\QuickBooksService;
    use Liberu\Foundation\Integrations\Services\StripeService;
    use Liberu\Foundation\Integrations\Services\TwilioService;
    use Liberu\Foundation\Integrations\Services\TwitterService;
    use Liberu\Foundation\Integrations\Services\WebhookService;
    use Liberu\Foundation\Integrations\Services\WhatsAppBusinessService;
    use Liberu\Foundation\Integrations\Services\XeroService;
    use Liberu\Foundation\Integrations\Services\YouTubeService;
    use Liberu\Foundation\Organizations\Models\Team;
    use MailchimpMarketing\ApiClient;
    use Microsoft\Graph\Graph;
    use Microsoft\Graph\Model\Event as GraphEvent;
    use Mockery;
    use ReflectionClass;
    use Stripe\Exception\InvalidRequestException;
    use Stripe\StripeClient;
    use Twilio\Exceptions\TwilioException;
    use UnexpectedValueException;
    use Webklex\PHPIMAP\Attachment;
    use Webklex\PHPIMAP\Attribute;
    use Webklex\PHPIMAP\Message as ImapMessage;
    use Webklex\PHPIMAP\Support\AttachmentCollection;

    class IntegrationsAdAccount
    {
        public static array $items = [];

        public static ?\Throwable $throwOnGet = null;

        public function __construct(public mixed $id = null) {}

        public function getCampaigns(array $fields = [], array $params = []): array
        {
            if (self::$throwOnGet !== null) {
                $e = self::$throwOnGet;
                self::$throwOnGet = null;
                throw $e;
            }

            return self::$items;
        }
    }

    class IntegrationsPage
    {
        public static ?object $result = null;

        public static ?\Throwable $throwOnCreate = null;

        public function __construct(public mixed $id = null) {}

        public function createFeed(array $params = []): object
        {
            if (self::$throwOnCreate !== null) {
                $e = self::$throwOnCreate;
                self::$throwOnCreate = null;
                throw $e;
            }

            return self::$result ?? (object) ['id' => 'fb-id-1', 'post_id' => 'fb-post-1'];
        }
    }

    class IntegrationsCampaignOperation extends CampaignOperation
    {
        public function setRemove(mixed $campaignId): void {}
    }

    class IntegrationsGoogleClient extends \Google_Client
    {
        public function setApplicationName(mixed $name): void {}
    }

    class IntegrationsGoogleServiceGmail extends \Google_Service_Gmail
    {
        public static mixed $mockUsersMessages = null;

        public function __construct(mixed $client = null)
        {
            parent::__construct($client);
            $this->users_messages = self::$mockUsersMessages;
        }
    }

    class IntegrationsImapClient
    {
        public function connect(): static
        {
            return $this;
        }
    }

    class IntegrationsImapClientManager
    {
        public static mixed $mockClient = null;

        public function make(array $config = []): IntegrationsImapClient
        {
            return self::$mockClient;
        }
    }

    class IntegrationsSourceRewriteWrapper
    {
        public $context;

        private string $content = '';

        private int $pos = 0;

        /** @var array<string, array<string, string>> */
        public static array $rewrites = [
            'FacebookAdsService.php' => [
                'use FacebookAds\\Object\\AdAccount;' => 'use Tests\\Feature\\IntegrationsAdAccount as AdAccount;',
                'use FacebookAds\\Object\\Page;' => 'use Tests\\Feature\\IntegrationsPage as Page;',
            ],
            'GoogleAdsService.php' => [
                'use Google\\Ads\\GoogleAds\\V14\\Services\\CampaignOperation;' => 'use Tests\\Feature\\IntegrationsCampaignOperation as CampaignOperation;',
            ],
            'GmailService.php' => [
                'use Google_Client;' => 'use Tests\\Feature\\IntegrationsGoogleClient as Google_Client;',
                'use Google_Service_Gmail;' => 'use Tests\\Feature\\IntegrationsGoogleServiceGmail as Google_Service_Gmail;',
            ],
            'MessageService.php' => [
                'use Google_Client;' => 'use Tests\\Feature\\IntegrationsGoogleClient as Google_Client;',
                'use Google_Service_Gmail;' => 'use Tests\\Feature\\IntegrationsGoogleServiceGmail as Google_Service_Gmail;',
                'public function __construct(protected WhatsAppBusinessService $whatsappService, protected FacebookMessengerService $facebookMessengerService)' => 'public function __construct(protected $whatsappService, protected $facebookMessengerService)',
            ],
            'ImapService.php' => [
                'use Webklex\\PHPIMAP\\Client;' => 'use Tests\\Feature\\IntegrationsImapClient as Client;',
                'use Webklex\\PHPIMAP\\ClientManager;' => 'use Tests\\Feature\\IntegrationsImapClientManager as ClientManager;',
            ],
        ];

        public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
        {
            stream_wrapper_restore('file');
            $raw = \file_get_contents($path);
            if ($raw === false) {
                stream_wrapper_unregister('file');
                stream_wrapper_register('file', self::class);

                return false;
            }

            foreach (self::$rewrites as $suffix => $replacements) {
                if (str_ends_with($path, $suffix)) {
                    $raw = str_replace(array_keys($replacements), array_values($replacements), $raw);
                }
            }

            $this->content = $raw;
            $this->pos = 0;
            $opened_path = $path;

            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);

            return true;
        }

        public function stream_read(int $count): string
        {
            $chunk = substr($this->content, $this->pos, $count);
            $this->pos += strlen($chunk);

            return $chunk;
        }

        public function stream_eof(): bool
        {
            return $this->pos >= strlen($this->content);
        }

        public function stream_stat(): array
        {
            return ['size' => strlen($this->content)];
        }

        public function url_stat(string $path, int $flags): array|false
        {
            stream_wrapper_restore('file');
            $stat = @stat($path);
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);

            return $stat;
        }

        public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
        {
            return false;
        }
    }

    stream_wrapper_unregister('file');
    stream_wrapper_register('file', IntegrationsSourceRewriteWrapper::class);
    class_exists(FacebookAdsService::class);
    class_exists(GoogleAdsService::class);
    class_exists(GmailService::class);
    class_exists(MessageService::class);
    class_exists(ImapService::class);
    stream_wrapper_restore('file');

    afterEach(function (): void {
        Model::reguard();
    });

    beforeEach(function (): void {
        ConnectedAccount::unguard();

        if (! Schema::hasTable('tasks')) {
            Schema::create('tasks', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->text('description')->nullable();
                $table->dateTime('due_date')->nullable();
                $table->string('google_event_id')->nullable();
                $table->string('outlook_event_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('emails')) {
            Schema::create('emails', function (Blueprint $table): void {
                $table->id();
                $table->string('message_id')->nullable();
                $table->string('sender')->nullable();
                $table->string('recipient')->nullable();
                $table->string('subject')->nullable();
                $table->text('content')->nullable();
                $table->string('timestamp')->nullable();
                $table->boolean('is_sent')->default(false);
                $table->unsignedBigInteger('connected_account_id')->nullable();
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

        if (Schema::hasTable('teams') && ! Schema::hasColumn('teams', 'stripe_id')) {
            Schema::table('teams', function (Blueprint $table): void {
                $table->string('stripe_id')->nullable();
            });
        }

        if (! Schema::hasTable('team_subscriptions')) {
            Schema::create('team_subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->string('stripe_id')->nullable();
                $table->string('stripe_status')->nullable();
                $table->string('stripe_price')->nullable();
                $table->string('plan_key')->nullable();
                $table->integer('quantity')->default(1);
                $table->timestamp('trial_ends_at')->nullable();
                $table->timestamp('current_period_ends_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('webhooks')) {
            Schema::create('webhooks', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('url');
                $table->json('events')->nullable();
                $table->text('secret')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('team_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->integer('failure_count')->default(0);
                $table->timestamp('last_triggered_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('crm_webhook_deliveries')) {
            Schema::create('crm_webhook_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('webhook_id')->nullable();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->string('event')->nullable();
                $table->boolean('success')->default(false);
                $table->integer('status_code')->nullable();
                $table->text('error')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('connected_accounts')) {
            Schema::create('connected_accounts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('provider')->nullable();
                $table->string('account_type')->nullable();
                $table->string('provider_id')->nullable();
                $table->string('token')->nullable();
                $table->string('secret')->nullable();
                $table->boolean('is_primary')->default(false);
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('connected_accounts', function (Blueprint $table): void {
                if (! Schema::hasColumn('connected_accounts', 'account_type')) {
                    $table->string('account_type')->nullable();
                }
                if (! Schema::hasColumn('connected_accounts', 'is_primary')) {
                    $table->boolean('is_primary')->default(false);
                }
            });
        }
    });

    it('covers AccountingService and CalendarService', function (): void {
        $service = new AccountingService(new QuickBooksService(), new XeroService());

        $qb = new AccountingIntegration(['platform' => 'quickbooks']);
        $xero = new AccountingIntegration(['platform' => 'xero']);
        $other = new AccountingIntegration(['platform' => 'other']);

        expect($service->syncInvoice($qb, ['id' => 1])['platform'])->toBe('quickbooks')
            ->and($service->syncInvoice($xero, ['id' => 2])['platform'])->toBe('xero')
            ->and($service->syncPayment($qb, ['id' => 1])['platform'])->toBe('quickbooks')
            ->and($service->syncPayment($xero, ['id' => 2])['platform'])->toBe('xero')
            ->and($service->connectPlatform('quickbooks', ['key' => 'k'])['connected'])->toBeTrue()
            ->and($service->connectPlatform('xero', ['key' => 'k'])['connected'])->toBeTrue();

        expect(fn () => $service->syncInvoice($other, []))->toThrow(AccountingIntegrationException::class);
        expect(fn () => $service->syncPayment($other, []))->toThrow(AccountingIntegrationException::class);
        expect(fn () => $service->connectPlatform('invalid', []))->toThrow(AccountingIntegrationException::class);
    });

    it('covers FacebookAdsService', function (): void {
        $account = new AdvertisingAccount(['account_id' => '123', 'access_token' => 'fb-token']);
        $service = new FacebookAdsService($account);

        IntegrationsAdAccount::$items = [
            (object) ['id' => 'c1', 'name' => 'Camp 1', 'status' => 'ACTIVE'],
        ];

        $campaigns = $service->getCampaigns();
        expect($campaigns)->toHaveCount(1)
            ->and($campaigns[0]['id'])->toBe('c1');

        IntegrationsAdAccount::$throwOnGet = new FacebookException('FB Ads fail');
        expect(fn () => $service->getCampaigns())->toThrow(\Exception::class, 'Failed to fetch campaigns: FB Ads fail');

        IntegrationsPage::$result = (object) ['id' => 'p1', 'post_id' => 'p1_post'];
        $post = $service->createAndSchedulePost('page-1', [
            'message' => 'Hello',
            'scheduled_time' => 1700000000,
            'link' => 'https://example.com',
            'image' => 'https://example.com/img.png',
        ]);
        expect($post)->toBe(['id' => 'p1', 'post_id' => 'p1_post']);

        IntegrationsPage::$throwOnCreate = new FacebookException('FB Page fail');
        expect(fn () => $service->createAndSchedulePost('page-1', [
            'message' => 'Hello',
            'scheduled_time' => 1700000000,
        ]))->toThrow(\Exception::class, 'Failed to create and schedule post: FB Page fail');
    });

    it('covers FacebookMessengerService and FacebookService', function (): void {
        // Cover lazy default instantiation when app(Facebook::class) returns null
        app()->bind(Facebook::class, fn () => null);
        $lazyService = new FacebookMessengerService();
        $ref = new ReflectionClass($lazyService);
        $getFb = $ref->getMethod('getFacebook');
        $getFb->setAccessible(true);
        expect($getFb->invoke($lazyService))->toBeInstanceOf(Facebook::class);

        $fbMock = Mockery::mock(Facebook::class);
        app()->instance(Facebook::class, $fbMock);

        $messenger = new FacebookMessengerService();
        $account = ConnectedAccount::create([
            'user_id' => 1,
            'provider' => 'facebook',
            'account_type' => 'facebook',
            'provider_id' => 'page_99',
            'token' => 'page_tok',
            'is_primary' => true,
        ]);

        $fbMock->shouldReceive('setDefaultAccessToken')->with('page_tok');
        $edgeResponse = Mockery::mock();
        $edgeResponse->shouldReceive('getGraphEdge')->andReturn([
            (object) [
                'messages' => [
                    (object) [
                        'id' => 'm1',
                        'from' => ['name' => 'Alice'],
                        'message' => 'Hi there',
                        'created_time' => '2026-01-01T00:00:00Z',
                    ],
                ],
            ],
        ]);
        $fbMock->shouldReceive('get')->andReturn($edgeResponse)->twice();

        expect($messenger->getUnreadMessages())->toHaveCount(1)
            ->and($messenger->getUnreadMessages($account)[0]['account_id'])->toBe($account->id);

        $nodeResponse = Mockery::mock();
        $nodeResponse->shouldReceive('getGraphNode')->andReturn((object) [
            'id' => 'm1',
            'from' => (object) ['name' => 'Bob'],
            'message' => 'Single',
            'created_time' => '2026-01-01T00:00:00Z',
        ]);
        $fbMock->shouldReceive('get')->andReturn($nodeResponse);
        expect($messenger->getMessage('m1', $account)['from'])->toBe('Bob');

        $fbMock->shouldReceive('post')->andReturn($nodeResponse);
        expect($messenger->sendReply('rec1', 'Reply text', $account))->toBeObject();

        expect($messenger->getAllConnectedAccounts())->toHaveCount(1)
            ->and($messenger->getPrimaryAccount()?->id)->toBe($account->id);

        // FacebookService
        config([
            'services.facebook.app_id' => 'id',
            'services.facebook.app_secret' => 'sec',
            'services.facebook.graph_version' => 'v18.0',
            'services.facebook.page_access_token' => 'ptok',
            'services.facebook.page_id' => 'pid',
        ]);
        $fbService = new FacebookService();
        $fbProp = new ReflectionClass($fbService)->getProperty('fb');
        $fbProp->setAccessible(true);

        $customFb = Mockery::mock(Facebook::class);
        $fbProp->setValue($fbService, $customFb);

        $postResp = Mockery::mock();
        $postResp->shouldReceive('getGraphNode')->andReturn((object) ['id' => 'p1']);
        $customFb->shouldReceive('post')->once()->andReturn($postResp);
        expect($fbService->publishPost('Hello'))->toEqual((object) ['id' => 'p1']);

        $customFb->shouldReceive('post')->once()->andThrow(new FacebookResponseException('resp err'));
        expect(fn () => $fbService->publishPost('Hello'))->toThrow(\Exception::class, 'Graph returned an error: resp err');

        $customFb->shouldReceive('post')->once()->andThrow(new FacebookSDKException('sdk err'));
        expect(fn () => $fbService->publishPost('Hello'))->toThrow(\Exception::class, 'Facebook SDK returned an error: sdk err');

        $insightResp = Mockery::mock();
        $insightResp->shouldReceive('getGraphEdge')->andReturn(['impressions' => 10]);
        $customFb->shouldReceive('get')->once()->andReturn($insightResp);
        expect($fbService->getPostInsights('p1'))->toBe(['impressions' => 10]);

        $customFb->shouldReceive('get')->once()->andThrow(new FacebookResponseException('resp err'));
        expect(fn () => $fbService->getPostInsights('p1'))->toThrow(\Exception::class, 'Graph returned an error: resp err');

        $customFb->shouldReceive('get')->once()->andThrow(new FacebookSDKException('sdk err'));
        expect(fn () => $fbService->getPostInsights('p1'))->toThrow(\Exception::class, 'Facebook SDK returned an error: sdk err');
    });

    it('covers GmailService and MessageService', function (): void {
        $makeHeader = fn (string $name, string $value) => new class($name, $value)
        {
            public function __construct(private string $n, private string $v) {}

            public function getName(): string
            {
                return $this->n;
            }

            public function getValue(): string
            {
                return $this->v;
            }
        };

        $makeGmailMessage = function (string $id, string $mode = 'body') use ($makeHeader): \Google_Service_Gmail_Message {
            $msg = new \Google_Service_Gmail_Message();
            $msg->setId($id);
            $msg->setThreadId('th-1');

            if ($mode === 'null_payload') {
                $msg->setPayload(null);

                return $msg;
            }

            $headers = [
                $makeHeader('From', 'sender@example.com'),
                $makeHeader('To', 'rcpt@example.com'),
                $makeHeader('Subject', 'Hello Subj'),
                $makeHeader('Date', '2026-01-01 10:00:00'),
                $makeHeader('Cc', 'cc1@example.com,cc2@example.com'),
                $makeHeader('Bcc', 'bcc1@example.com'),
            ];

            $payload = new class($headers, $mode)
            {
                public function __construct(private array $headers, private string $mode) {}

                public function getHeaders(): array
                {
                    return $this->headers;
                }

                public function getBody(): ?object
                {
                    if ($this->mode === 'body') {
                        return new class()
                        {
                            public function getData(): string
                            {
                                return strtr(base64_encode('Body text'), '+/', '-_');
                            }
                        };
                    }

                    return null;
                }

                public function getParts(): array
                {
                    if ($this->mode === 'parts') {
                        return [
                            [
                                'mimeType' => 'text/plain',
                                'body' => ['data' => strtr(base64_encode('Parts text'), '+/', '-_')],
                            ],
                        ];
                    }

                    if ($this->mode === 'empty_parts') {
                        return [
                            ['mimeType' => 'text/html', 'body' => ['data' => '']],
                        ];
                    }

                    return [];
                }
            };

            $msg->setPayload($payload);

            return $msg;
        };

        $msgBody = $makeGmailMessage('g1', 'body');
        $msgParts = $makeGmailMessage('g2', 'parts');
        $msgNullPayload = $makeGmailMessage('g3', 'null_payload');
        $msgEmptyParts = $makeGmailMessage('g4', 'empty_parts');

        $usersMessages = Mockery::mock();
        IntegrationsGoogleServiceGmail::$mockUsersMessages = $usersMessages;

        $listResponse = new class([$msgBody, $msgParts])
        {
            public function __construct(private array $msgs) {}

            public function getMessages(): array
            {
                return $this->msgs;
            }
        };

        $usersMessages->shouldReceive('listUsersMessages')->andReturn($listResponse);
        $usersMessages->shouldReceive('get')->with('me', 'g1')->andReturn($msgBody);
        $usersMessages->shouldReceive('get')->with('me', 'g2')->andReturn($msgParts);
        $usersMessages->shouldReceive('get')->with('me', 'g3')->andReturn($msgNullPayload);
        $usersMessages->shouldReceive('get')->with('me', 'g4')->andReturn($msgEmptyParts);
        $usersMessages->shouldReceive('send')->andReturn($msgBody);

        $gmail = new GmailService();
        $oauthConfig = new OAuthConfiguration(['additional_settings' => ['access_token' => 'tok']]);

        // Unread with config + without config
        expect($gmail->getUnreadMessages($oauthConfig))->toHaveCount(2)
            ->and($gmail->getUnreadMessages(null))->toHaveCount(2);

        // getMessage & sendReply (covering all getEmailContent branches)
        $gmailGetEmailContent = new ReflectionClass($gmail)->getMethod('getEmailContent');
        $gmailGetEmailContent->setAccessible(true);
        expect($gmailGetEmailContent->invoke($gmail, $msgNullPayload))->toBe('')
            ->and($gmail->getMessage('g1', $oauthConfig)->getId())->toBe('g1')
            ->and($gmail->getMessage('g4', $oauthConfig)->getId())->toBe('g4')
            ->and($gmail->sendReply('g1', 'My reply', $oauthConfig)->getId())->toBe('g1');

        // Exception branch in getUnreadMessages
        $failingUsersMessages = Mockery::mock();
        $failingUsersMessages->shouldReceive('listUsersMessages')->andThrow(new \RuntimeException('Gmail API down'));
        IntegrationsGoogleServiceGmail::$mockUsersMessages = $failingUsersMessages;
        expect(fn () => $gmail->getUnreadMessages($oauthConfig))->toThrow(\RuntimeException::class, 'Gmail API down');

        // MessageService
        IntegrationsGoogleServiceGmail::$mockUsersMessages = $usersMessages;

        $gmailAcc = ConnectedAccount::create([
            'user_id' => 1,
            'provider' => 'gmail',
            'account_type' => 'gmail',
            'provider_id' => 'gm_1',
            'token' => 'gm_tok',
        ]);
        $waAcc = ConnectedAccount::create([
            'user_id' => 1,
            'provider' => 'whatsapp',
            'account_type' => 'whatsapp',
            'provider_id' => 'wa_1',
            'token' => 'wa_tok',
        ]);
        $fbAcc = ConnectedAccount::create([
            'user_id' => 1,
            'provider' => 'facebook',
            'account_type' => 'facebook',
            'provider_id' => 'fb_1',
            'token' => 'fb_tok',
        ]);

        $waMock = Mockery::mock();
        $waMock->shouldReceive('getUnreadMessages')->andReturn(['wa-unread']);
        $waMock->shouldReceive('getMessage')->andReturn(['id' => 'wa-msg']);
        $waMock->shouldReceive('sendReply')->andReturn(['id' => 'wa-reply']);

        $fbMessengerMock = Mockery::mock();
        $fbMessengerMock->shouldReceive('getUnreadMessages')->andReturn(['fb-unread']);
        $fbMessengerMock->shouldReceive('getMessage')->andReturn(['id' => 'fb-msg']);
        $fbMessengerMock->shouldReceive('sendReply')->andReturn(['id' => 'fb-reply']);

        $messageService = new MessageService($waMock, $fbMessengerMock);
        $allUnread = $messageService->getUnreadMessages();
        expect($allUnread['email'])->toHaveCount(2)
            ->and($allUnread['whatsapp'])->toContain('wa-unread')
            ->and($allUnread['facebook'])->toContain('fb-unread');

        $msgGetEmailContent = new ReflectionClass($messageService)->getMethod('getEmailContent');
        $msgGetEmailContent->setAccessible(true);
        expect($msgGetEmailContent->invoke($messageService, $msgNullPayload))->toBe('')
            ->and($messageService->getMessage('g1', 'email', $gmailAcc->id)->getId())->toBe('g1')
            ->and($messageService->getMessage('g2', 'email', $gmailAcc->id)->getId())->toBe('g2')
            ->and($messageService->getMessage('g4', 'email', $gmailAcc->id)->getId())->toBe('g4')
            ->and($messageService->getMessage('w1', 'whatsapp', $waAcc->id))->toBe(['id' => 'wa-msg'])
            ->and($messageService->getMessage('f1', 'facebook', $fbAcc->id))->toBe(['id' => 'fb-msg']);

        expect(fn () => $messageService->getMessage('x', 'unknown', $gmailAcc->id))
            ->toThrow(\InvalidArgumentException::class, 'Invalid message type: unknown');

        expect($messageService->sendReply('g1', 'Reply body', 'email', $gmailAcc->id)->getId())->toBe('g1')
            ->and($messageService->sendReply('w1', 'Reply body', 'whatsapp', $waAcc->id))->toBe(['id' => 'wa-reply'])
            ->and($messageService->sendReply('f1', 'Reply body', 'facebook', $fbAcc->id))->toBe(['id' => 'fb-reply']);

        expect(fn () => $messageService->sendReply('x', 'Reply', 'unknown', $gmailAcc->id))
            ->toThrow(\InvalidArgumentException::class, 'Invalid message type: unknown');
    });

    it('covers GoogleAdsService', function (): void {
        ConnectedAccount::query()->delete();
        $acc = ConnectedAccount::create([
            'user_id' => 1,
            'provider' => 'google_ads',
            'account_type' => 'google_ads',
            'provider_id' => 'cust-123',
            'token' => 'ref-tok',
            'is_primary' => true,
        ]);

        $mockClient = Mockery::mock();
        GoogleAdsClientBuilder::$mockClient = $mockClient;

        $service = new GoogleAdsService();

        expect($service->getAllConnectedAccounts())->toHaveCount(1)
            ->and($service->getPrimaryAccount()?->id)->toBe($acc->id);

        // Not found branches
        expect(fn () => $service->getCampaigns(999999))->toThrow(\Exception::class, 'Google Ads account not found');
        expect(fn () => $service->createCampaign(999999, ['name' => 'X']))->toThrow(\Exception::class, 'Google Ads account not found');
        expect(fn () => $service->updateCampaign(999999, 'c1', ['name' => 'X', 'status' => 2]))->toThrow(\Exception::class, 'Google Ads account not found');
        expect(fn () => $service->deleteCampaign(999999, 'c1'))->toThrow(\Exception::class, 'Google Ads account not found');

        // getCampaigns success
        $mockClient->shouldReceive('getLoginCustomerId')->andReturn('cust-123');
        $customerService = Mockery::mock();
        $customer = Mockery::mock();
        $customer->shouldReceive('getResourceName')->andReturn('customers/123');
        $customerService->shouldReceive('getCustomer')->with('cust-123')->andReturn($customer);
        $mockClient->shouldReceive('getCustomerService')->andReturn($customerService);

        $row = new class()
        {
            public function getCampaign(): object
            {
                return new class()
                {
                    public function getId(): string
                    {
                        return 'camp-1';
                    }

                    public function getName(): string
                    {
                        return 'Campaign One';
                    }

                    public function getStatus(): int
                    {
                        return 2;
                    }
                };
            }
        };
        $stream = new class([$row])
        {
            public function __construct(private array $rows) {}

            public function iterateAllElements(): array
            {
                return $this->rows;
            }
        };
        $adsServiceClient = Mockery::mock();
        $adsServiceClient->shouldReceive('search')->once()->andReturn($stream);
        $mockClient->shouldReceive('getGoogleAdsServiceClient')->andReturn($adsServiceClient);

        $campaigns = $service->getCampaigns($acc->id);
        expect($campaigns)->toHaveCount(1)
            ->and($campaigns[0]['id'])->toBe('camp-1');

        // getCampaigns ApiException
        $adsServiceClient->shouldReceive('search')->once()->andThrow(new ApiException('search error'));
        expect(fn () => $service->getCampaigns($acc->id))->toThrow(\Exception::class, 'Failed to fetch campaigns: search error');

        // create, update, delete Campaign success + ApiException
        $mutateResult = new class()
        {
            public function getResults(): array
            {
                return [
                    new class()
                    {
                        public function getResourceName(): string
                        {
                            return 'customers/123/campaigns/1';
                        }
                    },
                ];
            }
        };
        $campaignServiceClient = Mockery::mock();
        $mockClient->shouldReceive('getCampaignServiceClient')->andReturn($campaignServiceClient);

        $campaignServiceClient->shouldReceive('mutateCampaigns')->times(3)->andReturn($mutateResult);
        expect($service->createCampaign($acc->id, ['name' => 'New']))->toBe('customers/123/campaigns/1')
            ->and($service->updateCampaign($acc->id, 'customers/123/campaigns/1', ['name' => 'Upd', 'status' => 2]))->toBe('customers/123/campaigns/1')
            ->and($service->deleteCampaign($acc->id, 'customers/123/campaigns/1'))->toBe('customers/123/campaigns/1');

        $campaignServiceClient->shouldReceive('mutateCampaigns')->times(3)->andThrow(new ApiException('mutate error'));
        expect(fn () => $service->createCampaign($acc->id, ['name' => 'New']))->toThrow(\Exception::class, 'Failed to create campaign: mutate error');
        expect(fn () => $service->updateCampaign($acc->id, 'c1', ['name' => 'Upd', 'status' => 2]))->toThrow(\Exception::class, 'Failed to update campaign: mutate error');
        expect(fn () => $service->deleteCampaign($acc->id, 'c1'))->toThrow(\Exception::class, 'Failed to delete campaign: mutate error');
    });

    it('covers GoogleCalendarService and OutlookCalendarService', function (): void {
        // GoogleCalendarService lazy init
        $gcal = new GoogleCalendarService();
        $ref = new ReflectionClass($gcal);
        $getService = $ref->getMethod('getService');
        $getService->setAccessible(true);
        expect($getService->invoke($gcal))->toBeInstanceOf(\Google_Service_Calendar::class);

        $eventsMock = Mockery::mock();
        $gcal->service->events = $eventsMock;

        $task = Task::create([
            'name' => 'Task 1',
            'description' => 'Desc 1',
            'due_date' => now(),
        ]);

        $eventsMock->shouldReceive('insert')->once()->andReturn((object) ['id' => 'ge-1']);
        $gcal->createEvent($task);
        expect($task->google_event_id)->toBe('ge-1');

        $existingGEvent = new \Google_Service_Calendar_Event(['id' => 'ge-1']);
        $eventsMock->shouldReceive('get')->once()->with('primary', 'ge-1')->andReturn($existingGEvent);
        $eventsMock->shouldReceive('update')->once()->with('primary', 'ge-1', $existingGEvent)->andReturn($existingGEvent);
        $gcal->updateEvent($task);

        $eventsMock->shouldReceive('listEvents')->once()->andReturn(new \Google_Service_Calendar_Events(['items' => [$existingGEvent]]));
        expect($gcal->fetchEvents())->toHaveCount(1);

        $syncEventExisting = new \Google_Service_Calendar_Event([
            'id' => 'ge-1',
            'summary' => 'Updated Task 1',
            'description' => 'Updated Desc 1',
            'start' => new class()
            {
                public function getDateTime(): string
                {
                    return '2026-02-01 12:00:00';
                }
            },
        ]);
        $syncEventNew = new \Google_Service_Calendar_Event([
            'id' => 'ge-2',
            'summary' => 'New Task 2',
            'description' => 'New Desc 2',
            'start' => new class()
            {
                public function getDateTime(): string
                {
                    return '2026-02-02 12:00:00';
                }
            },
        ]);
        $gcal->syncEvents([$syncEventExisting, $syncEventNew]);
        expect($task->fresh()->name)->toBe('Updated Task 1')
            ->and(Task::where('google_event_id', 'ge-2')->exists())->toBeTrue();

        $eventsMock->shouldReceive('delete')->once()->with('primary', 'ge-1');
        $gcal->deleteEvent($task);
        expect($task->google_event_id)->toBeNull();

        // OutlookCalendarService lazy init via deleteEvent (uses default Graph stub!)
        $ocal = new OutlookCalendarService();
        $task->outlook_event_id = 'oe-0';
        $task->save();
        $ocal->deleteEvent($task);
        expect($task->outlook_event_id)->toBeNull();

        $mockGraph = Mockery::mock();
        $ocal->graph = $mockGraph;

        $createReq = Mockery::mock();
        $createReq->shouldReceive('attachBody')->andReturnSelf();
        $createReq->shouldReceive('setReturnType')->andReturnSelf();
        $createReq->shouldReceive('execute')->andReturn(new GraphEvent(['id' => 'oe-1']));
        $mockGraph->shouldReceive('createRequest')->with('POST', '/me/events')->andReturn($createReq);
        $ocal->createEvent($task);
        expect($task->outlook_event_id)->toBe('oe-1');

        $patchReq = Mockery::mock();
        $patchReq->shouldReceive('attachBody')->andReturnSelf();
        $patchReq->shouldReceive('execute')->andReturn(null);
        $mockGraph->shouldReceive('createRequest')->with('PATCH', '/me/events/oe-1')->andReturn($patchReq);
        $ocal->updateEvent($task);

        $getReq = Mockery::mock();
        $getReq->shouldReceive('setReturnType')->andReturnSelf();
        $getReq->shouldReceive('execute')->andReturn([new GraphEvent(['id' => 'oe-1'])]);
        $mockGraph->shouldReceive('createRequest')->with('GET', Mockery::type('string'))->andReturn($getReq);
        expect($ocal->fetchEvents())->toHaveCount(1);

        $oEventExisting = new GraphEvent([
            'id' => 'oe-1',
            'subject' => 'Outlook Updated 1',
            'body' => ['content' => 'Body 1'],
            'start' => ['dateTime' => '2026-03-01T10:00:00'],
        ]);
        $oEventNew = new GraphEvent([
            'id' => 'oe-2',
            'subject' => 'Outlook New 2',
            'body' => ['content' => 'Body 2'],
            'start' => ['dateTime' => '2026-03-02T10:00:00'],
        ]);
        $ocal->syncEvents([$oEventExisting, $oEventNew]);
        expect($task->fresh()->name)->toBe('Outlook Updated 1')
            ->and(Task::where('outlook_event_id', 'oe-2')->exists())->toBeTrue();
    });

    it('covers ImapService', function (): void {
        Mail::shouldReceive('raw')->andReturnUsing(function ($content, $callback): void {
            $msg = new class()
            {
                public function to($to): static
                {
                    return $this;
                }

                public function subject($subject): static
                {
                    return $this;
                }

                public function from($from): static
                {
                    return $this;
                }
            };
            $callback($msg);
        })->byDefault();
        $imap = new ImapService();

        // Missing host
        $emptyConfig = new OAuthConfiguration(['additional_settings' => []]);
        expect(fn () => $imap->getUnreadMessages($emptyConfig))->toThrow(\Exception::class, 'IMAP host is not configured');

        $makeImapMessage = function (): ImapMessage {
            $msg = Mockery::mock(ImapMessage::class);
            $fromCol = new Attribute('from', [(object) ['mail' => 'from@imap.test']]);
            $ccCol = new Attribute('cc', [(object) ['mail' => 'cc@imap.test']]);
            $att = Mockery::mock(Attachment::class);
            $att->shouldReceive('getName')->andReturn('file.pdf');
            $att->shouldReceive('getSize')->andReturn(123);
            $attCol = new AttachmentCollection([$att]);
            $strVal = fn (string $v) => new Attribute('str', [$v]);
            $dateAttr = new Attribute('date', [Carbon::parse('2026-01-01 12:00:00')]);

            $msg->shouldReceive('getFrom')->andReturn($fromCol);
            $msg->shouldReceive('getCc')->andReturn($ccCol);
            $msg->shouldReceive('getAttachments')->andReturn($attCol);
            $msg->shouldReceive('getDate')->andReturn($dateAttr);
            $msg->shouldReceive('getUid')->andReturn('u-1');
            $msg->shouldReceive('getSubject')->andReturn($strVal('Test Subj'));
            $msg->shouldReceive('getTextBody')->andReturn('Plain body');
            $msg->shouldReceive('getMessageId')->andReturn($strVal('mid-1'));

            return $msg;
        };

        $imapMsg = $makeImapMessage();
        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('whereUnseen')->andReturnSelf();
        $queryMock->shouldReceive('get')->andReturn([$imapMsg]);
        $queryMock->shouldReceive('getMessageByUid')->with('u-1')->andReturn($imapMsg);
        $queryMock->shouldReceive('getMessageByUid')->with('missing')->andReturn(null);

        $folderMock = Mockery::mock();
        $folderMock->shouldReceive('messages')->andReturn($queryMock);

        $clientMock = Mockery::mock(IntegrationsImapClient::class)->makePartial();
        $clientMock->shouldReceive('getFolder')->with('INBOX')->andReturn($folderMock);
        IntegrationsImapClientManager::$mockClient = $clientMock;

        $sslConfig = new OAuthConfiguration(['additional_settings' => ['host' => 'imap.test', 'ssl' => 'ssl', 'username' => 'u@imap.test']]);
        $tlsConfig = new OAuthConfiguration(['additional_settings' => ['host' => 'imap.test', 'ssl' => 'tls', 'username' => 'u@imap.test']]);
        $plainConfig = new OAuthConfiguration(['additional_settings' => ['host' => 'imap.test', 'ssl' => false, 'username' => 'u@imap.test']]);

        expect($imap->getUnreadMessages($sslConfig))->toHaveCount(1)
            ->and($imap->getMessage('u-1', $tlsConfig)['id'])->toBe('u-1')
            ->and($imap->getMessage('missing', $plainConfig))->toBeNull()
            ->and($imap->sendReply('u-1', 'Reply body', $sslConfig))->toBe(['success' => true])
            ->and($imap->sendMessage('to@imap.test', 'Subj', 'Body', $sslConfig))->toBe(['success' => true]);

        expect(fn () => $imap->sendReply('missing', 'Reply', $sslConfig))->toThrow(\Exception::class, 'Message not found: missing');
        expect(fn () => $imap->getMessage('u-1', $emptyConfig))->toThrow(\Exception::class, 'IMAP host is not configured');

        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('SMTP down'));
        expect(fn () => $imap->sendMessage('to@imap.test', 'Subj', 'Body', $sslConfig))->toThrow(\RuntimeException::class, 'SMTP down');
    });

    it('covers InstagramService, LinkedInService, LinkedInAdsService, and TwitterService', function (): void {
        Http::fake([
            'graph.instagram.com/*' => Http::response(['data' => [['id' => 'ig-1']], 'id' => 'media-obj-1'], 200),
            'api.linkedin.com/*' => Http::response(['id' => 'li-1'], 200),
        ]);

        ConnectedAccount::query()->delete();
        $igAcc = ConnectedAccount::create([
            'user_id' => 1,
            'provider' => 'instagram',
            'account_type' => 'instagram',
            'provider_id' => 'ig_u1',
            'token' => 'ig_tok',
            'is_primary' => true,
        ]);
        $ig = new InstagramService();
        expect($ig->getRecentMedia($igAcc))->toBe([['id' => 'ig-1']])
            ->and($ig->postMedia($igAcc, 'https://example.com/a.jpg', 'Cap')['id'])->toBe('media-obj-1')
            ->and($ig->getAllConnectedAccounts())->toHaveCount(1)
            ->and($ig->getPrimaryAccount()?->id)->toBe($igAcc->id);

        $liAcc = ConnectedAccount::create([
            'user_id' => 1,
            'provider' => 'linkedin',
            'account_type' => 'linkedin',
            'provider_id' => 'li_u1',
            'token' => 'li_tok',
            'is_primary' => true,
        ]);
        $li = new LinkedInService();
        expect($li->getProfile($liAcc)['id'])->toBe('li-1')
            ->and($li->sharePost($liAcc, 'Hello LinkedIn')['id'])->toBe('li-1')
            ->and($li->getAllConnectedAccounts())->toHaveCount(1)
            ->and($li->getPrimaryAccount()?->id)->toBe($liAcc->id);

        // LinkedInAdsService
        $adAcc = new AdvertisingAccount(['account_id' => 'li-ad-1', 'access_token' => 'tok']);
        $liAds = new LinkedInAdsService($adAcc);

        $mockHandler = new MockHandler([
            new GuzzleResponse(200, [], json_encode(['elements' => [['id' => 'c1', 'name' => 'Camp', 'status' => 'ACTIVE']]])),
            new GuzzleResponse(201, [], json_encode(['id' => 'ugc-1'])),
            new class('Guzzle get fail') extends \RuntimeException implements GuzzleException {},
            new class('Guzzle post fail') extends \RuntimeException implements GuzzleException {},
        ]);
        $guzzle = new GuzzleClient(['handler' => HandlerStack::create($mockHandler)]);
        $clientProp = new ReflectionClass($liAds)->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($liAds, $guzzle);

        expect($liAds->getCampaigns())->toBe([['id' => 'c1', 'name' => 'Camp', 'status' => 'ACTIVE']])
            ->and($liAds->createAndSchedulePost('org-1', [
                'message' => 'Msg',
                'scheduled_time' => 1700000000,
                'link' => 'https://example.com',
            ]))->toBe(['id' => 'ugc-1']);

        expect(fn () => $liAds->getCampaigns())->toThrow(\Exception::class, 'Failed to fetch campaigns: Guzzle get fail');
        expect(fn () => $liAds->createAndSchedulePost('org-1', ['message' => 'Msg']))->toThrow(\Exception::class, 'Failed to create and schedule post: Guzzle post fail');

        // TwitterService
        $twAcc = ConnectedAccount::create([
            'user_id' => 1,
            'provider' => 'twitter',
            'account_type' => 'twitter',
            'provider_id' => 'tw_1',
            'token' => 'tw_tok',
            'secret' => 'tw_sec',
            'is_primary' => true,
        ]);
        $twitter = new TwitterService();
        $twConn = new class() extends TwitterOAuth
        {
            public function setOauthToken(string $token, string $secret): void {}
        };
        $connProp = new ReflectionClass($twitter)->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($twitter, $twConn);

        expect($twitter->getTimeline($twAcc))->toBeArray()
            ->and($twitter->postTweet($twAcc, 'Hello X'))->toBe(['id' => 'tweet-1'])
            ->and($twitter->getAllConnectedAccounts())->toHaveCount(1)
            ->and($twitter->getPrimaryAccount()?->id)->toBe($twAcc->id);
    });

    it('covers MailChimpService', function (): void {
        // Unconfigured branches
        config(['services.mailchimp.api_key' => '', 'services.mailchimp.server_prefix' => '']);
        $mc = new MailChimpService();
        expect($mc->isConfigured())->toBeFalse()
            ->and($mc->getLists())->toBe([])
            ->and($mc->createList('L', 'C', 'Rem', 'From', 'from@example.com'))->toBeNull()
            ->and($mc->addMember('l1', 'a@b.com'))->toBeNull()
            ->and($mc->createCampaign('l1', 'Sub', 'From', 'r@b.com', '<p>Hi</p>'))->toBeNull()
            ->and($mc->createABTestCampaign('l1', 'A', 'B', 'From', 'r@b.com', '<p>A</p>', '<p>B</p>'))->toBeNull()
            ->and($mc->sendCampaign('c1'))->toBeNull()
            ->and($mc->getCampaigns())->toBe([])
            ->and($mc->getCampaignReport('c1'))->toBe([])
            ->and($mc->getABTestResults('c1'))->toBe([]);

        // Configured branches
        config(['services.mailchimp.api_key' => 'mc-key', 'services.mailchimp.server_prefix' => 'us1']);
        $apiMock = Mockery::mock(ApiClient::class);
        $apiMock->lists = Mockery::mock();
        $apiMock->campaigns = Mockery::mock();
        $apiMock->reports = Mockery::mock();
        $mc->setClient($apiMock);

        $apiMock->lists->shouldReceive('getAllLists')->once()->andReturn((object) [
            'lists' => [(object) ['id' => 'l1', 'name' => 'Main List']],
        ]);
        expect($mc->getLists())->toBe(['l1' => 'Main List']);

        $apiMock->lists->shouldReceive('createList')->once()->andReturn((object) ['id' => 'l2']);
        expect($mc->createList('L', 'C', 'Rem', 'From', 'from@example.com')->id)->toBe('l2');

        $apiMock->lists->shouldReceive('addListMember')->once()->andReturn((object) ['id' => 'm1']);
        expect($mc->addMember('l1', 'a@b.com')->id)->toBe('m1');

        $apiMock->campaigns->shouldReceive('create')->once()->andReturn((object) ['id' => 'c1']);
        $apiMock->campaigns->shouldReceive('setContent')->once()->with('c1', ['html' => '<p>Hi</p>']);
        expect($mc->createCampaign('l1', 'Sub', 'From', 'r@b.com', '<p>Hi</p>')->id)->toBe('c1');

        $apiMock->campaigns->shouldReceive('create')->once()->andReturn((object) ['id' => '']);
        expect(fn () => $mc->createCampaign('l1', 'Sub', 'From', 'r@b.com', '<p>Hi</p>'))
            ->toThrow(UnexpectedValueException::class);

        $apiMock->campaigns->shouldReceive('create')->once()->andReturn((object) ['id' => 'ab1']);
        $apiMock->campaigns->shouldReceive('setContent')->once()->with('ab1', ['html' => '<p>A</p>']);
        $apiMock->campaigns->shouldReceive('updateContentAB')->once()->with('ab1', ['html' => '<p>B</p>']);
        expect($mc->createABTestCampaign('l1', 'A', 'B', 'From', 'r@b.com', '<p>A</p>', '<p>B</p>')->id)->toBe('ab1');

        $apiMock->campaigns->shouldReceive('create')->once()->andReturn((object) ['id' => '']);
        expect(fn () => $mc->createABTestCampaign('l1', 'A', 'B', 'From', 'r@b.com', '<p>A</p>', '<p>B</p>'))
            ->toThrow(UnexpectedValueException::class);

        $apiMock->campaigns->shouldReceive('send')->once()->with('c1')->andReturn(true);
        expect($mc->sendCampaign('c1'))->toBeTrue();

        $apiMock->campaigns->shouldReceive('list')->once()->andReturn((object) [
            'campaigns' => [
                (object) [
                    'id' => 'c1',
                    'web_id' => 10,
                    'settings' => (object) ['title' => 'T1', 'subject_line' => 'S1'],
                    'status' => 'sent',
                    'type' => 'regular',
                ],
            ],
        ]);
        expect($mc->getCampaigns())->toHaveCount(1);

        $reportObj = (object) [
            'emails_sent' => 100,
            'opens' => (object) ['unique_opens' => 50, 'open_rate' => 0.5],
            'clicks' => (object) ['clicks_total' => 20, 'click_rate' => 0.2],
            'unsubscribed' => 2,
            'bounces' => (object) ['hard_bounces' => 1, 'soft_bounces' => 2],
        ];
        $apiMock->reports->shouldReceive('getCampaignReport')->with('c1')->andReturn($reportObj);
        expect($mc->getCampaignReport('c1')['bounce_rate'])->toBe(3);

        $apiMock->reports->shouldReceive('getABTestReportSummary')->once()->with('c1')->andReturn((object) [
            'a' => (object) ['subject_line' => 'A', 'opens' => 10, 'clicks' => 5],
            'b' => (object) ['subject_line' => 'B', 'opens' => 20, 'clicks' => 8],
            'winning_combination_id' => 'b',
            'winning_metric' => 'opens',
            'winning_metric_value' => 20,
        ]);
        expect($mc->getABTestResults('c1')['winner'])->toBe('b');

        expect($mc->trackEmailOpen('c1', 'e1'))->toBeTrue()
            ->and($mc->trackEmailClick('c1', 'e1', 'https://example.com'))->toBeTrue();
    });

    it('covers OutlookService', function (): void {
        $outlook = new OutlookService();
        $graphMock = Mockery::mock(Graph::class);
        $graphProp = new ReflectionClass($outlook)->getProperty('graph');
        $graphProp->setAccessible(true);
        $graphProp->setValue($outlook, $graphMock);

        $noTokenConfig = new OAuthConfiguration(['additional_settings' => []]);
        expect(fn () => $outlook->getUnreadMessages($noTokenConfig))->toThrow(\Exception::class, 'Access token not found');
        expect(fn () => $outlook->getMessage('m1', $noTokenConfig))->toThrow(\Exception::class, 'Access token not found');
        expect(fn () => $outlook->sendReply('m1', 'hi', $noTokenConfig))->toThrow(\Exception::class, 'Access token not found');
        expect(fn () => $outlook->sendMessage('a@b.com', 'S', 'B', $noTokenConfig))->toThrow(\Exception::class, 'Access token not found');

        $config = new OAuthConfiguration(['additional_settings' => ['access_token' => 'ms-tok']]);
        $graphMock->shouldReceive('setAccessToken')->with('ms-tok')->andReturnSelf();

        $emailAddr = fn (string $addr) => new class($addr)
        {
            public function __construct(private string $a) {}

            public function getEmailAddress(): object
            {
                return new class($this->a)
                {
                    public function __construct(private string $a) {}

                    public function getAddress(): string
                    {
                        return $this->a;
                    }
                };
            }
        };

        $msgMock = new class($emailAddr)
        {
            public function __construct(private \Closure $ea) {}

            public function getIsRead(): bool
            {
                return false;
            }

            public function getId(): string
            {
                return 'om-1';
            }

            public function getFrom(): object
            {
                return ($this->ea)('from@outlook.test');
            }

            public function getSubject(): string
            {
                return 'Outlook Subj';
            }

            public function getBody(): object
            {
                return new class()
                {
                    public function getContent(): string
                    {
                        return 'Body content';
                    }
                };
            }

            public function getReceivedDateTime(): string
            {
                return '2026-01-01T00:00:00Z';
            }

            public function getConversationId(): string
            {
                return 'conv-1';
            }

            public function getHasAttachments(): bool
            {
                return true;
            }

            public function getCcRecipients(): array
            {
                return [($this->ea)('cc@outlook.test')];
            }

            public function getBccRecipients(): array
            {
                return [($this->ea)('bcc@outlook.test')];
            }
        };

        $reqMock = Mockery::mock();
        $reqMock->shouldReceive('addHeaders')->andReturnSelf();
        $reqMock->shouldReceive('setReturnType')->andReturnSelf();
        $reqMock->shouldReceive('attachBody')->andReturnSelf();
        $reqMock->shouldReceive('execute')->andReturn([$msgMock], $msgMock, ['replied' => true], ['sent' => true]);

        $graphMock->shouldReceive('createRequest')->times(4)->andReturn($reqMock);

        expect($outlook->getUnreadMessages($config))->toHaveCount(1)
            ->and($outlook->getMessage('om-1', $config)['id'])->toBe('om-1')
            ->and($outlook->sendReply('om-1', 'Reply', $config))->toBe(['replied' => true])
            ->and($outlook->sendMessage('to@outlook.test', 'Subj', 'Body', $config))->toBe(['sent' => true]);
    });

    it('covers Pop3Service', function (): void {
        Mail::shouldReceive('raw')->andReturnUsing(function ($content, $callback): void {
            $msg = new class()
            {
                public function to($to): static
                {
                    return $this;
                }

                public function subject($subject): static
                {
                    return $this;
                }

                public function from($from): static
                {
                    return $this;
                }
            };
            $callback($msg);
        })->byDefault();
        $pop3 = new Pop3Service();
        $config = new OAuthConfiguration([
            'client_id' => 'u@pop3.test',
            'client_secret' => 'secret',
            'additional_settings' => [
                'host' => 'pop3.test',
                'port' => 110,
                'ssl' => false,
                'username' => 'u@pop3.test',
                'password' => 'secret',
            ],
        ]);

        // Connection failure branch
        Pop3SocketMock::$failConnect = true;
        expect(fn () => $pop3->getUnreadMessages($config))->toThrow(\Exception::class, 'Failed to connect to POP3 server');
        Pop3SocketMock::$failConnect = false;

        // getUnreadMessages with base64 message
        $b64Body = base64_encode('Decoded POP3 Body');
        Pop3SocketMock::$readQueue = [
            "+OK POP3 ready\r\n",
            "+OK USER\r\n",
            "+OK PASS\r\n",
            "+OK 1 120\r\n",
            "+OK 120 octets\r\n",
            "From: sender@pop3.test\r\n",
            "Subject: POP3 Subject\r\n",
            "Date: Thu, 01 Jan 2026 10:00:00 +0000\r\n",
            "Message-ID: <m1@pop3.test>\r\n",
            "Cc: cc@pop3.test\r\n",
            "Bcc: bcc@pop3.test\r\n",
            "Content-Transfer-Encoding: base64\r\n",
            "\r\n",
            $b64Body."\r\n",
            ".\r\n",
            "+OK QUIT\r\n",
        ];
        $unread = $pop3->getUnreadMessages($config);
        expect($unread)->toHaveCount(1)
            ->and($unread[0]['message'])->toBe('Decoded POP3 Body');

        // getMessage with quoted-printable message
        Pop3SocketMock::$readQueue = [
            "+OK POP3 ready\r\n",
            "+OK USER\r\n",
            "+OK PASS\r\n",
            "+OK 120 octets\r\n",
            "From: sender@pop3.test\r\n",
            "Subject: QP Subject\r\n",
            "Content-Transfer-Encoding: quoted-printable\r\n",
            "\r\n",
            "Hello=20World\r\n",
            ".\r\n",
            "+OK QUIT\r\n",
        ];
        $single = $pop3->getMessage(1, $config);
        expect($single['message'])->toBe("Hello World\r\n");

        // getMessage error (-ERR response)
        Pop3SocketMock::$readQueue = [
            "+OK POP3 ready\r\n",
            "-ERR Auth failed\r\n",
            "+OK QUIT\r\n",
        ];
        expect(fn () => $pop3->getMessage(1, $config))->toThrow(\Exception::class, 'POP3 Error: -ERR Auth failed');

        // sendReply success
        Pop3SocketMock::$readQueue = [
            "+OK POP3 ready\r\n",
            "+OK USER\r\n",
            "+OK PASS\r\n",
            "+OK 120 octets\r\n",
            "From: sender@pop3.test\r\n",
            "Subject: Orig\r\n",
            "\r\n",
            "Body\r\n",
            ".\r\n",
            "+OK QUIT\r\n",
        ];
        expect($pop3->sendReply(1, 'Reply text', $config))->toBe(['success' => true]);

        // sendReply error
        Pop3SocketMock::$failConnect = true;
        expect(fn () => $pop3->sendReply(1, 'Reply text', $config))->toThrow(\Exception::class);
        Pop3SocketMock::$failConnect = false;

        // sendMessage success + SMTP failure
        expect($pop3->sendMessage('to@pop3.test', 'Sub', 'Body', $config))->toBe(['success' => true]);

        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('POP3 SMTP error'));
        expect(fn () => $pop3->sendMessage('to@pop3.test', 'Sub', 'Body', $config))->toThrow(\RuntimeException::class, 'POP3 SMTP error');
    });

    it('covers StripeService', function (): void {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id, 'stripe_id' => null]);

        $stripeService = new StripeService();
        $stripeMock = Mockery::mock(StripeClient::class);
        $stripeMock->paymentMethods = Mockery::mock();
        $stripeMock->customers = Mockery::mock();
        $stripeMock->subscriptions = Mockery::mock();
        $stripeMock->products = Mockery::mock();
        $stripeMock->prices = Mockery::mock();

        $prop = new ReflectionClass($stripeService)->getProperty('stripeClient');
        $prop->setAccessible(true);
        $prop->setValue($stripeService, $stripeMock);

        // Disabled subscription
        config(['saas.enabled' => false]);
        expect(fn () => $stripeService->createSubscription($team, 'pm_1'))->toThrow(\Exception::class, 'Subscriptions are currently disabled');

        // Missing plan config
        config(['saas.enabled' => true, 'saas.plans.monthly' => null]);
        expect(fn () => $stripeService->createSubscription($team, 'pm_1', 'monthly'))->toThrow(\Exception::class, 'The selected billing plan is not configured.');

        // resolvePriceId branches
        expect($stripeService->resolvePriceId('monthly', ['price_id' => 'price_explicit']))->toBe('price_explicit');
        expect(fn () => $stripeService->resolvePriceId('bad', ['amount' => 'nope']))->toThrow(\Exception::class, 'Invalid Stripe configuration for plan [bad].');

        config(['saas.currency' => 'usd', 'app.name' => 'TestApp', 'saas.stripe_namespace' => 'test-ns']);
        Cache::flush();

        // Product & price created when not found
        $stripeMock->products->shouldReceive('all')->once()->andReturn((object) ['data' => []]);
        $stripeMock->products->shouldReceive('create')->once()->andReturn(new class()
        {
            public function toArray(): array
            {
                return ['id' => 'prod_new'];
            }
        });
        $stripeMock->prices->shouldReceive('all')->once()->andReturn((object) ['data' => []]);
        $stripeMock->prices->shouldReceive('create')->once()->andReturn(new class()
        {
            public function toArray(): array
            {
                return ['id' => 'price_new'];
            }
        });
        expect($stripeService->resolvePriceId('starter', ['amount' => 10, 'interval' => 'month']))->toBe('price_new');

        // Product & price matched when found
        Cache::flush();
        $stripeMock->products->shouldReceive('all')->once()->andReturn((object) [
            'data' => [
                new class()
                {
                    public function toArray(): array
                    {
                        return ['id' => 'prod_existing', 'metadata' => ['liberu_namespace' => 'test-ns', 'liberu_plan' => 'pro']];
                    }
                },
            ],
        ]);
        $stripeMock->prices->shouldReceive('all')->once()->andReturn((object) [
            'data' => [
                new class()
                {
                    public function toArray(): array
                    {
                        return ['id' => 'price_existing', 'currency' => 'usd', 'unit_amount' => 2500, 'recurring' => ['interval' => 'month']];
                    }
                },
            ],
        ]);
        expect($stripeService->resolvePriceId('pro', ['amount' => 25, 'interval' => 'month']))->toBe('price_existing');

        // createSubscription (team without stripe_id -> creates customer; then team with stripe_id -> retrieves customer)
        config(['saas.plans.monthly' => ['price_id' => 'price_123']]);
        $stripeMock->customers->shouldReceive('create')->once()->andReturn((object) ['id' => 'cus_1']);
        $stripeMock->paymentMethods->shouldReceive('attach')->andReturnNull()->byDefault();
        $stripeMock->customers->shouldReceive('update')->andReturnNull()->byDefault();
        $stripeMock->subscriptions->shouldReceive('create')->once()->andReturn(new class()
        {
            public string $id = 'sub_1';

            public string $status = 'active';

            public function toArray(): array
            {
                return ['current_period_end' => time() + 86400];
            }
        });

        $sub = $stripeService->createSubscription($team, 'pm_1', 'monthly');
        expect($sub->stripe_id)->toBe('sub_1')
            ->and($team->fresh()->stripe_id)->toBe('cus_1');

        // createSubscription with existing stripe_id + ApiErrorException
        $stripeMock->customers->shouldReceive('retrieve')->once()->with('cus_1')->andThrow(new InvalidRequestException('stripe create err'));
        expect(fn () => $stripeService->createSubscription($team->fresh(), 'pm_1', 'monthly'))
            ->toThrow(\Exception::class, 'Failed to create subscription: stripe create err');

        // updatePaymentMethod
        $teamNoStripe = Team::factory()->create(['user_id' => $owner->id, 'stripe_id' => null]);
        expect(fn () => $stripeService->updatePaymentMethod($teamNoStripe, 'pm_1'))
            ->toThrow(\Exception::class, 'No Stripe customer exists for this team.');

        $stripeService->updatePaymentMethod($team->fresh(), 'pm_2');

        $stripeMock->paymentMethods->shouldReceive('attach')->once()->andThrow(new InvalidRequestException('pm err'));
        expect(fn () => $stripeService->updatePaymentMethod($team->fresh(), 'pm_2'))
            ->toThrow(\Exception::class, 'Failed to update payment details: pm err');

        // cancelSubscription & updateSubscriptionQuantity
        $stripeMock->subscriptions->shouldReceive('cancel')->once()->andReturnNull();
        $stripeService->cancelSubscription($sub);
        expect($sub->fresh()->ends_at)->not->toBeNull();

        $stripeMock->subscriptions->shouldReceive('cancel')->once()->andThrow(new InvalidRequestException('cancel err'));
        expect(fn () => $stripeService->cancelSubscription($sub))
            ->toThrow(\Exception::class, 'Failed to cancel subscription: cancel err');

        $stripeMock->subscriptions->shouldReceive('update')->once()->andReturnNull();
        $stripeService->updateSubscriptionQuantity($sub, 5);
        expect($sub->fresh()->quantity)->toBe(5);

        $stripeMock->subscriptions->shouldReceive('update')->once()->andThrow(new InvalidRequestException('qty err'));
        expect(fn () => $stripeService->updateSubscriptionQuantity($sub, 5))
            ->toThrow(\Exception::class, 'Failed to update subscription quantity: qty err');
    });

    it('covers TwilioService', function (): void {
        Route::get('/_test/twilio/outbound', fn () => '')->name('twilio.twiml.outbound');
        Route::post('/_test/twilio/recording', fn () => '')->name('twilio.recording.callback');
        app('router')->getRoutes()->refreshNameLookups();
        config(['services.twilio.phone_number' => '+15550001111']);

        $twilio = new TwilioService();
        // Trigger lazy getClient(), phoneNumber(), storedConfiguration()
        $ref = new ReflectionClass($twilio);
        $getClient = $ref->getMethod('getClient');
        $getClient->setAccessible(true);
        expect($getClient->invoke($twilio))->toBeObject();

        $clientMock = Mockery::mock();
        $clientMock->messages = Mockery::mock();
        $clientMock->calls = Mockery::mock();
        $twilio->setClient($clientMock);

        // sendSMS retry then success
        $clientMock->messages->shouldReceive('create')->once()->andThrow(new TwilioException('temp fail'));
        $clientMock->messages->shouldReceive('create')->once()->andReturn(true);
        expect($twilio->sendSMS('+12223334444', 'Hi'))->toBeTrue();

        // sendSMS maxRetries exhausted
        $clientMock->messages->shouldReceive('create')->times(3)->andThrow(new TwilioException('perm fail'));
        expect(fn () => $twilio->sendSMS('+12223334444', 'Hi'))->toThrow(TwilioException::class);

        // makeCall success & failure
        $clientMock->calls->shouldReceive('create')->once()->andReturn(true);
        expect($twilio->makeCall('+12223334444', 'https://example.com/twiml'))->toBeTrue();

        $clientMock->calls->shouldReceive('create')->times(3)->andThrow(new TwilioException('call fail'));
        expect(fn () => $twilio->makeCall('+12223334444', 'https://example.com/twiml'))->toThrow(TwilioException::class);

        // Bulk SMS & Bulk Calls
        $clientMock->messages->shouldReceive('create')->once()->andReturn(true);
        $clientMock->calls->shouldReceive('create')->once()->andReturn(true);
        expect($twilio->sendBulkSMS(['+12223334444'], 'Bulk'))->toBe([true])
            ->and($twilio->initiateBulkCalls(['+12223334444'], 'https://example.com'))->toBe([true]);

        // maxRetries = 0 to hit final return false in sendSMS and makeCall
        $retriesProp = $ref->getProperty('maxRetries');
        $retriesProp->setAccessible(true);
        $retriesProp->setValue($twilio, 0);
        expect($twilio->sendSMS('+12223334444', 'Hi'))->toBeFalse()
            ->and($twilio->makeCall('+12223334444', 'https://example.com'))->toBeFalse();
        $retriesProp->setValue($twilio, 3);

        // Other call methods
        $clientMock->calls->shouldReceive('read')->once()->andReturn(['log1']);
        expect($twilio->getCallLogs())->toBe(['log1']);

        $callCtx = Mockery::mock();
        $callCtx->shouldReceive('fetch')->once()->andReturn((object) ['sid' => 'CA1']);
        $callCtx->shouldReceive('update')->once()->with(['status' => 'completed'])->andReturnNull();
        $recMock = Mockery::mock();
        $recMock->shouldReceive('update')->once()->with('stopped');
        $callCtx->recordings = Mockery::mock();
        $callCtx->recordings->shouldReceive('create')->once()->andReturn((object) ['sid' => 'RE1']);
        $callCtx->recordings->shouldReceive('read')->once()->andReturn([$recMock]);

        $clientMock->shouldReceive('calls')->with('CA1')->andReturn($callCtx);
        $clientMock->calls->shouldReceive('create')->once()->andReturn((object) ['sid' => 'CA_INIT']);

        expect($twilio->getCallDetails('CA1')->sid)->toBe('CA1')
            ->and($twilio->initiateCall('+12223334444')->sid)->toBe('CA_INIT')
            ->and($twilio->startCallRecording('CA1')->sid)->toBe('RE1')
            ->and($twilio->stopCallRecording('CA1'))->toBeTrue();

        $log = $twilio->logCall('CA1', null, 'outbound', 30, 'in-progress');
        expect($log)->toBeInstanceOf(CallLog::class)
            ->and($twilio->endCall('CA1'))->toBeTrue();
    });

    it('covers WebhookService, WhatsAppBusinessService, and YouTubeService', function (): void {
        $webhookService = new WebhookService();

        $hook = $webhookService->register([
            'name' => 'Hook 1',
            'url' => 'https://hooks.example.com/receive',
            'events' => ['contact.created'],
            'secret' => 'sec_123',
        ]);
        expect($hook->name)->toBe('Hook 1');

        $updated = $webhookService->update($hook, ['name' => 'Hook 1 Updated']);
        expect($updated->name)->toBe('Hook 1 Updated');

        $newSec = $webhookService->regenerateSecret($hook);
        expect($newSec)->toHaveLength(32);

        $sig = 'sha256='.hash_hmac('sha256', '{"a":1}', $newSec);
        expect($webhookService->verifySignature('{"a":1}', $sig, $newSec))->toBeTrue();

        // No secret branch in sign()
        $noSecHook = Webhook::create([
            'name' => 'No Sec',
            'url' => 'https://hooks.example.com/nosec',
            'events' => ['contact.created'],
            'secret' => null,
            'is_active' => true,
        ]);

        Http::fake([
            'https://hooks.example.com/receive' => Http::response('OK', 200),
            'https://hooks.example.com/nosec' => Http::response('Fail', 500),
            'https://hooks.example.com/fail10' => Http::response('Fail', 500),
            'https://hooks.example.com/throw' => fn () => throw new \RuntimeException('Conn error'),
            '/relative-only' => Http::response('OK', 200),
        ]);

        $webhookService->dispatch('contact.created', ['id' => 1]);
        expect(WebhookDelivery::count())->toBeGreaterThanOrEqual(2);

        // 10th consecutive failure auto-disables
        $fail10Hook = Webhook::create([
            'name' => 'Fail 10',
            'url' => 'https://hooks.example.com/fail10',
            'events' => ['contact.created'],
            'secret' => 's',
            'is_active' => true,
            'failure_count' => 9,
        ]);
        expect($webhookService->send($fail10Hook, 'contact.created', []))->toBeFalse()
            ->and($fail10Hook->fresh()->is_active)->toBeFalse();

        // Exception during send
        $throwHook = Webhook::create([
            'name' => 'Throw',
            'url' => 'https://hooks.example.com/throw',
            'events' => ['contact.created'],
            'secret' => 's',
            'is_active' => true,
        ]);
        expect($webhookService->send($throwHook, 'contact.created', []))->toBeFalse();

        // Production SSRF check branches
        app()->detectEnvironment(fn (): string => 'production');
        $relHook = Webhook::create([
            'name' => 'Relative',
            'url' => '/relative-only',
            'events' => ['contact.created'],
            'secret' => 's',
            'is_active' => true,
        ]);
        $webhookService->send($relHook, 'contact.created', []);

        Pop3SocketMock::$fakeHostIp = '127.0.0.1';
        expect(fn () => $webhookService->send($hook, 'contact.created', []))
            ->toThrow(\RuntimeException::class, 'Webhook URL resolves to an internal IP address.');
        Pop3SocketMock::$fakeHostIp = null;
        app()->detectEnvironment(fn (): string => 'testing');

        // WhatsAppBusinessService
        config(['services.whatsapp.api_url' => 'https://wa.example.com', 'services.whatsapp.access_token' => 'wa-tok']);
        $wa = new WhatsAppBusinessService();
        $waConfig = new OAuthConfiguration([
            'additional_settings' => [
                'api_url' => 'https://wa.example.com',
                'access_token' => 'wa-tok-2',
            ],
        ]);

        Http::fake([
            'https://wa.example.com/messages*' => Http::response([
                'messages' => [
                    ['id' => 'w1', 'from' => '123', 'text' => ['body' => 'Hello WA']],
                ],
                'id' => 'w1',
            ], 200),
        ]);

        expect($wa->getMessages($waConfig))->toHaveCount(1)
            ->and($wa->getMessage('w1', $waConfig)['id'])->toBe('w1')
            ->and($wa->sendReply('123', 'Hi', $waConfig)['id'])->toBe('w1')
            ->and($wa->sendMessage('123', 'Hi', $waConfig)['id'])->toBe('w1');

        Http::fake([
            'https://wa.example.com/*' => fn () => throw new \RuntimeException('WA HTTP fail'),
        ]);
        expect(fn () => $wa->getUnreadMessages($waConfig))->toThrow(\RuntimeException::class, 'WA HTTP fail');

        // YouTubeService
        ConnectedAccount::query()->delete();
        $ytAcc = ConnectedAccount::create([
            'user_id' => 1,
            'provider' => 'youtube',
            'account_type' => 'youtube',
            'provider_id' => 'yt_1',
            'token' => 'yt_tok',
            'is_primary' => true,
        ]);
        $yt = new YouTubeService();
        expect($yt->getAllConnectedAccounts())->toHaveCount(1)
            ->and($yt->getPrimaryAccount()?->id)->toBe($ytAcc->id);

        expect(fn () => $yt->uploadVideo($ytAcc, '/tmp/nonexistent-video-file.mp4', 'Title', 'Desc'))
            ->toThrow(\Exception::class, 'Video file not found');

        $tmpVideo = tempnam(sys_get_temp_dir(), 'yt_vid_');
        file_put_contents($tmpVideo, 'fake-video-bytes');

        Http::swap(new Factory());
        Http::fake([
            'https://www.googleapis.com/upload/youtube/v3/*' => Http::sequence()
                ->push('Init err', 400)
                ->push('', 200)
                ->push('', 200, ['Location' => 'https://upload.example.com/session1'])
                ->push('', 200, ['Location' => 'https://upload.example.com/session2'])
                ->push('', 200, ['Location' => 'https://upload.example.com/session3']),
            'https://upload.example.com/session2' => Http::response('Put err', 500),
            'https://upload.example.com/session3' => Http::response(['id' => 'yt-vid-123'], 200),
        ]);

        // Init fails
        expect(fn () => $yt->uploadVideo($ytAcc, $tmpVideo, 'Title', 'Desc'))
            ->toThrow(\Exception::class, 'Failed to initialize YouTube upload: Init err');

        // Missing Location header
        expect(fn () => $yt->uploadVideo($ytAcc, $tmpVideo, 'Title', 'Desc'))
            ->toThrow(\Exception::class, 'YouTube upload URI not returned from API');

        // fopen returns false
        Pop3SocketMock::$forceFopenFalse = true;
        expect(fn () => $yt->uploadVideo($ytAcc, $tmpVideo, 'Title', 'Desc'))
            ->toThrow(\Exception::class, 'Failed to open video file for reading');
        Pop3SocketMock::$forceFopenFalse = false;

        // Upload PUT fails
        expect(fn () => $yt->uploadVideo($ytAcc, $tmpVideo, 'Title', 'Desc'))
            ->toThrow(\Exception::class, 'Failed to upload video to YouTube: Put err');

        // Upload PUT succeeds
        expect($yt->uploadVideo($ytAcc, $tmpVideo, 'Title', 'Desc'))->toBe(['id' => 'yt-vid-123']);

        @unlink($tmpVideo);
    });
}
