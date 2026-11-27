<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\Files\Contracts\MediaAccess;
use Liberu\Foundation\Files\Contracts\MediaTransformer;
use Liberu\Foundation\Files\FilesMediaServiceProvider;
use Liberu\Foundation\Files\Support\RejectingMalwareScanner;
use Liberu\Foundation\Files\Support\UploadPolicy;

it('registers and boots files-media service provider and resolves default scanner', function (): void {
    $provider = $this->app->register(FilesMediaServiceProvider::class, force: true);
    $provider->boot();

    $scanner = app(MalwareScanner::class);

    expect($provider)->toBeInstanceOf(FilesMediaServiceProvider::class)
        ->and($scanner)->toBeInstanceOf(RejectingMalwareScanner::class)
        ->and($scanner->clean('/tmp/example.pdf'))->toBeFalse()
        ->and(Schema::hasTable('media'))->toBeTrue()
        ->and(interface_exists(MediaAccess::class))->toBeTrue()
        ->and(interface_exists(MediaTransformer::class))->toBeTrue();
});

it('enforces file size and mime type rules in upload policy', function (): void {
    $policy = new UploadPolicy();
    $allowed = ['application/pdf', 'image/png'];

    // Valid boundary sizes and allowed MIME types pass without throwing
    $policy->assert('application/pdf', 1, $allowed, 1024);
    $policy->assert('image/png', 1024, $allowed, 1024);

    // Invalid sizes
    expect(fn () => $policy->assert('application/pdf', 0, $allowed, 1024))
        ->toThrow(InvalidArgumentException::class, 'File size is not permitted.')
        ->and(fn () => $policy->assert('application/pdf', -5, $allowed, 1024))
        ->toThrow(InvalidArgumentException::class, 'File size is not permitted.')
        ->and(fn () => $policy->assert('application/pdf', 1025, $allowed, 1024))
        ->toThrow(InvalidArgumentException::class, 'File size is not permitted.');

    // Disallowed MIME type
    expect(fn () => $policy->assert('application/x-executable', 512, $allowed, 1024))
        ->toThrow(InvalidArgumentException::class, 'File type is not permitted.');
});
