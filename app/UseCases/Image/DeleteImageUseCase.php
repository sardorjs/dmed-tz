<?php

declare(strict_types=1);

namespace App\UseCases\Image;

use App\Models\Image;
use Illuminate\Support\Facades\Storage;

final class DeleteImageUseCase
{
    public function execute(int $userId, int $imageId): void
    {
        $image = Image::query()
            ->where('id', $imageId)
            ->where('user_id', $userId)
            ->first();

        if ($image === null) {
            return;
        }

        Storage::disk($image->disk)->delete($image->path);

        $image->delete();
    }
}
