<?php

declare(strict_types=1);

use App\Modules\Creators\Enums\BlockType;
use App\Modules\Creators\Enums\Kind;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Driver-agnostic column-width guard for the availability enum columns.
|--------------------------------------------------------------------------
|
| creator_availability_blocks.kind / .block_type are sized varchar columns
| (kind=32, block_type=8). Postgres enforces those widths; SQLite (the test
| driver) does NOT, so an enum value longer than its column slips through
| every feature test and only blows up against Postgres in production. This
| is exactly how `exclusive_contract` (18 chars) overflowed the original
| varchar(16) kind column unnoticed (fixed 2026-06-03 by widening to 32).
|
| These constants MUST track the migration's declared widths. If a future
| enum value is added that exceeds them, this test fails on EVERY driver —
| forcing the column to be widened in lockstep rather than discovering the
| overflow in production.
*/

const KIND_COLUMN_WIDTH = 32;
const BLOCK_TYPE_COLUMN_WIDTH = 8;

it('keeps every Kind enum value within the kind column width', function (): void {
    foreach (Kind::cases() as $case) {
        expect(mb_strlen($case->value))
            ->toBeLessThanOrEqual(
                KIND_COLUMN_WIDTH,
                "Kind::{$case->name} ('{$case->value}') exceeds the varchar(".KIND_COLUMN_WIDTH.') kind column.',
            );
    }
});

it('keeps every BlockType enum value within the block_type column width', function (): void {
    foreach (BlockType::cases() as $case) {
        expect(mb_strlen($case->value))
            ->toBeLessThanOrEqual(
                BLOCK_TYPE_COLUMN_WIDTH,
                "BlockType::{$case->name} ('{$case->value}') exceeds the varchar(".BLOCK_TYPE_COLUMN_WIDTH.') block_type column.',
            );
    }
});
