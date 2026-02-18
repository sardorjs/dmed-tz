<?php

declare(strict_types=1);

namespace App\UseCases\Auth;

use App\DTO\Auth\AuthResultDTO;
use App\DTO\Auth\UserDTO;
use App\Models\User;

final class RegisterUseCase
{
    public function execute(UserDTO $dto): AuthResultDTO
    {
        $user = User::query()->create([
            'name' => $dto->getName(),
            'email' => $dto->getEmail(),
            'password' => $dto->getPassword(),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return new AuthResultDTO(user: $user, token: $token);
    }
}
