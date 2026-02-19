<?php

declare(strict_types=1);

namespace App\UseCases\Image;

use App\Enums\ImageStatus;
use App\Models\Image;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class FinalizeImageUploadUseCase
{
    public function execute(int $imageId): void
    {
        /** @var Image $image */
        $image = Image::query()->findOrFail($imageId);

        if ($image->status !== ImageStatus::PENDING) {
            return;
        }

        $targetDisk = (string) config('filesystems.default', 's3');
        $localAbsPath = Storage::disk('local')->path($image->path);

        $s3Path = Storage::disk($targetDisk)->putFile('images', new File($localAbsPath));

        if (! is_string($s3Path)) {
            throw new RuntimeException('Failed to upload image to storage.');
        }

        Storage::disk('local')->delete($image->path);

        $image->update([
            'path' => $s3Path,
            'disk' => $targetDisk,
            'status' => ImageStatus::DONE,
        ]);
    }
}
