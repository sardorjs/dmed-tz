<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImageStatus;
use App\Models\Image;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Image>
 */
final class ImageFactory extends Factory
{
    protected $model = Image::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id'       => User::factory(),
            'original_name' => $this->faker->word().'.png',
            'path'          => 'images/'.$this->faker->uuid().'.webp',
            'disk'          => 'local',
            'size'          => $this->faker->numberBetween(10000, 500000),
            'mime_type'     => 'image/webp',
            'status'        => ImageStatus::DONE,
            'hash'          => $this->faker->sha256(),
        ];
    }
}
