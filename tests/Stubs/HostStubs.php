<?php

declare(strict_types=1);

namespace {
    use Composer\Autoload\ClassLoader;

    $hostLoader = new ClassLoader();
    $root = dirname(__DIR__, 2);

    foreach (glob($root.'/modules/*/database/factories') ?: [] as $factoryDir) {
        $hostLoader->addPsr4('Database\\Factories\\', $factoryDir);
    }

    foreach (glob($root.'/modules/*/database/seeders') ?: [] as $seederDir) {
        $hostLoader->addPsr4('Database\\Seeders\\', $seederDir);
    }

    $crmVendorPsr4 = '/home/liberu/projects/crm-laravel/vendor/composer/autoload_psr4.php';
    if (is_file($crmVendorPsr4)) {
        $crmMappings = require $crmVendorPsr4;
        foreach (['OneLogin\\Saml2\\', 'RobRichards\\XMLSecLibs\\', 'MailchimpMarketing\\', 'Stripe\\', 'Webklex\\PHPIMAP\\', 'Webklex\\IMAP\\'] as $prefix) {
            if (isset($crmMappings[$prefix])) {
                $hostLoader->addPsr4($prefix, $crmMappings[$prefix]);
            }
        }
    }

    $hostLoader->register(false);

    if (! class_exists('Google_Client')) {
        class Google_Client
        {
            private ?string $redirectUri = null;

            private ?string $state = null;

            private mixed $token = null;

            public function setAuthConfig(mixed $config): void {}

            public function setClientId(?string $id): void {}

            public function setClientSecret(?string $secret): void {}

            public function setAccessType(string $type): void {}

            public function setPrompt(string $prompt): void {}

            public function setIncludeGrantedScopes(bool $include): void {}

            public function addScope(mixed $scope): void {}

            public function setScopes(mixed $scopes): void {}

            public function setAccessToken(mixed $token): void
            {
                $this->token = $token;
            }

            public function getAccessToken(): mixed
            {
                return $this->token;
            }

            public function isAccessTokenExpired(): bool
            {
                return false;
            }

            public function fetchAccessTokenWithRefreshToken(?string $refreshToken = null): array
            {
                return ['access_token' => 'refreshed-token'];
            }

            public function fetchAccessTokenWithAuthCode(string $code): array
            {
                return ['access_token' => 'auth-code-token'];
            }

            public function setRedirectUri(string $uri): void
            {
                $this->redirectUri = $uri;
            }

            public function setState(string $state): void
            {
                $this->state = $state;
            }

            public function createAuthUrl(): string
            {
                return 'https://accounts.google.com/o/oauth2/auth?'.http_build_query([
                    'redirect_uri' => $this->redirectUri,
                    'state' => $this->state,
                ]);
            }

            public function setDefer(bool $defer): void {}

            public function execute(mixed $request): mixed
            {
                return null;
            }
        }
    }

    if (! class_exists('Google_Service_Calendar')) {
        class Google_Service_Calendar
        {
            public const CALENDAR = 'https://www.googleapis.com/auth/calendar';

            public mixed $events = null;

            public function __construct(mixed $client = null) {}
        }
    }

    if (! class_exists('Google_Service_Calendar_Event')) {
        class Google_Service_Calendar_Event
        {
            protected array $data = [];

            public ?string $id = null;

            public ?string $summary = null;

            public ?string $description = null;

            public mixed $start = null;

            public mixed $end = null;

            public function __construct(array $data = [])
            {
                $this->data = $data;
                $this->id = $data['id'] ?? null;
                $this->summary = $data['summary'] ?? null;
                $this->description = $data['description'] ?? null;
                $this->start = isset($data['start']) ? (object) $data['start'] : null;
                $this->end = isset($data['end']) ? (object) $data['end'] : null;
            }

            public function getId(): ?string
            {
                return $this->id ?? ($this->data['id'] ?? null);
            }

            public function getSummary(): ?string
            {
                return $this->summary ?? ($this->data['summary'] ?? null);
            }

            public function setSummary(?string $summary): void
            {
                $this->summary = $summary;
                $this->data['summary'] = $summary;
            }

            public function getDescription(): ?string
            {
                return $this->description ?? ($this->data['description'] ?? null);
            }

            public function setDescription(?string $description): void
            {
                $this->description = $description;
                $this->data['description'] = $description;
            }

            public function getStart(): ?object
            {
                return is_object($this->start) ? $this->start : (isset($this->data['start']) ? (object) $this->data['start'] : null);
            }

            public function setStart(mixed $start): void
            {
                $this->start = is_object($start) ? $start : (object) (array) $start;
                $this->data['start'] = is_array($start) ? $start : (array) $start;
            }

            public function setEnd(mixed $end): void
            {
                $this->end = is_object($end) ? $end : (object) (array) $end;
                $this->data['end'] = is_array($end) ? $end : (array) $end;
            }
        }
    }

