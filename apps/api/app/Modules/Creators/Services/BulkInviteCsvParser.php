<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * CSV parser for bulk creator invitations.
 *
 * Q3-confirmed limits (Pedram open item 3 + (b-mod) decision):
 *   - 5 MB hard file-size cap
 *   - 1000-row hard row cap
 *   - 100-row soft warning (UI surfaces a banner; backend allows it)
 *
 * Required columns: `email`. Optional columns are accepted but ignored
 * by Sprint 3 Chunk 1 (Sprint 4+ may extend the parsed shape).
 *
 * Returns a structured result with row metadata so the controller can
 * surface `meta.row_count` and per-row validation errors back to the UI
 * without a separate round-trip.
 */
final class BulkInviteCsvParser
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    public const int MAX_ROWS = 1000;

    public const int SOFT_WARNING_ROWS = 100;

    /**
     * @return array{
     *   rows: list<array{row: int, email: string}>,
     *   errors: list<array{row: int, code: string, detail: string}>,
     *   row_count: int,
     *   exceeds_soft_warning: bool,
     * }
     */
    public function parse(UploadedFile $file): array
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('CSV exceeds 5MB hard cap.');
        }

        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new RuntimeException('Could not open uploaded CSV.');
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '\\');
            if ($header === false) {
                throw new RuntimeException('Empty CSV.');
            }

            // fgetcsv may return null cells when the line has a trailing
            // delimiter; coerce to '' so the email-column lookup can
            // safely string-compare each cell.
            $headerStrings = array_map(
                static fn (?string $cell): string => $cell ?? '',
                $header,
            );
            $emailIndex = $this->resolveEmailColumnIndex($headerStrings);

            $rows = [];
            $errors = [];
            $rowNumber = 1; // header is row 1

            while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $rowNumber++;

                if ($rowNumber - 1 > self::MAX_ROWS) {
                    throw new RuntimeException(
                        'CSV exceeds 1000-row hard cap.',
                    );
                }

                $rawEmail = isset($cells[$emailIndex]) ? trim((string) $cells[$emailIndex]) : '';
                if ($rawEmail === '') {
                    $errors[] = [
                        'row' => $rowNumber,
                        'code' => 'invitation.email_missing',
                        'detail' => 'Email cell is empty.',
                    ];

                    continue;
                }

                $normalised = mb_strtolower($rawEmail);
                if (! filter_var($normalised, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'code' => 'invitation.email_invalid',
                        'detail' => "'{$rawEmail}' is not a valid email address.",
                    ];

                    continue;
                }

                $rows[] = ['row' => $rowNumber, 'email' => $normalised];
            }

            return [
                'rows' => $rows,
                'errors' => $errors,
                'row_count' => count($rows),
                'exceeds_soft_warning' => count($rows) > self::SOFT_WARNING_ROWS,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string>  $header
     */
    private function resolveEmailColumnIndex(array $header): int
    {
        foreach ($header as $i => $name) {
            if (mb_strtolower(trim((string) $name)) === 'email') {
                return $i;
            }
        }

        throw new RuntimeException('CSV must include an `email` column.');
    }
}
