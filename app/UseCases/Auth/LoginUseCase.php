<?php

declare(strict_types=1);

namespace App\UseCases\Auth;

use App\DTO\Auth\AuthResultDTO;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

final class LoginUseCase
{
    public function execute(string $email, string $password): AuthResultDTO
    {
        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            throw new InvalidCredentialsException;
        }

        /** @var User $user */
        $user = Auth::user();
        $token = $user->createToken('auth_token')->plainTextToken;

        return new AuthResultDTO(user: $user, token: $token);
    }
}