    if (! class_exists('Google_Service_Calendar_Events')) {
        class Google_Service_Calendar_Events
        {
            protected array $items = [];

            public function __construct(array $data = [])
            {
                $this->items = $data['items'] ?? [];
            }

            public function getItems(): array
            {
                return $this->items;
            }
        }
    }

    if (! class_exists('Google_Service_Gmail')) {
        class Google_Service_Gmail
        {
            public const GMAIL_READONLY = 'https://www.googleapis.com/auth/gmail.readonly';

            public const GMAIL_SEND = 'https://www.googleapis.com/auth/gmail.send';

            public const GMAIL_MODIFY = 'https://www.googleapis.com/auth/gmail.modify';

            public mixed $users_messages = null;

            public function __construct(mixed $client = null) {}
        }
    }

    if (! class_exists('Google_Service_Gmail_Message')) {
        class Google_Service_Gmail_Message
        {
            private ?string $raw = null;

            private ?string $id = null;

            private ?string $threadId = null;

            private mixed $payload = null;

            public function setRaw(string $raw): void
            {
                $this->raw = $raw;
            }

            public function getRaw(): ?string
            {
                return $this->raw;
            }

            public function setId(string $id): void
            {
                $this->id = $id;
            }

            public function getId(): ?string
            {
                return $this->id;
            }

            public function setThreadId(?string $threadId): void
            {
                $this->threadId = $threadId;
            }

            public function getThreadId(): ?string
            {
                return $this->threadId;
            }

            public function setPayload(mixed $payload): void
            {
                $this->payload = $payload;
            }

            public function getPayload(): mixed
            {
                return $this->payload;
            }
        }
    }

    if (! class_exists('Google_Service_YouTube')) {
        class Google_Service_YouTube
        {
            public const YOUTUBE_UPLOAD = 'https://www.googleapis.com/auth/youtube.upload';

            public const YOUTUBE_READONLY = 'https://www.googleapis.com/auth/youtube.readonly';

            public mixed $videos = null;

            public mixed $channels = null;

            public function __construct(mixed $client = null) {}
        }
    }

    if (! class_exists('Google_Service_YouTube_VideoSnippet')) {
        class Google_Service_YouTube_VideoSnippet
        {
            public function setTitle(string $title): void {}

            public function setDescription(string $desc): void {}

            public function setTags(array $tags): void {}

            public function setCategoryId(string $id): void {}
        }
    }

    if (! class_exists('Google_Service_YouTube_VideoStatus')) {
        class Google_Service_YouTube_VideoStatus
        {
            public string $privacyStatus = 'private';

            public function setPrivacyStatus(string $status): void
            {
                $this->privacyStatus = $status;
            }
        }
    }

    if (! class_exists('Google_Service_YouTube_Video')) {
        class Google_Service_YouTube_Video
        {
            public function setSnippet(mixed $snippet): void {}

            public function setStatus(mixed $status): void {}

            public function getId(): string
            {
                return 'yt-video-1';
            }
        }
    }

    if (! class_exists('Google_Http_MediaFileUpload')) {
        class Google_Http_MediaFileUpload
        {
            public static mixed $nextChunkResult = false;

            public function __construct(mixed ...$args) {}

            public function setFileSize(int $size): void {}

            public function nextChunk(string $chunk): mixed
            {
                $res = self::$nextChunkResult;
                self::$nextChunkResult = ['id' => 'yt-uploaded-1'];

                return $res;
            }
        }
    }
}

namespace Twilio\Exceptions {
    if (! class_exists(TwilioException::class)) {
        class TwilioException extends \RuntimeException {}
    }
    if (! class_exists(RestException::class)) {
        class RestException extends TwilioException {}
    }
}

namespace Twilio\Rest {
    if (! class_exists(Client::class)) {
        class Client
        {
            public function __construct(mixed ...$args) {}

            public function __get(string $name): mixed
            {
                return null;
            }
        }
    }
}

