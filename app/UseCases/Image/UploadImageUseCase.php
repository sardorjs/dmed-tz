<?php

declare(strict_types=1);

namespace App\UseCases\Image;

use App\DTO\Image\ImageUploadDTO;
use App\Enums\ImageStatus;
use App\Models\Image;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class UploadImageUseCase
{
    public function execute(ImageUploadDTO $dto): Image
    {
        $file = $dto->getFile();

        $tempPath = Storage::disk('local')->putFile('temp/images', $file);

        if (! is_string($tempPath)) {
            throw new RuntimeException('Failed to save the uploaded file temporarily.');
        }

        /** @var Image $image */
        $image = Image::query()->create([
            'user_id' => $dto->getUserId(),
            'original_name' => $file->getClientOriginalName(),
            'path' => $tempPath,
            'disk' => 'local',
            'size' => $file->getSize() ?: 0,
            'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
            'status' => ImageStatus::PENDING,
        ]);

        return $image;
    }
}
