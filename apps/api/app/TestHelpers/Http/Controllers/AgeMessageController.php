<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\RelationshipMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/_test/messages/{ulid}/age  { "minutes": 16 }
 *
 * AH-119 B2. Moves ONE message's `created_at` back by `minutes` (either message table, a deleted row
 * included), so a Playwright spec can show the 15-minute edit window closing without waiting or
 * pinning the whole application clock. A message ulid is globally unique, so no agency or thread is
 * needed. Nothing else on the row changes, and `last_message_at` of the thread is left alone.
 *
 * 200 `{data: {id, table, created_at}}`; 404 unknown ulid; 422 `minutes` not an integer 1..10080.
 *
 * Production-safety perimeter: the same layered gate as the rest of `_test/*` (env in
 * {local,testing}, a non-empty `TEST_HELPERS_TOKEN`, the token header).
 */
final class AgeMessageController
{
    public function __invoke(Request $request, string $ulid): JsonResponse
    {
        $minutes = $request->input('minutes');
        if (! is_int($minutes) || $minutes < 1 || $minutes > 10080) {
            return new JsonResponse(['error' => 'minutes must be an integer between 1 and 10080'], 422);
        }

        foreach ([['messages', Message::class], ['relationship_messages', RelationshipMessage::class]] as [$table, $model]) {
            $message = $model::query()->withTrashed()->where('ulid', $ulid)->first();
            if ($message === null) {
                continue;
            }

            $aged = $message->created_at->copy()->subMinutes($minutes);
            // A raw update: only created_at moves (no updated_at touch, no model events).
            DB::table($table)->where('id', $message->id)->update(['created_at' => $aged]);

            return new JsonResponse(['data' => ['id' => $ulid, 'table' => $table, 'created_at' => Carbon::parse($aged)->toIso8601String()]]);
        }

        return new JsonResponse(['error' => 'message not found'], 404);
    }
}