namespace Twilio\Security {
    if (! class_exists(RequestValidator::class)) {
        class RequestValidator
        {
            public function __construct(private string $token = '') {}

            public function validate(string $signature, string $url, array $params = []): bool
            {
                return $signature === 'valid-signature';
            }
        }
    }
}

namespace Twilio\TwiML {
    if (! class_exists(VoiceResponse::class)) {
        class VoiceResponse
        {
            private array $parts = [];

            public function say(string $message, array $attributes = []): static
            {
                $this->parts[] = '<Say>'.htmlspecialchars($message).'</Say>';

                return $this;
            }

            public function dial(?string $number = null, array $attributes = []): object
            {
                $this->parts[] = '<Dial>'.htmlspecialchars((string) $number).'</Dial>';

                return new class($this->parts)
                {
                    public function __construct(private array &$parts) {}

                    public function number(string $num): void
                    {
                        $this->parts[] = '<Number>'.htmlspecialchars($num).'</Number>';
                    }
                };
            }

            public function record(array $attributes = []): static
            {
                $this->parts[] = '<Record/>';

                return $this;
            }

            public function __toString(): string
            {
                return '<?xml version="1.0" encoding="UTF-8"?><Response>'.implode('', $this->parts).'</Response>';
            }
        }
    }
}

namespace Microsoft\Graph {
    if (! class_exists(Graph::class)) {
        class Graph
        {
            protected ?string $accessToken = null;

            public function setAccessToken(string $token): static
            {
                $this->accessToken = $token;

                return $this;
            }

            public function createRequest(string $method, string $uri): object
            {
                return new class($method, $uri)
                {
                    public function __construct(public string $method, public string $uri) {}

                    public function attachBody(mixed $body): static
                    {
                        return $this;
                    }

                    public function addHeaders(array $headers): static
                    {
                        return $this;
                    }

                    public function setReturnType(string $class): static
                    {
                        return $this;
                    }

                    public function execute(): mixed
                    {
                        return null;
                    }
                };
            }
        }
    }
}

namespace Microsoft\Graph\Model {
    if (! class_exists(Event::class)) {
        class Event
        {
            public function __construct(protected array $data = []) {}

            public function getId(): ?string
            {
                return $this->data['id'] ?? null;
            }

            public function setSubject(?string $subject): void
            {
                $this->data['subject'] = $subject;
            }

            public function getSubject(): ?string
            {
                return $this->data['subject'] ?? null;
            }

            public function setBody(mixed $body): void
            {
                $this->data['body'] = $body;
            }

            public function getBody(): ?object
            {
                $body = $this->data['body'] ?? null;
                if (is_array($body)) {
                    return new class($body)
                    {
                        public function __construct(private array $data) {}

                        public function getContent(): ?string
                        {
                            return $this->data['content'] ?? null;
                        }
                    };
                }

                return $body;
            }

            public function setStart(mixed $start): void
            {
                $this->data['start'] = $start;
            }

            public function getStart(): ?object
            {
                $start = $this->data['start'] ?? null;
                if (is_array($start)) {
                    return new class($start)
                    {
                        public function __construct(private array $data) {}

                        public function getDateTime(): ?string
                        {
                            return $this->data['dateTime'] ?? null;
                        }
                    };
                }

                return $start;
            }

            public function setEnd(mixed $end): void
            {
                $this->data['end'] = $end;
            }
        }
    }

    if (! class_exists(ItemBody::class)) {
        class ItemBody
        {
            public function __construct(protected array $data = []) {}

            public function setContent(?string $content): void
            {
                $this->data['content'] = $content;
            }

            public function setContentType(mixed $type): void
            {
                $this->data['contentType'] = $type;
            }

            public function getContent(): ?string
            {
                return $this->data['content'] ?? null;
            }
        }
    }

    if (! class_exists(DateTimeTimeZone::class)) {
        class DateTimeTimeZone
        {
            public function __construct(protected array $data = []) {}

            public function setDateTime(?string $dt): void
            {
                $this->data['dateTime'] = $dt;
            }

            public function setTimeZone(?string $tz): void
            {
                $this->data['timeZone'] = $tz;
            }

            public function getDateTime(): ?string
            {
                return $this->data['dateTime'] ?? null;
            }
        }
    }

