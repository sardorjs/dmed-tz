<?php

declare(strict_types=1);

namespace Tests\Feature\Image;

use App\Enums\ImageStatus;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DeleteImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_user_can_delete_their_own_image(): void
    {
        $user  = User::factory()->create();
        $image = Image::factory()->create([
            'user_id' => $user->id,
            'status'  => ImageStatus::DONE,
            'disk'    => 'local',
            'path'    => 'images/test.webp',
        ]);

        Storage::disk('local')->put('images/test.webp', 'fake-content');

        $this->actingAs($user)
            ->deleteJson("/api/images/{$image->id}")
            ->assertStatus(202)
            ->assertJsonPath('message', 'Image is being deleted.');

        // Job runs synchronously — DB record must be gone by now.
        $this->assertDatabaseMissing('images', ['id' => $image->id]);

        // S3 file must be deleted when this was the only reference.
        Storage::disk('local')->assertMissing('images/test.webp');
    }

    public function test_user_cannot_delete_another_users_image(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $image = Image::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->deleteJson("/api/images/{$image->id}")
            ->assertStatus(404);

        // Image must still exist in DB.
        $this->assertDatabaseHas('images', ['id' => $image->id]);
    }

    public function test_delete_returns_404_for_nonexistent_image(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->deleteJson('/api/images/99999')
            ->assertStatus(404);
    }

    public function test_delete_requires_authentication(): void
    {
        $image = Image::factory()->create();

        $this->deleteJson("/api/images/{$image->id}")->assertStatus(401);
    }

    public function test_s3_file_is_kept_when_other_users_reference_it(): void
    {
        // Deduplication scenario: two users share the same S3 path.
        $shared_path = 'images/shared.webp';
        Storage::disk('local')->put($shared_path, 'fake-content');

        $user1  = User::factory()->create();
        $user2  = User::factory()->create();

        $image1 = Image::factory()->create([
            'user_id' => $user1->id,
            'disk'    => 'local',
            'path'    => $shared_path,
            'status'  => ImageStatus::DONE,
        ]);

        Image::factory()->create([
            'user_id' => $user2->id,
            'disk'    => 'local',
            'path'    => $shared_path,
            'status'  => ImageStatus::DONE,
        ]);

        // User 1 deletes their record.
        $this->actingAs($user1)
            ->deleteJson("/api/images/{$image1->id}")
            ->assertStatus(202);

        // User 1's DB record is gone.
        $this->assertDatabaseMissing('images', ['id' => $image1->id]);

        // But the S3 file must still exist — user 2 still references it.
        Storage::disk('local')->assertExists($shared_path);
    }
}
