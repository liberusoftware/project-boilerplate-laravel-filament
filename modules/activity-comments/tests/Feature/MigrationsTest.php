<?php

use Illuminate\Support\Facades\Schema;

it('registers migrations that create and remove the activity and comment tables', function () {
    $this->artisan('migrate', ['--database' => 'testing'])->assertSuccessful();

    foreach (['timeline_activities', 'internal_comments', 'activity_subscriptions', 'comment_mentions', 'comment_attachments'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }

    expect(Schema::hasColumns('internal_comments', ['subject_type', 'subject_id', 'author_ref', 'body', 'visibility', 'deleted_at']))->toBeTrue();

    $this->artisan('migrate:rollback', ['--database' => 'testing'])->assertSuccessful();

    foreach (['timeline_activities', 'internal_comments', 'activity_subscriptions', 'comment_mentions', 'comment_attachments'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }
});