    if (! class_exists(Message::class)) {
        class Message
        {
            public function __construct(protected array $data = []) {}

            public function getId(): ?string
            {
                return $this->data['id'] ?? 'msg-1';
            }

            public function getSubject(): ?string
            {
                return $this->data['subject'] ?? 'Subject';
            }

            public function getBodyPreview(): ?string
            {
                return $this->data['bodyPreview'] ?? 'Preview';
            }

            public function getBody(): object
            {
                return new ItemBody(['content' => $this->data['content'] ?? 'Body']);
            }

            public function getFrom(): ?object
            {
                return $this->data['from'] ?? (object) ['emailAddress' => (object) ['address' => 'from@example.test', 'name' => 'Sender']];
            }

            public function getToRecipients(): array
            {
                return $this->data['toRecipients'] ?? [['emailAddress' => ['address' => 'to@example.test']]];
            }

            public function getReceivedDateTime(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00Z');
            }

            public function getSentDateTime(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00Z');
            }

            public function getConversationId(): string
            {
                return $this->data['conversationId'] ?? 'conv-1';
            }
        }
    }
}

namespace Facebook\Exceptions {
    if (! class_exists(FacebookSDKException::class)) {
        class FacebookSDKException extends \Exception {}
    }
    if (! class_exists(FacebookResponseException::class)) {
        class FacebookResponseException extends FacebookSDKException {}
    }
}

namespace Facebook {
    if (! class_exists(Facebook::class)) {
        class Facebook
        {
            public function __construct(array $config = []) {}

            public function get(string $endpoint, ?string $token = null): object
            {
                return new class()
                {
                    public function getDecodedBody(): array
                    {
                        return ['data' => []];
                    }

                    public function getGraphNode(): object
                    {
                        return new class()
                        {
                            public function asArray(): array
                            {
                                return [];
                            }
                        };
                    }
                };
            }

            public function post(string $endpoint, array $params = [], ?string $token = null): object
            {
                return $this->get($endpoint, $token);
            }
        }
    }
}

namespace FacebookAds\Exception {
    if (! class_exists(FacebookException::class)) {
        class FacebookException extends \Exception {}
    }
}

namespace FacebookAds {
    if (! class_exists(Api::class)) {
        class Api
        {
            public static ?self $instance = null;

            public static function init(mixed $appId, mixed $appSecret, mixed $token): self
            {
                return self::$instance ??= new self();
            }

            public static function instance(): ?self
            {
                return self::$instance;
            }
        }
    }
}

namespace FacebookAds\Object {
    if (! class_exists(AdAccount::class)) {
        class AdAccount
        {
            public static mixed $campaignsResponse = [];

            public static ?\Throwable $throwOnGet = null;

            public function __construct(public mixed $id = null) {}

            public function getCampaigns(array $fields = [], array $params = []): object
            {
                if (self::$throwOnGet !== null) {
                    $e = self::$throwOnGet;
                    self::$throwOnGet = null;
                    throw $e;
                }

                return new class(self::$campaignsResponse)
                {
                    public function __construct(private array $data) {}

                    public function getResponse(): object
                    {
                        return new class($this->data)
                        {
                            public function __construct(private array $data) {}

                            public function getContent(): array
                            {
                                return $this->data;
                            }
                        };
                    }
                };
            }
        }
    }

    if (! class_exists(Page::class)) {
        class Page
        {
            public static array $feedResult = ['id' => 'post-1'];

            public static ?\Throwable $throwOnCreate = null;

            public function __construct(public mixed $id = null) {}

            public function createFeed(array $fields = [], array $params = []): object
            {
                if (self::$throwOnCreate !== null) {
                    $e = self::$throwOnCreate;
                    self::$throwOnCreate = null;
                    throw $e;
                }

                return new class(self::$feedResult)
                {
                    public function __construct(private array $data) {}

                    public function exportAllData(): array
                    {
                        return $this->data;
                    }
                };
            }
        }
    }
}

namespace Abraham\TwitterOAuth {
    if (! class_exists(TwitterOAuth::class)) {
        class TwitterOAuth
        {
            public static mixed $lastGet = [];

            public static mixed $lastPost = ['id' => 'tweet-1'];

            public function __construct(mixed ...$args) {}

            public function get(string $path, array $params = []): mixed
            {
                return self::$lastGet;
            }

            public function post(string $path, array $params = [], bool $json = false): mixed
            {
                return self::$lastPost;
            }
        }
    }
}

namespace Google\ApiCore {
    if (! class_exists(ApiException::class)) {
        class ApiException extends \Exception
        {
            public function __construct(string $message = '', int $code = 0, string $status = 'INVALID_ARGUMENT')
            {
                parent::__construct($message, $code);
            }
        }
    }
}

