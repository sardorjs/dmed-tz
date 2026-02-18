<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @property Image $resource
 */
final class ImageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->original_name,
            'url' => Storage::disk($this->resource->disk)->url($this->resource->path),
            'size' => $this->resource->size,
            'mime_type' => $this->resource->mime_type,
            'created_at' => $this->resource->created_at,
        ];
    }
}
