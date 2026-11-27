<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Foundation\ImportExport\Data\TransferSchema;
use Liberu\Foundation\ImportExport\ImportExportServiceProvider;
use Liberu\Foundation\ImportExport\Support\RowValidator;

it('boots import-export service provider and validates transfer schema construction', function (): void {
    $provider = $this->app->register(ImportExportServiceProvider::class, force: true);
    $provider->boot();

    $schema = new TransferSchema('invoices', '1.0', [
        'code' => ['required' => true, 'type' => 'string'],
    ]);

    expect($provider)->toBeInstanceOf(ImportExportServiceProvider::class)
        ->and(Schema::hasTable('data_transfers'))->toBeTrue()
        ->and(interface_exists(TransferAuthorizer::class))->toBeTrue()
        ->and($schema->name)->toBe('invoices')
        ->and($schema->version)->toBe('1.0')
        ->and($schema->fields)->toHaveKey('code');

    expect(fn () => new TransferSchema('empty', '1.0', []))
        ->toThrow(InvalidArgumentException::class, 'Transfer schema requires fields.');
});

it('validates all row field types, required rules, optional nulls, and unknown types', function (): void {
    $validator = new RowValidator();
    $schema = new TransferSchema('ledger', '2.0', [
        'name' => ['required' => true, 'type' => 'string'],
        'count' => ['required' => true, 'type' => 'integer'],
        'amount' => ['required' => false, 'type' => 'number'],
        'active' => ['type' => 'boolean'],
        'posted_at' => ['type' => 'date'],
        'unknown' => ['type' => 'custom'],
    ]);

    // Valid row covering every supported type
    expect($validator->validate($schema, [
        'name' => 'Opening balance',
        'count' => '42',
        'amount' => '125.50',
        'active' => true,
        'posted_at' => '2026-08-01',
    ]))->toBe([]);

    // Valid boolean variants (false, 0, 1, '0', '1') and integer/number native values
    foreach ([false, 0, 1, '0', '1'] as $boolValue) {
        expect($validator->validate($schema, [
            'name' => 'Entry',
            'count' => 7,
            'amount' => 99.95,
            'active' => $boolValue,
            'posted_at' => '2026-08-02 10:00:00',
        ]))->toBe([]);
    }

    // Required missing (null) and empty string values
    $missingErrors = $validator->validate($schema, [
        'name' => '',
    ]);
    expect($missingErrors)->toBe([
        'name' => ['required'],
        'count' => ['required'],
    ]);

    // Invalid values for every type including unknown default match arm
    $typeErrors = $validator->validate($schema, [
        'name' => 123,
        'count' => 'not-an-int',
        'amount' => 'not-a-number',
        'active' => 'yes',
        'posted_at' => 'not-a-date',
        'unknown' => 'anything',
    ]);
    expect($typeErrors)->toBe([
        'name' => ['type'],
        'count' => ['type'],
        'amount' => ['type'],
        'active' => ['type'],
        'posted_at' => ['type'],
        'unknown' => ['type'],
    ]);

    // Non-string date value fails date check
    expect($validator->validate($schema, [
        'name' => 'Entry',
        'count' => 1,
        'posted_at' => 20260801,
    ]))->toBe([
        'posted_at' => ['type'],
    ]);
});
