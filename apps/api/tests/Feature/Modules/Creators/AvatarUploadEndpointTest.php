<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeAvatarCreator(): User
{
    $user = User::factory()->createOne();
    CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);

    return $user;
}

beforeEach(function (): void {
    Storage::fake('media');
});

it('stores a valid avatar and returns the refreshed creator', function (): void {
    $user = makeAvatarCreator();
    $file = UploadedFile::fake()->image('avatar.jpg', 256, 256);

    $this->actingAs($user)
        ->post('/api/v1/creators/me/avatar', ['file' => $file])
        ->assertOk()
        ->assertJsonPath('data.type', 'creators');

    expect($user->creator->refresh()->avatar_path)->not->toBeNull();
});

it('returns a precise 413 avatar.too_large when PHP dropped the file via upload_max_filesize', function (): void {
    $user = makeAvatarCreator();

    // Simulate the runtime-limit drop: the file arrives with the
    // UPLOAD_ERR_INI_SIZE error code and no usable temp file.
    $tmp = tempnam(sys_get_temp_dir(), 'avatar_');
    file_put_contents($tmp, 'x');
    $dropped = new UploadedFile($tmp, 'big.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

    $this->actingAs($user)
        ->post('/api/v1/creators/me/avatar', ['file' => $dropped])
        ->assertStatus(413)
        ->assertJsonPath('errors.0.code', 'avatar.too_large');
});

it('still rejects an ordinary oversized file via validation (422)', function (): void {
    config(['uploads.avatar_max_bytes' => 1024 * 1024]); // 1 MB cap
    $user = makeAvatarCreator();
    $file = UploadedFile::fake()->image('huge.jpg', 4000, 4000)->size(2 * 1024); // 2 MB

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/avatar', ['file' => $file])
        ->assertStatus(422);
});
