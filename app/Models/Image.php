<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $original_name
 * @property string $path
 * @property string $disk
 * @property int $size
 * @property string $mime_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static Builder<static>|Image newModelQuery()
 * @method static Builder<static>|Image newQuery()
 * @method static Builder<static>|Image query()
 * @method static Builder<static>|Image whereCreatedAt($value)
 * @method static Builder<static>|Image whereDisk($value)
 * @method static Builder<static>|Image whereId($value)
 * @method static Builder<static>|Image whereMimeType($value)
 * @method static Builder<static>|Image whereOriginalName($value)
 * @method static Builder<static>|Image wherePath($value)
 * @method static Builder<static>|Image whereSize($value)
 * @method static Builder<static>|Image whereUpdatedAt($value)
 * @method static Builder<static>|Image whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Image extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'original_name',
        'path',
        'disk',
        'size',
        'mime_type',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
