<?php

declare(strict_types=1);

namespace App\UseCases\Image;

use App\Models\Image;

final class ShowImageUseCase
{
    public function execute(int $userId, int $imageId): Image
    {
        /** @var Image $image */
        $image = Image::query()
            ->where('id', $imageId)
            ->where('user_id', $userId)
            ->firstOrFail();

        return $image;
    }
}
