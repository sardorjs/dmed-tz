<?php

declare(strict_types=1);

namespace App\UseCases\Image;

use App\Enums\ImageStatus;
use App\Models\Image;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
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

        // --- Deduplication ---
        // Check if a successfully processed image with the same content hash
        // already exists (any user). If so, reuse its S3 path instead of
        // uploading the same bytes again. This avoids redundant storage costs
        // and speeds up processing for commonly shared images.
        $duplicate = Image::query()
            ->where('hash', $image->hash)
            ->where('status', ImageStatus::DONE)
            ->where('id', '!=', $image->id)
            ->first();

        if ($duplicate !== null) {
            // Clean up local temp — no S3 upload needed.
            Storage::disk('local')->delete($image->path);

            $image->update([
                'path' => $duplicate->path,
                'disk' => $duplicate->disk,
                'mime_type' => $duplicate->mime_type,
                'status' => ImageStatus::DONE,
            ]);

            return;
        }

        // --- Compression ---
        // Convert to WebP at 80% quality using GD (available in all PHP builds).
        // WebP vs original: ~30-50% smaller file at visually identical quality.
        // This reduces S3 storage costs and speeds up CDN delivery to clients.
        $localAbsPath = Storage::disk('local')->path($image->path);

        // Write compressed file to system temp dir, not to local storage,
        // so we can pass it to Storage::putFile as a real filesystem File.
        $webpTempPath = sys_get_temp_dir().'/'.uniqid('img_', true).'.webp';

        $manager = new ImageManager(new Driver);

        // quality: 80 — sweet spot for web images: imperceptible loss vs JPEG/PNG,
        // significant reduction in file size (avg 35% smaller than JPEG at q85).
        $manager->read($localAbsPath)->toWebp(quality: 80)->save($webpTempPath);

        // --- Upload to S3 ---
        $targetDisk = (string) config('filesystems.default', 's3');
        $s3Path = Storage::disk($targetDisk)->putFile('images', new File($webpTempPath));

        // Remove intermediate WebP temp file regardless of outcome.
        @unlink($webpTempPath);

        if (! is_string($s3Path)) {
            throw new RuntimeException('Failed to upload image to storage.');
        }

        // Remove original temp file from local disk after successful S3 upload.
        Storage::disk('local')->delete($image->path);

        $image->update([
            'path' => $s3Path,
            'disk' => $targetDisk,
            // Update mime_type to reflect the actual stored format.
            'mime_type' => 'image/webp',
            'status' => ImageStatus::DONE,
        ]);
    }
}
