<?php

declare(strict_types=1);

namespace App\DTO\Auth;

use App\Models\User;

final readonly class AuthResultDTO
{
    public function __construct(
        private readonly User $user,
        private readonly string $token,
    ) {}

    public function getUser(): User
    {
        return $this->user;
    }

    public function getToken(): string
    {
        return $this->token;
    }
}
