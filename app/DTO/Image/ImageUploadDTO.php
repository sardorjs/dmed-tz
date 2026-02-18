<?php

declare(strict_types=1);

namespace App\DTO\Image;

use Illuminate\Http\UploadedFile;

final readonly class ImageUploadDTO
{
    public function __construct(
        private int $userId,
        private UploadedFile $file,
    ) {}

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getFile(): UploadedFile
    {
        return $this->file;
    }
}
