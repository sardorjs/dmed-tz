<?php

declare(strict_types=1);

namespace App\Http\Requests\Image;

use App\DTO\Image\ImageUploadDTO;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

final class UploadImageRequest extends FormRequest
{
    private const MAX_IMAGE_SIZE_KB = 5120; // 5 MB

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:'.self::MAX_IMAGE_SIZE_KB],
        ];
    }

    public function toDto(): ImageUploadDTO
    {
        /** @var User $user */
        $user = $this->user();

        /** @var UploadedFile $file */
        $file = $this->file('image');

        return new ImageUploadDTO(
            userId: $user->id,
            file: $file,
        );
    }
}
