<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Services\MessageAttachmentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 11 (S4) — thread-keyed presigned attachment uploads (D-6): init →
 * complete (thread-prefix + existence check) → attachment-only send. Mirrors
 * the PortfolioUploadService mechanics, but keyed on the thread.
 *
 * @return array{agency: Agency, campaign: Campaign, assignment: CampaignAssignment, admin: User}
 */
function attachmentSetup(): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->createOne(['user_id' => $creatorUser->id]);

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Contracted)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $admin->id,
    ]);

    return compact('agency', 'campaign', 'assignment', 'admin');
}

function attachInitUrl(Agency $agency, Campaign $campaign, CampaignAssignment $assignment): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/messages/attachments/init";
}

function attachSendUrl(Agency $agency, Campaign $campaign, CampaignAssignment $assignment): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/messages";
}

it('initiates a presigned upload scoped under the thread prefix', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = attachmentSetup();

    $response = $this->actingAs($admin)
        ->postJson(attachInitUrl($agency, $campaign, $assignment), ['mime_type' => 'application/pdf', 'size_bytes' => 1024])
        ->assertOk();

    $storagePath = $response->json('data.storage_path');
    expect($storagePath)->toMatch('#^messages/[0-9A-Z]{26}/[0-9A-Z]{26}\.pdf$#')
        ->and($response->json('data.max_bytes'))->toBe(MessageAttachmentUploadService::MAX_BYTES);
});

it('rejects an unsupported mime type on init (422)', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = attachmentSetup();

    $this->actingAs($admin)
        ->postJson(attachInitUrl($agency, $campaign, $assignment), ['mime_type' => 'application/x-msdownload', 'size_bytes' => 1024])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'message.attachment_invalid');
});

it('rejects a file over 25MB on init (422)', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = attachmentSetup();

    $this->actingAs($admin)
        ->postJson(attachInitUrl($agency, $campaign, $assignment), ['mime_type' => 'image/png', 'size_bytes' => 26 * 1024 * 1024])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'message.attachment_invalid');
});

it('sends an attachment-only message after the upload lands (kind=attachment_only)', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = attachmentSetup();

    $storagePath = $this->actingAs($admin)
        ->postJson(attachInitUrl($agency, $campaign, $assignment), ['mime_type' => 'application/pdf', 'size_bytes' => 1024])
        ->json('data.storage_path');

    // Simulate the client's direct PUT to S3.
    Storage::disk('media')->put($storagePath, 'pdf-bytes');

    $this->actingAs($admin)
        ->postJson(attachSendUrl($agency, $campaign, $assignment), [
            'attachments' => [[
                'upload_id' => $storagePath,
                'mime_type' => 'application/pdf',
                'name' => 'brief.pdf',
                'size_bytes' => 1024,
            ]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.kind', 'attachment_only')
        ->assertJsonPath('data.attributes.attachments.0.name', 'brief.pdf');

    $message = Message::query()->where('kind', MessageKind::AttachmentOnly->value)->firstOrFail();
    expect($message->attachments[0]['s3_path'])->toBe($storagePath)
        ->and($message->body)->toBeNull();
});

it("rejects a send whose attachment belongs to another thread's prefix (422)", function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = attachmentSetup();

    // A path under a DIFFERENT thread ulid — the prefix check must reject it
    // even if the object exists.
    $foreignPath = 'messages/01OTHERTHREADULID0000000A/01FILE0000000000000000B.pdf';
    Storage::disk('media')->put($foreignPath, 'pdf-bytes');

    $this->actingAs($admin)
        ->postJson(attachSendUrl($agency, $campaign, $assignment), [
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

it('rejects a send whose attachment object never landed (422)', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = attachmentSetup();

    $storagePath = $this->actingAs($admin)
        ->postJson(attachInitUrl($agency, $campaign, $assignment), ['mime_type' => 'application/pdf', 'size_bytes' => 1024])
        ->json('data.storage_path');

    // Note: we never PUT the object — the existence check must reject the send.
    $this->actingAs($admin)
        ->postJson(attachSendUrl($agency, $campaign, $assignment), [
            'attachments' => [[
                'upload_id' => $storagePath,
                'mime_type' => 'application/pdf',
                'name' => 'ghost.pdf',
                'size_bytes' => 1024,
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'message.attachment_invalid');
});

it('caps attachments at 10 per message (422 validation)', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = attachmentSetup();

    $attachments = array_fill(0, 11, [
        'upload_id' => 'messages/x/y.pdf',
        'mime_type' => 'application/pdf',
        'name' => 'f.pdf',
        'size_bytes' => 10,
    ]);

    $this->actingAs($admin)
        ->postJson(attachSendUrl($agency, $campaign, $assignment), ['attachments' => $attachments])
        ->assertStatus(422);
});
