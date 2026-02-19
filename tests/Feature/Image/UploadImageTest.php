<?php

declare(strict_types=1);

namespace Tests\Feature\Image;

use App\Enums\ImageStatus;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class UploadImageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
    }

    public function test_authenticated_user_can_upload_png(): void
    {
        $file = UploadedFile::fake()->image('photo.png', 100, 100);

        $response = $this->actingAs($this->user)
            ->postJson('/api/images', ['image' => $file]);

        // The controller returns the image snapshot captured before the job runs,
        // so the response always shows 'pending'. The job runs synchronously
        // (QUEUE_CONNECTION=sync) and updates the DB — we verify that below.
        $response->assertStatus(202)
            ->assertJsonPath('data.status', ImageStatus::PENDING->value)
            ->assertJsonPath('data.name', 'photo.png')
            ->assertJsonStructure([
                'data' => ['id', 'name', 'status', 'url', 'size', 'mime_type', 'created_at'],
            ]);

        // After the synchronous job the DB record must be DONE.
        $this->assertDatabaseHas('images', [
            'user_id' => $this->user->id,
            'status'  => ImageStatus::DONE->value,
        ]);
    }

    public function test_authenticated_user_can_upload_jpeg(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $this->actingAs($this->user)
            ->postJson('/api/images', ['image' => $file])
            ->assertStatus(202)
            ->assertJsonPath('data.status', ImageStatus::PENDING->value);

        $this->assertDatabaseHas('images', [
            'user_id' => $this->user->id,
            'status'  => ImageStatus::DONE->value,
        ]);
    }

    public function test_upload_rejects_non_image_files(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $this->actingAs($this->user)
            ->postJson('/api/images', ['image' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_upload_rejects_gif(): void
    {
        $file = UploadedFile::fake()->create('animation.gif', 100, 'image/gif');

        $this->actingAs($this->user)
            ->postJson('/api/images', ['image' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_upload_rejects_files_over_5mb(): void
    {
        // 5121 KB = 5.001 MB — just over the 5 MB limit.
        $file = UploadedFile::fake()->image('large.png')->size(5_121);

        $this->actingAs($this->user)
            ->postJson('/api/images', ['image' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_upload_accepts_file_exactly_at_5mb_limit(): void
    {
        // 5120 KB = exactly 5 MB — must be accepted.
        $file = UploadedFile::fake()->image('exact.png', 100, 100)->size(5_120);

        $this->actingAs($this->user)
            ->postJson('/api/images', ['image' => $file])
            ->assertStatus(202);
    }

    public function test_upload_requires_authentication(): void
    {
        $file = UploadedFile::fake()->image('photo.png');

        $this->postJson('/api/images', ['image' => $file])
            ->assertStatus(401);
    }

    public function test_upload_requires_image_field(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/images', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_image_is_stored_as_webp_after_processing(): void
    {
        $file = UploadedFile::fake()->image('photo.png', 200, 200);

        $this->actingAs($this->user)
            ->postJson('/api/images', ['image' => $file])
            ->assertStatus(202);

        $image = Image::query()->where('user_id', $this->user->id)->firstOrFail();

        // After compression the stored mime_type must reflect WebP format.
        $this->assertSame('image/webp', $image->mime_type);
        $this->assertStringEndsWith('.webp', $image->path);
    }

    public function test_duplicate_image_reuses_s3_path(): void
    {
        $file1 = UploadedFile::fake()->image('photo.png', 100, 100);
        $file2 = UploadedFile::fake()->image('photo.png', 100, 100);

        // Make both files have identical content so hashes match.
        $content = file_get_contents($file1->getRealPath());
        file_put_contents($file2->getRealPath(), $content);

        $this->actingAs($this->user)
            ->postJson('/api/images', ['image' => $file1])
            ->assertStatus(202);

        $anotherUser = User::factory()->create();

        $this->actingAs($anotherUser)
            ->postJson('/api/images', ['image' => $file2])
            ->assertStatus(202);

        $first  = Image::query()->where('user_id', $this->user->id)->firstOrFail();
        $second = Image::query()->where('user_id', $anotherUser->id)->firstOrFail();

        // Both records must point to the same S3 path — only one file stored.
        $this->assertSame($first->path, $second->path);
        $this->assertSame($first->hash, $second->hash);
    }

    public function test_different_images_are_not_deduplicated(): void
    {
        $file1 = UploadedFile::fake()->image('a.png', 100, 100);
        $file2 = UploadedFile::fake()->image('b.png', 200, 200);

        $this->actingAs($this->user)
            ->postJson('/api/images', ['image' => $file1])
            ->assertStatus(202);

        $this->actingAs($this->user)
            ->postJson('/api/images', ['image' => $file2])
            ->assertStatus(202);

        $paths = Image::query()
            ->where('user_id', $this->user->id)
            ->pluck('path');

        // Different images must be stored at different paths.
        $this->assertCount(2, $paths->unique());
    }
}
