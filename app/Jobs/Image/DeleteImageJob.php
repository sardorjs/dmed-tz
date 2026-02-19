<?php

declare(strict_types=1);

namespace App\Jobs\Image;

use App\UseCases\Image\DeleteImageUseCase;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class DeleteImageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        private readonly int $userId,
        private readonly int $imageId,
    ) {
        $this->afterCommit = true;
        // Dedicated queue so delete jobs don't compete with upload workers
        // during bursts — both can scale independently in Horizon.
        $this->queue = 'images-delete';
    }

    public function uniqueId(): string
    {
        return (string) $this->imageId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 180, 360];
    }

    public function handle(DeleteImageUseCase $useCase): void
    {
        $useCase->execute(userId: $this->userId, imageId: $this->imageId);
    }

    public function failed(Throwable $exception): void
    {
        // Deletion failed after all retries — image remains in DB and S3.
        // Operators can re-trigger delete or clean up manually.
    }
}
