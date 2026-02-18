<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\DTO\Auth\AuthResultDTO;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property AuthResultDTO $resource
 */
final class AuthResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'user' => new UserResource($this->resource->getUser()),
            'token' => $this->resource->getToken(),
            'token_type' => 'Bearer',
        ];
    }
}
