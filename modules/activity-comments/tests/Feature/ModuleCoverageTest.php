<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\ActivityComments\ActivityCommentsServiceProvider;
use Liberu\Foundation\ActivityComments\Contracts\ActivityAuthorizer;
use Liberu\Foundation\ActivityComments\Support\Visibility;

it('boots activity comments service provider and registers expected schema', function (): void {
    $provider = $this->app->register(ActivityCommentsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(ActivityCommentsServiceProvider::class)
        ->and(Schema::hasTable('timeline_activities'))->toBeTrue()
        ->and(Schema::hasTable('internal_comments'))->toBeTrue()
        ->and(Schema::hasTable('activity_subscriptions'))->toBeTrue()
        ->and(Schema::hasTable('comment_mentions'))->toBeTrue()
        ->and(Schema::hasTable('comment_attachments'))->toBeTrue();
});

it('evaluates all visibility levels and authorizer contract', function (): void {
    expect(Visibility::Public->visible(false, false, false))->toBeTrue()
        ->and(Visibility::Members->visible(true, false, false))->toBeTrue()
        ->and(Visibility::Members->visible(false, true, false))->toBeTrue()
        ->and(Visibility::Members->visible(false, false, true))->toBeFalse()
        ->and(Visibility::Internal->visible(true, false, true))->toBeFalse()
        ->and(Visibility::Internal->visible(false, true, false))->toBeTrue()
        ->and(Visibility::Private->visible(false, false, true))->toBeTrue()
        ->and(Visibility::Private->visible(false, true, false))->toBeTrue()
        ->and(Visibility::Private->visible(true, false, false))->toBeFalse();

    $authorizer = new class() implements ActivityAuthorizer
    {
        public function allowed(string|int $actorId, string $subjectType, string|int $subjectId, string $operation): bool
        {
            return $operation === 'read' && (string) $actorId !== '';
        }
    };

    expect($authorizer->allowed(1, 'Invoice', 42, 'read'))->toBeTrue()
        ->and($authorizer->allowed(1, 'Invoice', 42, 'delete'))->toBeFalse();
});
