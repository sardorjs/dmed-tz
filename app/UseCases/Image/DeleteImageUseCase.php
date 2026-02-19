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

        // Deduplication reference count: multiple image records can point to
        // the same S3 path when identical files were uploaded by different users.
        // Only delete the actual S3 object when this is the last DB record
        // referencing it — otherwise other users would lose their files.
        $hasOtherReferences = Image::query()
            ->where('path', $image->path)
            ->where('id', '!=', $image->id)
            ->exists();

        if (! $hasOtherReferences) {
            Storage::disk($image->disk)->delete($image->path);
        }

        $image->delete();
    }
}
