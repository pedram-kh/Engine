<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Services\RelationshipMessageAttachmentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-010a (D4) — relationship-message attachments: thread-keyed presigned files
 * (prefix isolation), the net-new link payload (http/https-only), and the
 * load-bearing assertion — EXIF/GPS is GENUINELY stripped from a sent image by
 * the synchronous on-complete sanitiser (Q3), before any message row or signed
 * URL exists.
 *
 * @return array{agency: Agency, creator: Creator, admin: User}
 */
function relAttachmentSetup(): array
{
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->approved()->createOne(['user_id' => $creatorUser->id]);

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    return compact('agency', 'creator', 'admin');
}

function relAttachInitUrl(Agency $agency, Creator $creator): string
{
    return "/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}/relationship-messages/attachments/init";
}

function relAttachSendUrl(Agency $agency, Creator $creator): string
{
    return "/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}/relationship-messages";
}

/**
 * A minimal JPEG with an APP1/EXIF segment carrying a unique ASCII marker spliced
 * right after the SOI — the re-encode must drop it.
 */
function jpegWithExifMarker(string $marker): string
{
    $image = imagecreatetruecolor(48, 48);
    assert($image !== false);
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();
    imagedestroy($image);

    // EXIF payload: "Exif\0\0" + the marker. APP1 length covers the 2 length
    // bytes + payload.
    $payload = "Exif\x00\x00".$marker;
    $length = strlen($payload) + 2;
    $app1 = "\xFF\xE1".pack('n', $length).$payload;

    // Splice the APP1 segment in right after the SOI (the first two bytes, FFD8).
    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

// ── Presigned files — thread-keyed isolation ────────────────────────────────

it('initiates a presigned upload scoped under the relationship thread prefix', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relAttachmentSetup();

    $response = $this->actingAs($admin)
        ->postJson(relAttachInitUrl($agency, $creator), ['mime_type' => 'application/pdf', 'size_bytes' => 1024])
        ->assertOk();

    expect($response->json('data.storage_path'))
        ->toMatch('#^relationship-messages/[0-9A-Z]{26}/[0-9A-Z]{26}\.pdf$#')
        ->and($response->json('data.max_bytes'))->toBe(RelationshipMessageAttachmentUploadService::MAX_BYTES);
});

it('rejects an unsupported mime type on init (422)', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relAttachmentSetup();

    $this->actingAs($admin)
        ->postJson(relAttachInitUrl($agency, $creator), ['mime_type' => 'application/x-msdownload', 'size_bytes' => 1024])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'message.attachment_invalid');
});

