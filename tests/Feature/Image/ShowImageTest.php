<?php

declare(strict_types=1);

namespace Tests\Feature\Image;

use App\Enums\ImageStatus;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShowImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_their_own_image(): void
    {
        $user  = User::factory()->create();
        $image = Image::factory()->create([
            'user_id' => $user->id,
            'status'  => ImageStatus::DONE,
        ]);

        $this->actingAs($user)
            ->getJson("/api/images/{$image->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $image->id)
            ->assertJsonStructure([
                'data' => ['id', 'name', 'status', 'url', 'size', 'mime_type', 'created_at'],
            ]);
    }

    public function test_user_cannot_view_another_users_image(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $image = Image::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->getJson("/api/images/{$image->id}")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Resource not found.');
    }

    public function test_show_returns_404_for_nonexistent_image(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/images/99999')
            ->assertStatus(404);
    }

    public function test_show_requires_authentication(): void
    {
        $image = Image::factory()->create();

        $this->getJson("/api/images/{$image->id}")->assertStatus(401);
    }

    public function test_done_image_has_url(): void
    {
        $user  = User::factory()->create();
        $image = Image::factory()->create([
            'user_id' => $user->id,
            'status'  => ImageStatus::DONE,
            'disk'    => 'local',
            'path'    => 'images/test.webp',
        ]);

        $this->actingAs($user)
            ->getJson("/api/images/{$image->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', ImageStatus::DONE->value);

        // url must not be null for a done image.
        $data = $this->actingAs($user)
            ->getJson("/api/images/{$image->id}")
            ->json('data');

        $this->assertNotNull($data['url']);
    }
}
