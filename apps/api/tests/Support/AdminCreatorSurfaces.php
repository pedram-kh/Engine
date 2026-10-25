<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * AH-104c C2-4: the manifest of every file in the ADMIN modules that reads a creator, and what it
 * does about an account that is leaving (a deletion request stands, or it is erased).
 *
 * The admin twin of {@see AgencyCreatorSurfaces}. Admin payloads carry `is_erased` and
 * `deletion_pending` (never sent to an agency); the admin SPA renders them through ONE reader
 * (`apps/admin/src/core/creators/creatorLabel.ts`). `AdminCreatorSurfacesTest` fails on any file
 * the heuristic finds that is not listed here, so the next admin file that reads creators has to
 * be classified in the same commit. The heuristic is a grep and says so; the behavioural matrix
 * (`AdminDeletionFlagsTest`) is the strong half.
 *
 * Kinds (and the marker the spec requires in the file):
 *   flagged   — a payload: carries both `is_erased` and `deletion_pending` (or reads them through
 *               `AdminDeletionFlags`)
 *   reader    — the one class that spells the flags
 *   unchanged — deliberately NOT changed; a reason is required
 *   open      — a known gap, NOT guarded today; the reason must name the tech-debt entry
 */
final class AdminCreatorSurfaces
{
    /** Directories (relative to app/Modules) scanned for files that read creators. */
    public const array DIRECTORIES = ['Admin', 'Creators/Http/Controllers/Admin'];

    /** Files outside those directories that emit the admin view of a creator, and are listed too. */
    public const array EXTRA = ['Creators/Http/Resources/CreatorResource.php'];

    /** The shapes that mean "this file reads a creator" (a heuristic, and named as one). */
    public const string READS_CREATORS = "/Creators\\\\Models\\\\Creator;|creator:id|display_name|'creator'\\)|->creator\\b/";

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function all(): array
    {
        return [
            'Admin/Http/Controllers/AdminCreatorAppController.php' => ['flagged', 'The creator-app usage list: the creator block carries both flags.'],
            'Admin/Http/Controllers/AdminDashboardController.php' => ['unchanged', 'Counts of creators awaiting approval or KYC; emits no creator field.'],
            'Admin/Http/Controllers/AdminPushDeliveryController.php' => ['flagged', 'The push-delivery log: the creator block carries both flags.'],
            'Admin/Jobs/RunAutomationRuleJob.php' => ['open', 'Runs a "creator approved" rule (connect to an agency) with no check that the account is not leaving: tech-debt AH-104c C2-4a.'],
            'Admin/Support/AdminDeletionFlags.php' => ['reader', 'The one place the two flags are spelled.'],
            'Creators/Http/Controllers/Admin/AdminCreatorConnectionController.php' => ['open', 'An admin connecting a creator to an agency has no check that the account is not leaving: tech-debt AH-104c C2-4a.'],
            'Creators/Http/Controllers/Admin/AdminCreatorController.php' => ['flagged', 'The review queue: each row carries both flags.'],
            'Creators/Http/Controllers/Admin/AdminCreatorHistoryController.php' => ['unchanged', "The creator's audit history: emits the actors' emails, never the creator's own name or email."],
            'Creators/Http/Resources/CreatorResource.php' => ['flagged', 'The admin detail: admin_attributes carries both flags (the creator-facing view carries neither).'],
        ];
    }

    /**
     * @return list<string>|null
     */
    public static function markers(string $kind): ?array
    {
        return match ($kind) {
            'flagged' => ['AdminDeletionFlags', 'deletion_pending'],
            'reader' => ['function forCreator', 'function forUser'],
            default => null,
        };
    }
}
