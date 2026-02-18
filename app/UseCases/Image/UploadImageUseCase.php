<?php

declare(strict_types=1);

namespace App\UseCases\Image;

use App\DTO\Image\ImageUploadDTO;
use App\Models\Image;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class UploadImageUseCase
{
    public function execute(ImageUploadDTO $dto): Image
    {
        $file = $dto->getFile();
        $disk = (string) config('filesystems.default', 's3');

        $path = Storage::disk($disk)->putFile('images', $file);

        if (! is_string($path)) {
            throw new RuntimeException('Failed to store the image.');
        }

        /** @var Image $image */
        $image = Image::query()->create([
            'user_id' => $dto->getUserId(),
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'disk' => $disk,
            'size' => $file->getSize() ?: 0,
            'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
        ]);

        return $image;
    }
}