it('sends an attachment-only file message after the upload lands (kind=attachment_only)', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relAttachmentSetup();

    $storagePath = $this->actingAs($admin)
        ->postJson(relAttachInitUrl($agency, $creator), ['mime_type' => 'application/pdf', 'size_bytes' => 1024])
        ->json('data.storage_path');

    Storage::disk('media')->put($storagePath, 'pdf-bytes');

    $this->actingAs($admin)
        ->postJson(relAttachSendUrl($agency, $creator), [
            'attachments' => [[
                'upload_id' => $storagePath,
                'mime_type' => 'application/pdf',
                'name' => 'brief.pdf',
                'size_bytes' => 1024,
            ]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.kind', 'attachment_only')
        ->assertJsonPath('data.attributes.attachments.0.kind', 'file')
        ->assertJsonPath('data.attributes.attachments.0.name', 'brief.pdf');

    $message = RelationshipMessage::query()->where('kind', MessageKind::AttachmentOnly->value)->firstOrFail();
    expect($message->attachments[0]['s3_path'] ?? null)->toBe($storagePath)
        ->and($message->body)->toBeNull();
});

it("rejects a send whose attachment belongs to another thread's prefix (422)", function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relAttachmentSetup();

    $foreignPath = 'relationship-messages/01OTHERTHREADULID0000000A/01FILE0000000000000000B.pdf';
    Storage::disk('media')->put($foreignPath, 'pdf-bytes');

    $this->actingAs($admin)
        ->postJson(relAttachSendUrl($agency, $creator), [
            'attachments' => [[
                'upload_id' => $foreignPath,
                'mime_type' => 'application/pdf',
                'name' => 'sneaky.pdf',
                'size_bytes' => 1024,
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'message.attachment_invalid');
});

// ── EXIF strip (Q3) — the load-bearing sanitise assertion ───────────────────

it('GENUINELY strips EXIF/GPS from a sent image (synchronous on-complete)', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relAttachmentSetup();

    $marker = 'GPS_SECRET_MARKER_8421';
    $raw = jpegWithExifMarker($marker);
    // Sanity: the marker IS present in the raw upload.
    expect(str_contains($raw, $marker))->toBeTrue();

    $storagePath = $this->actingAs($admin)
        ->postJson(relAttachInitUrl($agency, $creator), ['mime_type' => 'image/jpeg', 'size_bytes' => strlen($raw)])
        ->json('data.storage_path');

    Storage::disk('media')->put($storagePath, $raw);

    $this->actingAs($admin)
        ->postJson(relAttachSendUrl($agency, $creator), [
            'attachments' => [[
                'upload_id' => $storagePath,
                'mime_type' => 'image/jpeg',
                'name' => 'photo.jpg',
                'size_bytes' => strlen($raw),
            ]],
        ])
        ->assertCreated();

    $stored = (string) Storage::disk('media')->get($storagePath);

    // The re-encoded object is a valid image with the EXIF marker GONE.
    expect(str_contains($stored, $marker))->toBeFalse()
        ->and(getimagesizefromstring($stored))->not->toBeFalse();
});

it('rejects an undecodable image as a clean 422, not a 500', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relAttachmentSetup();

    $storagePath = $this->actingAs($admin)
        ->postJson(relAttachInitUrl($agency, $creator), ['mime_type' => 'image/jpeg', 'size_bytes' => 16])
        ->json('data.storage_path');

    // Not a real image — the synchronous decode must fail cleanly.
    Storage::disk('media')->put($storagePath, 'not-an-image');

    $this->actingAs($admin)
        ->postJson(relAttachSendUrl($agency, $creator), [
            'attachments' => [[
                'upload_id' => $storagePath,
                'mime_type' => 'image/jpeg',
                'name' => 'broken.jpg',
                'size_bytes' => 16,
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'message.attachment_invalid');
});

// ── Links (D4) — net-new, http/https only ───────────────────────────────────

it('sends a link attachment (http/https) → stored as kind=link', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relAttachmentSetup();

    $this->actingAs($admin)
        ->postJson(relAttachSendUrl($agency, $creator), [
            'body' => 'check this',
            'links' => [['url' => 'https://example.com/deck', 'name' => 'Deck']],
        ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.attachments.0.kind', 'link')
        ->assertJsonPath('data.attributes.attachments.0.url', 'https://example.com/deck')
        ->assertJsonPath('data.attributes.attachments.0.name', 'Deck');
});

it('rejects a javascript: link (422 validation)', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relAttachmentSetup();

    $this->actingAs($admin)
        ->postJson(relAttachSendUrl($agency, $creator), [
            'links' => [['url' => 'javascript:alert(1)']],
        ])
        ->assertStatus(422);
});

it('caps file attachments at 10 per message (422 validation)', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relAttachmentSetup();

    $attachments = array_fill(0, 11, [
        'upload_id' => 'relationship-messages/x/y.pdf',
        'mime_type' => 'application/pdf',
        'name' => 'f.pdf',
        'size_bytes' => 10,
    ]);

    $this->actingAs($admin)
        ->postJson(relAttachSendUrl($agency, $creator), ['attachments' => $attachments])
        ->assertStatus(422);
});