namespace Google\Ads\GoogleAds\Lib {
    if (! class_exists(OAuth2TokenBuilder::class)) {
        class OAuth2TokenBuilder
        {
            public function fromFile(): static
            {
                return $this;
            }

            public function withClientId(mixed $v): static
            {
                return $this;
            }

            public function withClientSecret(mixed $v): static
            {
                return $this;
            }

            public function withRefreshToken(mixed $v): static
            {
                return $this;
            }

            public function build(): object
            {
                return new \stdClass();
            }
        }
    }
}

namespace Google\Ads\GoogleAds\Lib\V14 {
    if (! class_exists(GoogleAdsClientBuilder::class)) {
        class GoogleAdsClientBuilder
        {
            public static mixed $mockClient = null;

            public function fromFile(): static
            {
                return $this;
            }

            public function withDeveloperToken(mixed $v): static
            {
                return $this;
            }

            public function withLoginCustomerId(mixed $v): static
            {
                return $this;
            }

            public function withOAuth2Credential(mixed $v): static
            {
                return $this;
            }

            public function build(): mixed
            {
                return self::$mockClient ?? new \stdClass();
            }
        }
    }
}

namespace Google\Ads\GoogleAds\V14\Enums\CampaignStatusEnum {
    if (! class_exists(CampaignStatus::class)) {
        class CampaignStatus
        {
            public const PAUSED = 3;

            public const ENABLED = 2;

            public static function name(int $value): string
            {
                return $value === self::ENABLED ? 'ENABLED' : 'PAUSED';
            }
        }
    }
}

namespace Google\Ads\GoogleAds\V14\Resources {
    if (! class_exists(Campaign::class)) {
        class Campaign
        {
            public function __construct(public array $data = []) {}
        }
    }
}

namespace Google\Ads\GoogleAds\V14\Services {
    if (! class_exists(CampaignOperation::class)) {
        class CampaignOperation
        {
            public function setCreate(mixed $campaign): void {}

            public function setUpdate(mixed $campaign): void {}

            public function setUpdateMask(mixed $mask): void {}
        }
    }
}

namespace Liberu\CRM\UnifiedConversations\Actions {
    if (! class_exists(SyncExternalConversation::class)) {
        class SyncExternalConversation
        {
            public function handle(array $payload): array
            {
                return $payload;
            }
        }
    }
}

namespace App\Http\Controllers {
    use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
    use Illuminate\Foundation\Validation\ValidatesRequests;

    if (! class_exists(Controller::class)) {
        abstract class Controller extends \Illuminate\Routing\Controller
        {
            use AuthorizesRequests;
            use ValidatesRequests;
        }
    }
}

namespace App\Exceptions {
    if (! class_exists(AccountingIntegrationException::class)) {
        class AccountingIntegrationException extends \RuntimeException {}
    }
}

namespace App\Events {
    if (! class_exists(ContactUpdated::class)) {
        class ContactUpdated
        {
            public function __construct(public mixed $contact = null) {}
        }
    }
}

namespace App\Filament\Concerns {
    use App\Models\User;
    use Illuminate\Auth\Access\Response;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Str;

