<?php

declare(strict_types=1);

namespace App\UseCases\Image;

use App\Models\Image;
use Illuminate\Database\Eloquent\Collection;

final class ListImagesUseCase
{
    /** @return Collection<int, Image> */
    public function execute(int $userId): Collection
    {
        return Image::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();
    }
}
