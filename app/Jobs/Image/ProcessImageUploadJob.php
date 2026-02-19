<?php

declare(strict_types=1);

namespace App\Jobs\Image;

use App\Enums\ImageStatus;
use App\Models\Image;
use App\UseCases\Image\FinalizeImageUploadUseCase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ProcessImageUploadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // 120s: S3 upload of a 5 MB file on a slow connection takes ~30-60s.
    // 2x headroom covers retries without blocking the worker slot indefinitely.
    public int $timeout = 120;

    public function __construct(private readonly int $imageId)
    {
        $this->afterCommit = true;
        // Dedicated queue so Horizon can scale upload workers independently
        // from delete workers and other background jobs.
        $this->queue = 'images-upload';
    }

    /** @return list<int> */
    public function backoff(): array
    {
        // 1 min → 3 min → 6 min: exponential backoff gives S3/network time
        // to recover from transient failures without hammering the service.
        return [60, 180, 360];
    }

    public function handle(FinalizeImageUploadUseCase $useCase): void
    {
        $useCase->execute($this->imageId);
    }

    public function failed(Throwable $exception): void
    {
        $image = Image::query()->find($this->imageId);

        if ($image === null) {
            return;
        }

        // If disk is still 'local', the file was saved to temp storage but
        // never moved to S3. Delete it now to prevent unbounded disk growth.
        // At 1M uploads/day even a 1% failure rate = 10,000 leaked files/day.
        if ($image->disk === 'local') {
            Storage::disk('local')->delete($image->path);
        }

        $image->update(['status' => ImageStatus::FAILED]);
    }
}