    if (! trait_exists(EnforcesResourcePermissions::class)) {
        trait EnforcesResourcePermissions
        {
            public static function permissionResource(): string
            {
                return Str::snake(class_basename(static::getModel()));
            }

            protected static function userHasPermission(string $action): bool
            {
                $user = Auth::user();
                if (! $user instanceof User) {
                    return false;
                }
                if ($user->hasRole('super_admin')) {
                    return true;
                }

                return $user->getAllPermissions()->contains('name', $action.'_'.static::permissionResource());
            }

            public static function canViewAny(): bool
            {
                return static::userHasPermission('view') && parent::getViewAnyAuthorizationResponse()->allowed();
            }

            public static function getViewAnyAuthorizationResponse(): Response
            {
                return static::canViewAny() ? parent::getViewAnyAuthorizationResponse() : Response::deny();
            }

            public static function canView(Model $record): bool
            {
                return static::userHasPermission('view') && parent::getViewAuthorizationResponse($record)->allowed();
            }

            public static function getViewAuthorizationResponse(Model $record): Response
            {
                return static::canView($record) ? parent::getViewAuthorizationResponse($record) : Response::deny();
            }

            public static function canCreate(): bool
            {
                return static::userHasPermission('create') && parent::getCreateAuthorizationResponse()->allowed();
            }

            public static function getCreateAuthorizationResponse(): Response
            {
                return static::canCreate() ? parent::getCreateAuthorizationResponse() : Response::deny();
            }

            public static function canEdit(Model $record): bool
            {
                return static::userHasPermission('update') && parent::getEditAuthorizationResponse($record)->allowed();
            }

            public static function getEditAuthorizationResponse(Model $record): Response
            {
                return static::canEdit($record) ? parent::getEditAuthorizationResponse($record) : Response::deny();
            }

            public static function canDelete(Model $record): bool
            {
                return static::userHasPermission('delete') && parent::getDeleteAuthorizationResponse($record)->allowed();
            }

            public static function getDeleteAuthorizationResponse(Model $record): Response
            {
                return static::canDelete($record) ? parent::getDeleteAuthorizationResponse($record) : Response::deny();
            }

            public static function canDeleteAny(): bool
            {
                return static::userHasPermission('delete') && parent::getDeleteAnyAuthorizationResponse()->allowed();
            }

            public static function getDeleteAnyAuthorizationResponse(): Response
            {
                return static::canDeleteAny() ? parent::getDeleteAnyAuthorizationResponse() : Response::deny();
            }
        }
    }
}

namespace App\Filament\Forms {
    use Filament\Forms\Components\CheckboxList;
    use Filament\Forms\Components\Placeholder;
    use Filament\Forms\Components\Select;
    use Filament\Forms\Components\TextInput;
    use Filament\Schemas\Components\Section;
    use Filament\Schemas\Components\Utilities\Get;
    use Filament\Schemas\Components\Utilities\Set;
    use Liberu\Foundation\RolesPermissions\Enums\Role;
    use Liberu\Foundation\RolesPermissions\Support\RolePermissionCatalog;
    use Spatie\Permission\Models\Role as SpatieRole;

    if (! class_exists(RolePermissionBuilder::class)) {
        final class RolePermissionBuilder
        {
            public static function components(bool $platform = false): array
            {
                return [
                    Section::make($platform ? 'Platform role' : 'Team role')
                        ->columnSpanFull()->columns(2)->schema([
                            TextInput::make('name')->required()->maxLength(255)
                                ->readOnly(fn (?SpatieRole $record): bool => $platform && $record !== null && in_array($record->name, Role::values(), true)),
                            Select::make('preset')->label('Start from a preset')->options(RolePermissionCatalog::presets())
                                ->live()->dehydrated(false)
                                ->afterStateUpdated(function (?string $state, Set $set): void {
                                    if ($state !== null) {
                                        $set('permissions', RolePermissionCatalog::preset($state));
                                    }
                                }),
                        ]),
                    Section::make('Permission builder')
                        ->columnSpanFull()->schema([
                            Placeholder::make('permission_summary')->label('Selection')
                                ->content(fn (Get $get): string => count($get('permissions') ?? []).' permissions selected'),
                            CheckboxList::make('permissions')->label('Allowed actions')->options(RolePermissionCatalog::options($platform)),
                        ]),
                ];
            }
        }
    }
}

namespace App\Support {
    use Illuminate\Support\Facades\Schema;

    if (! class_exists(SchemaGraph::class)) {
        class SchemaGraph
        {
            public static function edges(array $tables): array
            {
                $map = [];
                foreach ($tables as $table) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }
                    foreach (Schema::getForeignKeys($table) as $fk) {
                        $columns = $fk['columns'] ?? [];
                        $referenced = $fk['foreign_table'] ?? null;
                        if ($referenced !== null && count($columns) === 1) {
                            $map[$table][$columns[0]] = $referenced;
                        }
                    }
                }

                return $map;
            }
        }
    }
}

namespace App\Services {
    use App\Models\AuditLog;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\Auth;

    if (! class_exists(AuditLogService::class)) {
        class AuditLogService
        {
            public function log(string $action, string $description): void
            {
                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => $action,
                    'description' => $description,
                    'ip_address' => request()->ip(),
                ]);
            }

            public function record(string $action, string $description, ?Model $auditable = null): void
            {
                if (! Auth::check()) {
                    return;
                }
                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => $action,
                    'description' => $description,
                    'ip_address' => request()->ip() ?? '0.0.0.0',
                    'auditable_type' => $auditable?->getMorphClass(),
                    'auditable_id' => $auditable?->getKey(),
                ]);
            }
        }
    }
}

