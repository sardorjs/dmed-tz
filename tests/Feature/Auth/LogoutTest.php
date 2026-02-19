<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertStatus(200)
            ->assertJsonPath('message', 'Successfully logged out.');

        // Token row must be deleted from the database after logout.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/auth/logout')
            ->assertStatus(401);
    }
}
