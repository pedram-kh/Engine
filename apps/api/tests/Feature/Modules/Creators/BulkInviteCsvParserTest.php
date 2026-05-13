<?php

declare(strict_types=1);

use App\Modules\Creators\Services\BulkInviteCsvParser;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

uses(TestCase::class);

function uploadedCsv(string $contents): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $contents);

    return new UploadedFile($path, 'invitees.csv', 'text/csv', null, true);
}

it('parses a valid CSV with a single email column', function (): void {
    $csv = "email\nfirst@example.com\nSECOND@example.com\n";
    $parser = new BulkInviteCsvParser;

    $result = $parser->parse(uploadedCsv($csv));

    expect($result['row_count'])->toBe(2)
        ->and($result['exceeds_soft_warning'])->toBeFalse()
        ->and($result['rows'][0]['email'])->toBe('first@example.com')
        ->and($result['rows'][1]['email'])->toBe('second@example.com')
        ->and($result['errors'])->toBeEmpty();
});

it('records per-row errors for invalid emails without aborting (Q3)', function (): void {
    $csv = "email\nvalid@example.com\nnot-an-email\n\nanother@example.com\n";
    $parser = new BulkInviteCsvParser;

    $result = $parser->parse(uploadedCsv($csv));

    expect($result['row_count'])->toBe(2)
        ->and($result['errors'])->toHaveCount(2)
        ->and($result['errors'][0]['code'])->toBe('invitation.email_invalid')
        ->and($result['errors'][1]['code'])->toBe('invitation.email_missing');
});

it('rejects a CSV exceeding the 5MB hard cap', function (): void {
    $bigCsv = "email\n".str_repeat("filler@example.com\n", 300_000);
    $parser = new BulkInviteCsvParser;

    expect(fn () => $parser->parse(uploadedCsv($bigCsv)))
        ->toThrow(RuntimeException::class, '5MB');
});

it('rejects a CSV exceeding the 1000-row hard cap (Q3)', function (): void {
    $rows = "email\n";
    for ($i = 0; $i < BulkInviteCsvParser::MAX_ROWS + 5; $i++) {
        $rows .= "user{$i}@example.com\n";
    }
    $parser = new BulkInviteCsvParser;

    expect(fn () => $parser->parse(uploadedCsv($rows)))
        ->toThrow(RuntimeException::class, '1000-row');
});

it('flags the soft-warning threshold at 100 rows (Q3)', function (): void {
    $rows = "email\n";
    for ($i = 0; $i < 150; $i++) {
        $rows .= "user{$i}@example.com\n";
    }
    $parser = new BulkInviteCsvParser;

    $result = $parser->parse(uploadedCsv($rows));

    expect($result['exceeds_soft_warning'])->toBeTrue()
        ->and($result['row_count'])->toBe(150);
});

it('rejects a CSV with no email column', function (): void {
    $csv = "name,country\nFoo,IT\n";
    $parser = new BulkInviteCsvParser;

    expect(fn () => $parser->parse(uploadedCsv($csv)))
        ->toThrow(RuntimeException::class, 'email');
});