namespace App\Services\Marketing {
    use App\Models\MarketingPlatformSettings;
    use App\Models\User;
    use Illuminate\Support\Facades\Schema;
    use Liberu\Foundation\Organizations\Models\Team;

    if (! class_exists(PlatformConfiguration::class)) {
        class PlatformConfiguration
        {
            public function settings(): ?MarketingPlatformSettings
            {
                return Schema::hasTable('marketing_platform_settings') ? MarketingPlatformSettings::query()->find(1) : null;
            }

            public function provider(): string
            {
                return $this->settings()?->getAttribute('provider')
                    ?? (in_array(config('services.zernio.mode'), ['zernio', 'preferred'], true) ? 'zernio' : 'direct');
            }

            public function credential(string $key, mixed $default = null): mixed
            {
                return data_get($this->settings()?->getAttribute('credentials') ?? [], $key, $default);
            }
        }
    }

    if (! class_exists(WorkspaceAccess::class)) {
        class WorkspaceAccess
        {
            public function canManage(User $user, Team $team): bool
            {
                return (int) $team->getAttribute('user_id') === (int) $user->getKey()
                    || $team->users()->whereKey($user->getKey())->wherePivotIn('role', ['admin', 'manager'])->exists();
            }

            public function authorize(User $user, Team $team, string $permission): void
            {
                abort_unless($user->belongsToTeam($team), 403);
                abort_unless($this->canManage($user, $team) || $user->getAllPermissions()->contains('name', $permission), 403);
            }
        }
    }
}

namespace App\Models {
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use Illuminate\Database\Eloquent\Relations\BelongsToMany;
    use Liberu\Foundation\Identity\Socialstream\Models\ConnectedAccount as BaseConnectedAccount;
    use Liberu\Foundation\Organizations\Traits\IsTenantModel;

    if (! class_exists(ConnectedAccount::class)) {
        class ConnectedAccount extends BaseConnectedAccount
        {
            protected $guarded = [];

            protected $casts = [
                'created_at' => 'datetime',
                'expires_at' => 'datetime',
                'is_primary' => 'boolean',
            ];

            public function scopeOfType(Builder $query, string $type): Builder
            {
                return $query->where('account_type', $type);
            }

            public function scopePrimary(Builder $query): Builder
            {
                return $query->where('is_primary', true);
            }

            public function isExpired(): bool
            {
                return $this->expires_at !== null && $this->expires_at->isPast();
            }
        }
    }

    if (! class_exists(MarketingPlatformSettings::class)) {
        class MarketingPlatformSettings extends Model
        {
            protected $table = 'marketing_platform_settings';

            protected $guarded = [];

            protected $casts = [
                'credentials' => 'array',
                'options' => 'array',
                'setup_completed_at' => 'datetime',
            ];
        }
    }

    if (! class_exists(AuditLog::class)) {
        class AuditLog extends Model
        {
            use IsTenantModel;

            protected $table = 'audit_logs';

            protected $guarded = [];

            protected $casts = [
                'changes' => 'array',
            ];

            public function user(): BelongsTo
            {
                return $this->belongsTo(User::class);
            }
        }
    }

    if (! class_exists(Territory::class)) {
        class Territory extends Model
        {
            use IsTenantModel;

            protected $table = 'territories';

            protected $guarded = [];

            protected $casts = [
                'is_active' => 'boolean',
            ];

            public function manager(): BelongsTo
            {
                return $this->belongsTo(User::class, 'manager_id');
            }

            public function members(): BelongsToMany
            {
                return $this->belongsToMany(User::class, 'territory_user')->withPivot('role')->withTimestamps();
            }
        }
    }

    if (! class_exists(Branch::class)) {
        class Branch extends Model
        {
            protected $table = 'branches';

            protected $guarded = [];
        }
    }

    if (! class_exists(AdvertisingAccount::class)) {
        class AdvertisingAccount extends Model
        {
            protected $table = 'advertising_accounts';

            protected $guarded = [];

            protected $casts = [
                'metadata' => 'array',
            ];
        }
    }

    if (! class_exists(CallLog::class)) {
        class CallLog extends Model
        {
            protected $table = 'call_logs';

            protected $guarded = [];
        }
    }

