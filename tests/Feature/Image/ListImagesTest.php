<?php

declare(strict_types=1);

namespace Tests\Feature\Image;

use App\Enums\ImageStatus;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ListImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_only_their_own_images(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Image::factory()->count(3)->create(['user_id' => $user->id]);
        Image::factory()->count(2)->create(['user_id' => $other->id]);

        $response = $this->actingAs($user)->getJson('/api/images');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');

        // Every returned image must belong to the authenticated user.
        collect($response->json('data'))->each(
            fn (array $item) => $this->assertDatabaseHas('images', [
                'id' => $item['id'],
                'user_id' => $user->id,
            ])
        );
    }

    public function test_list_returns_empty_when_user_has_no_images(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/images')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_list_returns_correct_image_structure(): void
    {
        $user = User::factory()->create();
        Image::factory()->create(['user_id' => $user->id, 'status' => ImageStatus::DONE]);

        $this->actingAs($user)
            ->getJson('/api/images')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    ['id', 'name', 'status', 'url', 'size', 'mime_type', 'created_at'],
                ],
            ]);
    }

    public function test_list_requires_authentication(): void
    {
        $this->getJson('/api/images')->assertStatus(401);
    }

    public function test_pending_image_has_null_url(): void
    {
        $user = User::factory()->create();
        Image::factory()->create(['user_id' => $user->id, 'status' => ImageStatus::PENDING]);

        $response = $this->actingAs($user)->getJson('/api/images');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.status', ImageStatus::PENDING->value)
            ->assertJsonPath('data.0.url', null);
    }
}
