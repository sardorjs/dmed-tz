<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email'    => 'john@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'john@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email', 'created_at'],
                    'token',
                    'token_type',
                ],
            ])
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'john@example.com']);

        $this->postJson('/api/auth/login', [
            'email'    => 'john@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'The provided credentials are incorrect.');
    }

    public function test_login_fails_with_nonexistent_email(): void
    {
        $this->postJson('/api/auth/login', [
            'email'    => 'nobody@example.com',
            'password' => 'password123',
        ])->assertStatus(401);
    }

    public function test_login_fails_with_missing_fields(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }
}