    if (! class_exists(Email::class)) {
        class Email extends Model
        {
            use HasFactory;

            protected $table = 'emails';

            protected $guarded = [];

            protected $casts = [
                'is_sent' => 'boolean',
                'sent_at' => 'datetime',
                'received_at' => 'datetime',
            ];
        }
    }

    if (! class_exists(EmailTracking::class)) {
        class EmailTracking extends Model
        {
            use HasFactory;

            protected $table = 'email_trackings';

            protected $guarded = [];
        }
    }

    if (! class_exists(LiveChat::class)) {
        class LiveChat extends Model
        {
            use HasFactory;

            protected $table = 'live_chats';

            protected $guarded = [];
        }
    }

    if (! class_exists(Message::class)) {
        class Message extends Model
        {
            use HasFactory;

            protected $table = 'messages';

            protected $guarded = [];
        }
    }

    if (! class_exists(SocialMediaPost::class)) {
        class SocialMediaPost extends Model
        {
            protected $table = 'social_media_posts';

            protected $guarded = [];

            protected $casts = [
                'platforms' => 'array',
                'target_accounts' => 'array',
                'platform_post_ids' => 'array',
                'scheduled_at' => 'datetime',
                'published_at' => 'datetime',
            ];

            public const STATUS_PUBLISHED = 'published';

            public const STATUS_FAILED = 'failed';

            public const STATUS_SCHEDULED = 'scheduled';

            public function team(): BelongsTo
            {
                return $this->belongsTo(Team::class);
            }
        }
    }

    if (! class_exists(Task::class)) {
        class Task extends Model
        {
            protected $table = 'tasks';

            protected $guarded = [];

            protected $casts = [
                'due_date' => 'datetime',
            ];
        }
    }

    foreach (['Chatbot', 'CustomField', 'EmailTemplate', 'KnowledgeBaseArticle', 'Menu', 'Pipeline', 'ReportBuilder', 'Stage', 'Tag', 'Workflow', 'WorkflowAction', 'WorkflowCondition', 'WorkflowTrigger'] as $cloneModel) {
        $fqcn = __NAMESPACE__.'\\'.$cloneModel;
        if (! class_exists($fqcn)) {
            eval("namespace App\\Models; class {$cloneModel} extends \\Illuminate\\Database\\Eloquent\\Model { protected \$guarded = []; }");
        }
    }
}

namespace Webklex\PHPIMAP {
    if (! class_exists(Attribute::class)) {
        class Attribute
        {
            /** @param array<int, mixed> $values */
            public function __construct(
                public string $name = '',
                public array $values = [],
            ) {}

            public function first(): mixed
            {
                return $this->values[0] ?? null;
            }

            /** @return array<int, mixed> */
            public function toArray(): array
            {
                return $this->values;
            }

            public function toString(): string
            {
                return (string) ($this->first() ?? '');
            }

            public function __toString(): string
            {
                return $this->toString();
            }
        }
    }

    if (! class_exists(Attachment::class)) {
        class Attachment
        {
            public function getName(): string
            {
                return '';
            }

            public function getSize(): int
            {
                return 0;
            }
        }
    }

    if (! class_exists(Message::class)) {
        class Message {}
    }
}

namespace Webklex\PHPIMAP\Support {
    use Illuminate\Support\Collection;

    if (! class_exists(AttachmentCollection::class)) {
        class AttachmentCollection extends Collection {}
    }
}

namespace MailchimpMarketing {
    if (! class_exists(ApiClient::class)) {
        class ApiClient
        {
            public mixed $lists = null;

            public mixed $campaigns = null;

            public mixed $reports = null;

            /** @param array<string, mixed> $config */
            public function setConfig(array $config = []): static
            {
                return $this;
            }
        }
    }
}

namespace Stripe\Exception {
    if (! class_exists(ApiErrorException::class)) {
        class ApiErrorException extends \Exception {}
    }

    if (! class_exists(InvalidRequestException::class)) {
        class InvalidRequestException extends ApiErrorException
        {
            public static function factory(string $message = '', ?int $httpStatus = null): static
            {
                return new static($message, $httpStatus ?? 0);
            }
        }
    }
}

namespace Stripe {
    if (! class_exists(StripeClient::class)) {
        class StripeClient
        {
            public mixed $paymentMethods = null;

            public mixed $customers = null;

            public mixed $subscriptions = null;

            public mixed $products = null;

            public mixed $prices = null;

            /** @param array<string, mixed>|string $config */
            public function __construct(array|string $config = []) {}
        }
    }
}
